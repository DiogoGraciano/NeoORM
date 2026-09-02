<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Dialect;

use Diogodg\Neoorm\Migrations\Exception\UnsupportedOperationException;
use Diogodg\Neoorm\Migrations\Exception\UnsupportedTypeException;
use Diogodg\Neoorm\Migrations\Operation\AddCheckConstraint;
use Diogodg\Neoorm\Migrations\Operation\AddColumn;
use Diogodg\Neoorm\Migrations\Operation\AddForeignKey;
use Diogodg\Neoorm\Migrations\Operation\AddPrimaryKey;
use Diogodg\Neoorm\Migrations\Operation\AddUniqueConstraint;
use Diogodg\Neoorm\Migrations\Operation\AlterColumn;
use Diogodg\Neoorm\Migrations\Operation\CreateIndex;
use Diogodg\Neoorm\Migrations\Operation\CreateTable;
use Diogodg\Neoorm\Migrations\Operation\DropCheckConstraint;
use Diogodg\Neoorm\Migrations\Operation\DropColumn;
use Diogodg\Neoorm\Migrations\Operation\DropForeignKey;
use Diogodg\Neoorm\Migrations\Operation\DropIndex;
use Diogodg\Neoorm\Migrations\Operation\DropPrimaryKey;
use Diogodg\Neoorm\Migrations\Operation\DropTable;
use Diogodg\Neoorm\Migrations\Operation\DropUniqueConstraint;
use Diogodg\Neoorm\Migrations\Operation\OperationList;
use Diogodg\Neoorm\Migrations\Operation\RawSql;
use Diogodg\Neoorm\Migrations\Operation\RenameColumn;
use Diogodg\Neoorm\Migrations\Operation\RenameTable;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Migrations\Operation\SetTableComment;
use Diogodg\Neoorm\Migrations\Operation\SetTableOptions;
use Diogodg\Neoorm\Migrations\Runner\MigrationStatus;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;
use Diogodg\Neoorm\Schema\ForeignKeyDefinition;
use Diogodg\Neoorm\Schema\Naming\ConstraintNamer;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\Type\TypeCatalog;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\Value\DefaultValue;

/**
 * O que os dois dialetos realmente compartilham.
 *
 * Note o que está aqui e o que não está. Aqui: o despacho de operações, a
 * citação de identificadores e literais, e os pedaços de sintaxe que os dois
 * bancos escrevem igual palavra por palavra. Não aqui: nenhum dos 19 `compile*`.
 *
 * Todos eles são abstratos de propósito, mesmo quando as duas implementações vão
 * ficar parecidas. Uma implementação "compartilhada" de geração de DDL é
 * exatamente o lugar onde o próximo ajuste específico do MySQL quebra o
 * PostgreSQL em silêncio — foi assim que o sistema antigo perdeu comentário e
 * tamanho de coluna no pgsql, porque o SQL vinha montado em MySQL e o driver
 * pgsql descartava o que não reconhecia. Como bônus, deixar tudo abstrato torna
 * impossível esquecer de implementar uma operação num dialeto: o PHP recusa a
 * classe.
 */
abstract class AbstractDialect implements Dialect
{
    // ---------------------------------------------------------------- citação

    /**
     * Valida e depois envolve. Nesta ordem, e sem exceção.
     *
     * `normalize()` recusa qualquer coisa fora de `[a-zA-Z_][a-zA-Z0-9_]*`, então
     * nenhum aspa, ponto e vírgula ou espaço chega ao `wrapIdentifier()`. A
     * duplicação do caractere de citação lá é a segunda camada: se um dia esta
     * validação afrouxar, o escape ainda está de pé.
     */
    final public function quoteIdentifier(string $identifier): string
    {
        $normalized = IdentifierValidator::normalize(
            $identifier,
            "Identificador em SQL de {$this->name()}",
        );

        if (strlen($normalized) > $this->maxIdentifierLength()) {
            throw new InvalidIdentifierException(
                "Identificador '{$normalized}' tem " . strlen($normalized)
                . " caracteres e excede o limite de {$this->maxIdentifierLength()} do "
                . $this->name() . '. Nomes gerados passam por ConstraintNamer, que trunca em '
                . ConstraintNamer::MAX_LENGTH . '.',
            );
        }

        return $this->wrapIdentifier($normalized);
    }

    abstract protected function wrapIdentifier(string $identifier): string;

    final public function quoteLiteral(string|int|float|bool|null $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        if (is_bool($value)) {
            return $this->renderBoolean($value);
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            if (!is_finite($value)) {
                throw new UnsupportedTypeException(
                    'INF e NAN não têm literal em SQL; nenhum dos dois pode ser default de coluna.',
                );
            }

            // var_export preserva o valor exato e nunca produz vírgula decimal,
            // qualquer que seja o locale. Notação científica (1.0E+25) é literal
            // numérico válido nos dois bancos.
            return var_export($value, true);
        }

        return $this->wrapString($value);
    }

    abstract protected function wrapString(string $value): string;

    abstract protected function renderBoolean(bool $value): string;

    /**
     * Valores que entram na DDL sem citação, como nome de engine e de collation.
     *
     * Os dois bancos aceitam esses valores citados como string, mas escrevê-los
     * citados destoa do que os próprios servidores devolvem, e o que protege aqui
     * é a validação — não a citação.
     */
    final protected function safeOptionValue(string $value, string $context): string
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $value) !== 1) {
            throw new InvalidIdentifierException(
                "{$context} inválido: '{$value}'. Use apenas letras, dígitos e underscore.",
            );
        }

        return $value;
    }

    // -------------------------------------------------------------- despacho

    /**
     * O conjunto de operações é fechado, então o `default` é inalcançável por
     * qualquer caminho normal — está aqui para que uma implementação de
     * `SchemaOperation` escrita fora do conjunto falhe dizendo o que houve, em
     * vez de virar lista vazia e sumir da migração sem aviso.
     */
    final public function compile(SchemaOperation $operation): array
    {
        return match ($operation::class) {
            CreateTable::class => $this->compileCreateTable($operation),
            DropTable::class => $this->compileDropTable($operation),
            RenameTable::class => $this->compileRenameTable($operation),
            SetTableComment::class => $this->compileSetTableComment($operation),
            SetTableOptions::class => $this->compileSetTableOptions($operation),
            AddColumn::class => $this->compileAddColumn($operation),
            DropColumn::class => $this->compileDropColumn($operation),
            RenameColumn::class => $this->compileRenameColumn($operation),
            AlterColumn::class => $this->compileAlterColumn($operation),
            AddPrimaryKey::class => $this->compileAddPrimaryKey($operation),
            DropPrimaryKey::class => $this->compileDropPrimaryKey($operation),
            AddUniqueConstraint::class => $this->compileAddUniqueConstraint($operation),
            DropUniqueConstraint::class => $this->compileDropUniqueConstraint($operation),
            CreateIndex::class => $this->compileCreateIndex($operation),
            DropIndex::class => $this->compileDropIndex($operation),
            AddForeignKey::class => $this->compileAddForeignKey($operation),
            DropForeignKey::class => $this->compileDropForeignKey($operation),
            AddCheckConstraint::class => $this->compileAddCheckConstraint($operation),
            DropCheckConstraint::class => $this->compileDropCheckConstraint($operation),
            RawSql::class => [$operation->sql],
            default => throw new UnsupportedOperationException(
                'O dialeto ' . $this->name() . ' não sabe compilar ' . $operation::class
                . '. O conjunto de operações é fechado em Migrations\Operation.',
            ),
        };
    }

    final public function compileAll(OperationList $operations): array
    {
        $statements = [];

        foreach ($operations as $operation) {
            foreach ($this->compile($operation) as $sql) {
                $statements[] = $sql;
            }
        }

        return $statements;
    }

    /** @return list<string> */
    abstract protected function compileCreateTable(CreateTable $operation): array;

    /** @return list<string> */
    abstract protected function compileDropTable(DropTable $operation): array;

    /** @return list<string> */
    abstract protected function compileRenameTable(RenameTable $operation): array;

    /** @return list<string> */
    abstract protected function compileSetTableComment(SetTableComment $operation): array;

    /** @return list<string> */
    abstract protected function compileSetTableOptions(SetTableOptions $operation): array;

    /** @return list<string> */
    abstract protected function compileAddColumn(AddColumn $operation): array;

    /** @return list<string> */
    abstract protected function compileDropColumn(DropColumn $operation): array;

    /** @return list<string> */
    abstract protected function compileRenameColumn(RenameColumn $operation): array;

    /** @return list<string> */
    abstract protected function compileAlterColumn(AlterColumn $operation): array;

    /** @return list<string> */
    abstract protected function compileAddPrimaryKey(AddPrimaryKey $operation): array;

    /** @return list<string> */
    abstract protected function compileDropPrimaryKey(DropPrimaryKey $operation): array;

    /** @return list<string> */
    abstract protected function compileAddUniqueConstraint(AddUniqueConstraint $operation): array;

    /** @return list<string> */
    abstract protected function compileDropUniqueConstraint(DropUniqueConstraint $operation): array;

    /** @return list<string> */
    abstract protected function compileCreateIndex(CreateIndex $operation): array;

    /** @return list<string> */
    abstract protected function compileDropIndex(DropIndex $operation): array;

    /** @return list<string> */
    abstract protected function compileAddForeignKey(AddForeignKey $operation): array;

    /** @return list<string> */
    abstract protected function compileDropForeignKey(DropForeignKey $operation): array;

    /** @return list<string> */
    abstract protected function compileAddCheckConstraint(AddCheckConstraint $operation): array;

    /** @return list<string> */
    abstract protected function compileDropCheckConstraint(DropCheckConstraint $operation): array;

    /** Uma linha de definição de coluna, com a sintaxe do dialeto. */
    abstract protected function renderColumn(ColumnDefinition $column): string;

    // --------------------------------------------------------------- sintaxe

    /**
     * @param list<string> $columns
     */
    final protected function columnList(array $columns): string
    {
        return implode(', ', array_map($this->quoteIdentifier(...), $columns));
    }

    final protected function alterTable(string $table): string
    {
        return 'ALTER TABLE ' . $this->quoteIdentifier($table);
    }

    final protected function addConstraint(string $table, string $name, string $body): string
    {
        return $this->alterTable($table) . ' ADD CONSTRAINT ' . $this->quoteIdentifier($name) . ' ' . $body;
    }

    final protected function dropConstraint(string $table, string $name): string
    {
        return $this->alterTable($table) . ' DROP CONSTRAINT ' . $this->quoteIdentifier($name);
    }

    final protected function primaryKeyBody(PrimaryKeyDefinition $primaryKey): string
    {
        return 'PRIMARY KEY (' . $this->columnList($primaryKey->columns) . ')';
    }

    /**
     * O corpo de uma foreign key, idêntico nos dois bancos.
     *
     * `NO ACTION` é omitido: é o comportamento padrão dos dois, e é para onde
     * `ReferentialAction::canonical()` dobra tanto `RESTRICT` quanto
     * `NO ACTION`. O MySQL reporta regra omitida como RESTRICT e o PostgreSQL
     * como NO ACTION, e as duas voltam ao mesmo enum na introspecção — então
     * omitir é a forma que não gera drift em nenhum dos dois.
     */
    final protected function foreignKeyBody(ForeignKeyDefinition $foreignKey): string
    {
        $sql = 'FOREIGN KEY (' . $this->columnList($foreignKey->columns) . ')'
            . ' REFERENCES ' . $this->quoteIdentifier($foreignKey->referencedTable)
            . ' (' . $this->columnList($foreignKey->referencedColumns) . ')';

        if (!$foreignKey->onDelete->isDefault()) {
            $sql .= ' ON DELETE ' . $foreignKey->onDelete->value;
        }

        if (!$foreignKey->onUpdate->isDefault()) {
            $sql .= ' ON UPDATE ' . $foreignKey->onUpdate->value;
        }

        return $sql;
    }

    /**
     * O corpo do CREATE TABLE: colunas e, quando existe, a chave primária.
     *
     * A PK vai inline porque não há alternativa no MySQL — `CREATE TABLE t (id INT
     * AUTO_INCREMENT)` é recusado com "there can be only one auto column and it
     * must be defined as a key". Uniques, índices, checks e foreign keys ficam de
     * fora: cada um é uma operação própria que as fases P7 e P8 colocam depois de
     * todas as tabelas existirem.
     */
    final protected function tableBody(TableDefinition $table): string
    {
        $lines = [];

        foreach ($table->columns as $column) {
            $lines[] = "\t" . $this->renderColumn($column);
        }

        if ($table->primaryKey !== null) {
            $lines[] = "\t" . $this->inlinePrimaryKey($table->primaryKey);
        }

        return "(\n" . implode(",\n", $lines) . "\n)";
    }

    /**
     * A PK dentro do CREATE TABLE. O MySQL aceita `CONSTRAINT nome` e ignora o
     * nome (a chave primária é sempre `PRIMARY`), então escrevê-lo lá seria
     * mentir sobre o resultado.
     */
    abstract protected function inlinePrimaryKey(PrimaryKeyDefinition $primaryKey): string;

    /**
     * `DEFAULT ...`, ou null quando a coluna não tem cláusula DEFAULT nenhuma.
     *
     * Distinguir "sem DEFAULT" de "DEFAULT NULL" é o ponto de `DefaultKind`: são
     * estados diferentes no catálogo dos dois bancos, e tratá-los como um só faria
     * o differ não conseguir expressar a remoção de um default.
     */
    protected function renderDefault(DefaultValue $default): ?string
    {
        if ($default->isNone()) {
            return null;
        }

        if ($default->isNull()) {
            return 'DEFAULT NULL';
        }

        if ($default->isLiteral()) {
            return 'DEFAULT ' . $this->quoteLiteral($default->value);
        }

        return 'DEFAULT ' . $this->renderDefaultExpression((string) $default->value);
    }

    /**
     * Expressão de default, já canonicalizada por `DefaultValue`.
     *
     * Sai como está: é SQL escrito por quem definiu o model, e não há como citar
     * uma expressão sem deixar de ser uma expressão. É a mesma fronteira de
     * responsabilidade de `RawSql`, e o motivo de ela ser explícita em vez de
     * espalhada.
     */
    protected function renderDefaultExpression(string $expression): string
    {
        return $expression;
    }

    // ----------------------------------------------------------------- tipos

    /**
     * Quem suporta o quê mora em `TypeCatalog`, e num lugar só.
     *
     * Por isso é final: um dialeto que resolvesse recusar um tipo por conta
     * própria criaria uma segunda resposta para a mesma pergunta, e o
     * `SchemaValidator` — que consulta o catálogo — passaria a discordar do
     * gerador de SQL.
     */
    final public function checkType(TypeSpec $type): ?string
    {
        return TypeCatalog::check($type, $this->name());
    }

    final protected function rejectType(TypeSpec $type): never
    {
        throw new UnsupportedTypeException(
            "O dialeto {$this->name()} não sabe escrever {$type->signature()}: "
            . ($this->checkType($type) ?? 'tipo sem renderização definida.')
            . ' SchemaValidator deveria ter recusado este schema antes da geração de SQL.',
        );
    }

    // --------------------------------------------------- tabela de controle

    /**
     * DDL da tabela de bookkeeping, montada a partir do mesmo IR que tudo o mais.
     *
     * `tag` é a chave primária, sem `id` surrogate: a tag *é* a identidade de uma
     * migração, um surrogate seria só um segundo nome para a mesma coisa, e ter a
     * PK natural é o que permite o DDL inteiro ser um único `CREATE TABLE IF NOT
     * EXISTS`. A ordem de aplicação vem do índice no journal, não desta tabela, e
     * a tag ordena lexicograficamente na mesma sequência.
     *
     * 191 e não 255 porque uma PK VARCHAR no MySQL com utf8mb4 gasta 4 bytes por
     * caractere, e 191 é o maior valor que cabe no limite clássico de chave do
     * InnoDB sem depender do formato de linha do servidor.
     */
    final public function migrationsTableDdl(string $table): array
    {
        $name = IdentifierValidator::normalize($table, 'Nome da tabela de migrações');

        $definition = new TableDefinition(
            name: $name,
            columns: [
                new ColumnDefinition('tag', TypeSpec::parse('VARCHAR', 191), notNull: true),
                new ColumnDefinition('hash', TypeSpec::parse('CHAR', 64), notNull: true),
                new ColumnDefinition(
                    'statements',
                    TypeSpec::parse('INT'),
                    notNull: true,
                    default: DefaultValue::literal(0),
                ),
                new ColumnDefinition(
                    'applied_index',
                    TypeSpec::parse('INT'),
                    notNull: true,
                    default: DefaultValue::literal(0),
                ),
                new ColumnDefinition(
                    'status',
                    TypeSpec::parse('VARCHAR', 16),
                    notNull: true,
                    // O default vem do enum, e não de uma string escrita à mão, porque
                    // `PdoMigrationRepository::hydrate()` recusa status que o enum não
                    // conheça: um default fora do conjunto seria uma linha que a própria
                    // biblioteca não consegue ler de volta.
                    default: DefaultValue::literal(MigrationStatus::Running->value),
                ),
                new ColumnDefinition('error', TypeSpec::parse('TEXT')),
                new ColumnDefinition('started_at', TypeSpec::parse('TIMESTAMP')),
                new ColumnDefinition('finished_at', TypeSpec::parse('TIMESTAMP')),
            ],
            primaryKey: new PrimaryKeyDefinition(ConstraintNamer::primaryKey($name), ['tag']),
        );

        return $this->compile(new CreateTable($definition, ifNotExists: true));
    }

    // ------------------------------------------------------------- savepoints

    /**
     * Os três comandos têm a mesma grafia nos dois bancos, então moram aqui.
     *
     * É a exceção à regra de manter tudo abstrato: não há dialeto para divergir,
     * o SQL é o do padrão, e duplicá-lo só criaria duas cópias para sair de sincronia.
     */
    final public function savepoint(int $depth): string
    {
        return 'SAVEPOINT ' . $this->savepointName($depth);
    }

    final public function releaseSavepoint(int $depth): string
    {
        return 'RELEASE SAVEPOINT ' . $this->savepointName($depth);
    }

    final public function rollbackToSavepoint(int $depth): string
    {
        return 'ROLLBACK TO SAVEPOINT ' . $this->savepointName($depth);
    }

    /**
     * Nome derivado da profundidade, e por isso injeção-livre por construção.
     *
     * Passa por `quoteIdentifier()` mesmo sendo gerado: o nome vem de um inteiro e
     * não teria como conter nada perigoso, mas citar mantém a regra "todo
     * identificador que vai para o SQL passa por um lugar só" sem exceção — e
     * exceção a essa regra é como se perde o rastro de onde falta escapar.
     */
    private function savepointName(int $depth): string
    {
        if ($depth < 1) {
            throw new UnsupportedOperationException(
                "Profundidade de savepoint inválida: {$depth}. O nível 0 é a transação em si, "
                . 'que se abre com BEGIN e não com SAVEPOINT.',
            );
        }

        return $this->quoteIdentifier('neoorm_sp' . $depth);
    }
}
