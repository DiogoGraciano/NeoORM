<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Naming\ConstraintNamer;
use Diogodg\Neoorm\Schema\Type\TypeCatalog;
use Diogodg\Neoorm\Schema\Value\DefaultKind;

/**
 * Valida um schema inteiro contra um dialeto e devolve TODOS os problemas.
 *
 * Devolver a lista completa, e não a primeira falha, é intencional: quem roda
 * `generate` quer saber tudo que precisa corrigir de uma vez, e não descobrir
 * um erro por execução. Cada mensagem carrega `tabela.coluna`, porque o sistema
 * antigo lançava de dentro do construtor de Column sem dizer qual coluna de
 * qual tabela — ou, pior, engolia o erro num catch vazio.
 */
final class SchemaValidator
{
    /**
     * @return list<string> vazio significa válido
     */
    public function validate(SchemaDefinition $schema, string $dialect): array
    {
        $errors = [];

        foreach ($schema->tables as $table) {
            $errors = [...$errors, ...$this->validateTable($schema, $table, $dialect)];
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateTable(SchemaDefinition $schema, TableDefinition $table, string $dialect): array
    {
        $errors = [];

        foreach ($table->columns as $column) {
            $errors = [...$errors, ...$this->validateColumn($table, $column, $dialect)];
        }

        $errors = [...$errors, ...$this->validatePrimaryKey($table)];
        $errors = [...$errors, ...$this->validateAutoIncrement($table)];
        $errors = [...$errors, ...$this->validateColumnReferences($table)];
        $errors = [...$errors, ...$this->validateForeignKeys($schema, $table)];
        $errors = [...$errors, ...$this->validateIdentifierLengths($table)];
        $errors = [...$errors, ...$this->validateUniqueIndexes($table, $dialect)];

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateColumn(TableDefinition $table, ColumnDefinition $column, string $dialect): array
    {
        $errors = [];
        $where = "{$table->name}.{$column->name}";

        $reason = TypeCatalog::check($column->type, $dialect);

        if ($reason !== null) {
            $errors[] = "{$where}: {$reason}";
        }

        if (TypeCatalog::requiresLength($column->type->name, $dialect) && $column->type->length === null) {
            $errors[] = "{$where}: {$column->type->name->value} exige um tamanho neste dialeto.";
        }

        // Silenciosamente aceito antes, e sem efeito nenhum: a coluna é NOT NULL,
        // então o default NULL nunca poderia ser usado.
        if ($column->notNull && $column->default->kind === DefaultKind::Null) {
            $errors[] = "{$where}: coluna NOT NULL não pode ter DEFAULT NULL.";
        }

        // E numa coluna nullable, `DEFAULT NULL` não é errado — é INEXPRIMÍVEL.
        //
        // Nenhum dos dois bancos guarda a diferença entre `x INT NULL` e
        // `x INT NULL DEFAULT NULL`: o MySQL registra `COLUMN_DEFAULT` nulo nos dois
        // casos, e o PostgreSQL descarta a cláusula ao armazená-la. Ou seja, a
        // introspecção jamais devolve esse estado, e um model que o declarasse
        // divergiria do banco em toda execução, para sempre, sem que nenhuma migração
        // pudesse resolver. Recusar na validação é o que torna o impossível impossível
        // em vez de silenciosamente eterno.
        if (!$column->notNull && $column->default->kind === DefaultKind::Null) {
            $errors[] = "{$where}: DEFAULT NULL numa coluna nullable é redundante e não é "
                . 'representável no catálogo de nenhum dos dois bancos, o que faria a coluna divergir '
                . 'para sempre. Remova o setDefault(null).';
        }

        if ($column->autoIncrement && !$column->type->isIntegral()) {
            $errors[] = "{$where}: auto incremento exige tipo inteiro, e o tipo é {$column->type->signature()}.";
        }

        // Os dois bancos recusam a combinação, cada um do seu jeito: o MySQL com
        // "invalid default value", o PostgreSQL porque uma coluna identity não
        // aceita cláusula DEFAULT. Pegar aqui é o que faz a mensagem citar a
        // coluna em vez de vir do banco no meio de uma migração.
        if ($column->autoIncrement && !$column->default->isNone()) {
            $errors[] = "{$where}: coluna com auto incremento não pode ter DEFAULT.";
        }

        if ($column->collation !== null && $dialect !== 'mysql') {
            $errors[] = "{$where}: collation por coluna só é suportada no MySQL.";
        }

        // Metadado do MySQL (nomes e comentários no information_schema) é armazenado em
        // utf8mb3, que não cabe caractere de 4 bytes: um emoji num comentário volta do
        // catálogo como `?`. Não é bug desta biblioteca — é o servidor — e a única forma
        // de não deixar a coluna divergir para sempre é recusar na entrada.
        if ($dialect === 'mysql' && $column->comment !== null && self::hasNonBmpCharacter($column->comment)) {
            $errors[] = "{$where}: o comentário tem caractere fora do BMP (emoji, por exemplo). O "
                . 'MySQL guarda comentário em utf8mb3 e o devolve como "?", o que faria a coluna '
                . 'divergir do banco em toda comparação.';
        }

        return $errors;
    }

    /**
     * Índice único e restrição de unicidade são o MESMO OBJETO no MySQL.
     *
     * `CREATE UNIQUE INDEX` aparece em `TABLE_CONSTRAINTS` como UNIQUE, e o catálogo não
     * registra por qual comando o objeto nasceu. Manter as duas representações no IR faria
     * uma delas divergir do banco para sempre. Isto pega quem monta o IR na mão.
     *
     * @return list<string>
     */
    private function validateUniqueIndexes(TableDefinition $table, string $dialect): array
    {
        if ($dialect !== 'mysql') {
            return [];
        }

        $errors = [];

        foreach ($table->indexes as $index) {
            if ($index->unique) {
                $errors[] = "{$table->name}.{$index->name}: no MySQL um índice único e uma restrição de "
                    . 'unicidade são o mesmo objeto no catálogo, então declarar um índice único faria a '
                    . 'tabela divergir do banco. Use uma restrição de unicidade.';
            }
        }

        return $errors;
    }

    /**
     * Caractere que exige 4 bytes em UTF-8, ou seja, fora do Basic Multilingual Plane.
     */
    private static function hasNonBmpCharacter(string $text): bool
    {
        return preg_match('/[\x{10000}-\x{10FFFF}]/u', $text) === 1;
    }

    /**
     * @return list<string>
     */
    private function validatePrimaryKey(TableDefinition $table): array
    {
        $errors = [];

        if ($table->primaryKey === null) {
            return $errors;
        }

        foreach ($table->primaryKey->columns as $column) {
            if (!$table->hasColumn($column)) {
                $errors[] = "{$table->name}: chave primária referencia coluna inexistente '{$column}'.";
                continue;
            }

            // Uma coluna de PK é NOT NULL por definição nos dois bancos. Deixar
            // o IR discordar disso faria a coluna parecer alterada em todo
            // round-trip, já que a introspecção sempre devolve NOT NULL.
            if (!$table->columns[$column]->notNull) {
                $errors[] = "{$table->name}.{$column}: coluna de chave primária precisa ser NOT NULL.";
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateAutoIncrement(TableDefinition $table): array
    {
        $errors = [];
        $autoIncrementColumns = [];

        foreach ($table->columns as $column) {
            if ($column->autoIncrement) {
                $autoIncrementColumns[] = $column->name;
            }
        }

        if ($autoIncrementColumns === []) {
            return $errors;
        }

        if (count($autoIncrementColumns) > 1) {
            $errors[] = "{$table->name}: mais de uma coluna com auto incremento ("
                . implode(', ', $autoIncrementColumns) . '). Os dois bancos permitem só uma.';
        }

        $primaryKeyColumns = $table->getPrimaryKeyColumns();

        foreach ($autoIncrementColumns as $column) {
            if (!in_array($column, $primaryKeyColumns, true)) {
                $errors[] = "{$table->name}.{$column}: coluna com auto incremento precisa fazer parte da chave primária.";
            }
        }

        return $errors;
    }

    /**
     * Toda coluna citada por índice, unique ou FK precisa existir.
     *
     * O builder antigo validava isso no momento da chamada, o que obrigava
     * `index()` a vir depois de `columns()`. Validando o schema pronto, a
     * ordem das chamadas deixa de importar.
     *
     * @return list<string>
     */
    private function validateColumnReferences(TableDefinition $table): array
    {
        $errors = [];

        $checkColumns = function (array $columns, string $kind, string $name) use ($table, &$errors): void {
            foreach ($columns as $column) {
                if (!$table->hasColumn($column)) {
                    $errors[] = "{$table->name}: {$kind} '{$name}' referencia coluna inexistente '{$column}'.";
                }
            }
        };

        foreach ($table->indexes as $index) {
            $checkColumns($index->columns, 'índice', $index->name);
        }

        foreach ($table->uniqueConstraints as $unique) {
            $checkColumns($unique->columns, 'restrição de unicidade', $unique->name);
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $checkColumns($foreignKey->columns, 'foreign key', $foreignKey->name);
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateForeignKeys(SchemaDefinition $schema, TableDefinition $table): array
    {
        $errors = [];

        foreach ($table->foreignKeys as $foreignKey) {
            $referenced = $schema->table($foreignKey->referencedTable);

            if ($referenced === null) {
                $errors[] = "{$table->name}: foreign key '{$foreignKey->name}' aponta para a tabela "
                    . "'{$foreignKey->referencedTable}', que não existe no schema.";
                continue;
            }

            foreach ($foreignKey->referencedColumns as $column) {
                if (!$referenced->hasColumn($column)) {
                    $errors[] = "{$table->name}: foreign key '{$foreignKey->name}' aponta para "
                        . "'{$referenced->name}.{$column}', que não existe.";
                }
            }

            // Um SET NULL numa coluna NOT NULL só falha quando a linha pai é
            // removida — em produção, muito depois da migração.
            if ($foreignKey->onDelete->value === 'SET NULL') {
                foreach ($foreignKey->columns as $column) {
                    if ($table->hasColumn($column) && $table->columns[$column]->notNull) {
                        $errors[] = "{$table->name}.{$column}: ON DELETE SET NULL exige coluna que aceite NULL.";
                    }
                }
            }
        }

        return $errors;
    }

    /**
     * @return list<string>
     */
    private function validateIdentifierLengths(TableDefinition $table): array
    {
        $errors = [];
        $max = ConstraintNamer::MAX_LENGTH;

        if (strlen($table->name) > $max) {
            $errors[] = "Nome de tabela com mais de {$max} caracteres: '{$table->name}'.";
        }

        foreach ($table->columns as $column) {
            if (strlen($column->name) > $max) {
                $errors[] = "{$table->name}: nome de coluna com mais de {$max} caracteres: '{$column->name}'.";
            }
        }

        return $errors;
    }
}
