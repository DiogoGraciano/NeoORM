<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Dialect;

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
use Diogodg\Neoorm\Migrations\Operation\RenameColumn;
use Diogodg\Neoorm\Migrations\Operation\RenameTable;
use Diogodg\Neoorm\Migrations\Operation\SetTableComment;
use Diogodg\Neoorm\Migrations\Operation\SetTableOptions;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;

/**
 * Gerador de DDL do MySQL 8.
 *
 * Duas coisas que este dialeto assume do ambiente, e que o runner precisa
 * garantir na sessão:
 *
 * 1. `NO_BACKSLASH_ESCAPES` desligado. É o padrão do MySQL, e é o que faz
 *    `wrapString()` poder dobrar a barra invertida. Com o modo ligado a barra
 *    deixaria de ser especial e a duplicação passaria a inserir duas barras de
 *    verdade. Como o dialeto não tem conexão por construção — `generate` roda
 *    offline —, quem fixa isso é o runner ao abrir a sessão.
 * 2. `explicit_defaults_for_timestamp` ligado, que é o padrão do 8.0. Com ele
 *    desligado, uma coluna `TIMESTAMP NOT NULL` sem DEFAULT ganha
 *    `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` que ninguém pediu, e
 *    a introspecção acusa uma diferença que nenhuma migração resolve. O caso
 *    nullable está coberto: `renderColumn()` escreve `NULL` explicitamente
 *    justamente para desarmar essa mágica.
 */
final class MysqlDialect extends AbstractDialect
{
    public function name(): string
    {
        return 'mysql';
    }

    public function maxIdentifierLength(): int
    {
        return 64;
    }

    /**
     * Tira `NO_BACKSLASH_ESCAPES` do `sql_mode` da sessão.
     *
     * Com esse modo ligado a contrabarra é um caractere comum, e sem ele é escape. Ou
     * seja: o MESMO texto de um literal significa duas coisas diferentes conforme a
     * configuração do servidor. Como `quoteLiteral()` escapa contrabarra — e tem que
     * escapar, porque é o default do MySQL —, um servidor com o modo ligado leria
     * `'a\\b'` como três caracteres em vez de dois e gravaria o dado errado, sem erro
     * nenhum.
     *
     * O `REPLACE` preserva o resto do `sql_mode`. Substituir o valor inteiro por um fixo
     * desligaria `STRICT_TRANS_TABLES` de quem o tem ligado, e aí um `ALTER` que deveria
     * falhar por dado incompatível passaria a truncar em silêncio — trocando um erro de
     * migração por corrupção de dados.
     *
     * @return list<string>
     */
    public function sessionSetup(): array
    {
        return ["SET SESSION sql_mode = REPLACE(@@SESSION.sql_mode, 'NO_BACKSLASH_ESCAPES', '')"];
    }

    /**
     * Cada statement de DDL dá commit implícito. Não é uma limitação que dê para
     * contornar: era a causa da transação furada do sistema antigo, que rodava
     * `CREATE TABLE IF NOT EXISTS` no construtor de um rastreador, uma vez por
     * model, dentro da transação do migrate.
     */
    public function supportsTransactionalDdl(): bool
    {
        return false;
    }

    /**
     * O MySQL representa tudo que o IR tem, então nada é descartado.
     */
    public function normalizeForStorage(SchemaDefinition $schema): SchemaDefinition
    {
        return $schema;
    }

    protected function wrapIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }

    protected function wrapString(string $value): string
    {
        $escaped = str_replace(
            ['\\', "'", "\0"],
            ['\\\\', "''", '\\0'],
            $value,
        );

        return "'" . $escaped . "'";
    }

    /** BOOLEAN é TINYINT(1) aqui, então o literal é numérico. */
    protected function renderBoolean(bool $value): string
    {
        return $value ? '1' : '0';
    }

    // ----------------------------------------------------------------- tipos

    public function renderType(TypeSpec $type): string
    {
        if ($this->checkType($type) !== null) {
            $this->rejectType($type);
        }

        $rendered = $this->renderBaseType($type);

        if ($type->unsigned && $type->name->isNumeric()) {
            $rendered .= ' UNSIGNED';
        }

        return $rendered;
    }

    private function renderBaseType(TypeSpec $type): string
    {
        $name = $type->name;

        // BOOLEAN é um apelido de TINYINT(1) no MySQL. Escrever a forma expandida
        // é o que permite à introspecção distinguir um BOOLEAN de um TINYINT: o
        // catálogo devolve `tinyint(1)` para este e `tinyint` para aquele, porque
        // largura de exibição de tipo integral nunca chega ao IR.
        if ($name === TypeName::Boolean) {
            return 'TINYINT(1)';
        }

        if ($name === TypeName::Enum) {
            $values = array_map($this->quoteLiteral(...), $type->values ?? []);

            return 'ENUM(' . implode(', ', $values) . ')';
        }

        if ($type->precision !== null && $name->acceptsPrecision()) {
            return $name->value . '(' . $type->precision . ',' . ($type->scale ?? 0) . ')';
        }

        if ($name->acceptsLength()) {
            if ($type->length !== null) {
                return $name->value . '(' . $type->length . ')';
            }

            // VARCHAR e VARBINARY sem tamanho não são sintaxe válida no MySQL.
            // TypeCatalog::requiresLength() diz isso e o SchemaValidator recusa
            // antes; aqui é a rede embaixo.
            if ($name === TypeName::Varchar || $name === TypeName::VarBinary) {
                $this->rejectType($type);
            }
        }

        return $name->value;
    }

    // --------------------------------------------------------------- colunas

    protected function renderColumn(ColumnDefinition $column): string
    {
        $parts = [$this->quoteIdentifier($column->name), $this->renderType($column->type)];

        if ($column->collation !== null) {
            $parts[] = 'COLLATE ' . $this->safeOptionValue($column->collation, 'Collation de coluna');
        }

        // NULL e NOT NULL sempre explícitos. Omitir `NULL` numa coluna TIMESTAMP
        // deixaria o resultado depender de explicit_defaults_for_timestamp, e é o
        // tipo de dependência de configuração de servidor que produz drift que
        // ninguém consegue reproduzir.
        $parts[] = $column->notNull ? 'NOT NULL' : 'NULL';

        $default = $this->renderDefault($column->default);

        if ($default !== null) {
            $parts[] = $default;
        }

        if ($column->autoIncrement) {
            $parts[] = 'AUTO_INCREMENT';
        }

        if ($column->comment !== null) {
            $parts[] = 'COMMENT ' . $this->quoteLiteral($column->comment);
        }

        return implode(' ', $parts);
    }

    /**
     * O MySQL 8.0.13+ exige parênteses em default de expressão — com uma exceção
     * que importa muito na prática.
     *
     * `DEFAULT CURRENT_TIMESTAMP` é um default especial de TIMESTAMP e DATETIME.
     * Escrever `DEFAULT (CURRENT_TIMESTAMP)` é aceito, mas registra a coluna como
     * `DEFAULT_GENERATED`, e aí o catálogo devolve a expressão em outra forma que
     * a introspecção lê como diferente da declarada. Como praticamente todo model
     * tem uma coluna assim, isso significaria uma migração espúria por tabela em
     * cada `generate`.
     */
    protected function renderDefaultExpression(string $expression): string
    {
        if (strcasecmp($expression, 'CURRENT_TIMESTAMP') === 0) {
            return 'CURRENT_TIMESTAMP';
        }

        return '(' . $expression . ')';
    }

    protected function inlinePrimaryKey(PrimaryKeyDefinition $primaryKey): string
    {
        // Sem `CONSTRAINT nome`: o MySQL aceita a sintaxe e descarta o nome, já que
        // a chave primária se chama sempre PRIMARY. Escrevê-lo faria o DDL afirmar
        // uma coisa que o banco não guarda.
        return $this->primaryKeyBody($primaryKey);
    }

    // ------------------------------------------------------------- operações

    protected function compileCreateTable(CreateTable $operation): array
    {
        $table = $operation->table;

        $sql = 'CREATE TABLE ';

        if ($operation->ifNotExists) {
            $sql .= 'IF NOT EXISTS ';
        }

        $sql .= $this->quoteIdentifier($table->name) . ' ' . $this->tableBody($table);

        if ($table->options->engine !== null) {
            $sql .= ' ENGINE=' . $this->safeOptionValue($table->options->engine, 'Engine de tabela');
        }

        if ($table->options->collation !== null) {
            $sql .= ' COLLATE=' . $this->safeOptionValue($table->options->collation, 'Collation de tabela');
        }

        if ($table->comment !== null) {
            $sql .= ' COMMENT=' . $this->quoteLiteral($table->comment);
        }

        return [$sql];
    }

    protected function compileDropTable(DropTable $operation): array
    {
        // Sem IF EXISTS: uma migração descreve uma transição exata, e tolerar a
        // ausência da tabela transformaria drift em silêncio.
        return ['DROP TABLE ' . $this->quoteIdentifier($operation->table)];
    }

    protected function compileRenameTable(RenameTable $operation): array
    {
        return [$this->alterTable($operation->from) . ' RENAME TO ' . $this->quoteIdentifier($operation->to)];
    }

    protected function compileSetTableComment(SetTableComment $operation): array
    {
        // Não existe "remover comentário" no MySQL; a ausência é a string vazia.
        return [
            $this->alterTable($operation->table)
            . ' COMMENT=' . $this->quoteLiteral($operation->comment ?? ''),
        ];
    }

    protected function compileSetTableOptions(SetTableOptions $operation): array
    {
        $parts = [];

        if ($operation->options->engine !== null) {
            $parts[] = 'ENGINE=' . $this->safeOptionValue($operation->options->engine, 'Engine de tabela');
        }

        if ($operation->options->collation !== null) {
            $parts[] = 'COLLATE=' . $this->safeOptionValue($operation->options->collation, 'Collation de tabela');
        }

        // Tudo nulo significa "default do servidor", e não existe DDL para pedir o
        // default de volta sem afirmar um valor concreto. Nada a fazer é a resposta
        // honesta — e é o que impede o diff fantasma de collation de virar um ALTER
        // que a comparação seguinte nunca aceita.
        if ($parts === []) {
            return [];
        }

        return [$this->alterTable($operation->table) . ' ' . implode(', ', $parts)];
    }

    protected function compileAddColumn(AddColumn $operation): array
    {
        return [$this->alterTable($operation->table) . ' ADD COLUMN ' . $this->renderColumn($operation->column)];
    }

    protected function compileDropColumn(DropColumn $operation): array
    {
        return [
            $this->alterTable($operation->table)
            . ' DROP COLUMN ' . $this->quoteIdentifier($operation->column),
        ];
    }

    protected function compileRenameColumn(RenameColumn $operation): array
    {
        // RENAME COLUMN existe a partir do MySQL 8.0; o CHANGE COLUMN antigo
        // exigia redeclarar a definição inteira, e esquecer um pedaço dela era
        // como se perdiam comentários em rename.
        return [
            $this->alterTable($operation->table)
            . ' RENAME COLUMN ' . $this->quoteIdentifier($operation->from)
            . ' TO ' . $this->quoteIdentifier($operation->to),
        ];
    }

    /**
     * Um único MODIFY COLUMN com a definição completa.
     *
     * Não é escolha de estilo: o MODIFY COLUMN do MySQL substitui a definição da
     * coluna por inteiro. Omitir o comentário apaga o comentário, omitir o DEFAULT
     * remove o default. Por isso a operação carrega a definição de destino inteira
     * em vez de só o que mudou.
     */
    protected function compileAlterColumn(AlterColumn $operation): array
    {
        if ($operation->changes->isEmpty()) {
            return [];
        }

        return [$this->alterTable($operation->table) . ' MODIFY COLUMN ' . $this->renderColumn($operation->to)];
    }

    protected function compileAddPrimaryKey(AddPrimaryKey $operation): array
    {
        return [$this->alterTable($operation->table) . ' ADD ' . $this->primaryKeyBody($operation->primaryKey)];
    }

    protected function compileDropPrimaryKey(DropPrimaryKey $operation): array
    {
        $statements = [];

        // O MySQL recusa DROP PRIMARY KEY enquanto a coluna for AUTO_INCREMENT:
        // "there can be only one auto column and it must be defined as a key".
        if ($operation->autoIncrementColumn !== null) {
            $statements[] = $this->alterTable($operation->table)
                . ' MODIFY COLUMN '
                . $this->renderColumn($operation->autoIncrementColumn->withAutoIncrement(false));
        }

        $statements[] = $this->alterTable($operation->table) . ' DROP PRIMARY KEY';

        return $statements;
    }

    protected function compileAddUniqueConstraint(AddUniqueConstraint $operation): array
    {
        return [
            $this->addConstraint(
                $operation->table,
                $operation->constraint->name,
                'UNIQUE (' . $this->columnList($operation->constraint->columns) . ')',
            ),
        ];
    }

    protected function compileDropUniqueConstraint(DropUniqueConstraint $operation): array
    {
        // No MySQL uma restrição UNIQUE *é* um índice, e DROP INDEX funciona em
        // toda versão suportada — DROP CONSTRAINT só existe a partir do 8.0.19.
        return [$this->alterTable($operation->table) . ' DROP INDEX ' . $this->quoteIdentifier($operation->name)];
    }

    protected function compileCreateIndex(CreateIndex $operation): array
    {
        $index = $operation->index;

        $sql = 'CREATE ' . ($index->unique ? 'UNIQUE INDEX ' : 'INDEX ') . $this->quoteIdentifier($index->name);

        if ($index->method !== null) {
            $sql .= ' USING ' . $this->safeOptionValue($index->method, 'Método de índice');
        }

        $sql .= ' ON ' . $this->quoteIdentifier($operation->table)
            . ' (' . $this->columnList($index->columns) . ')';

        return [$sql];
    }

    protected function compileDropIndex(DropIndex $operation): array
    {
        return [$this->alterTable($operation->table) . ' DROP INDEX ' . $this->quoteIdentifier($operation->name)];
    }

    protected function compileAddForeignKey(AddForeignKey $operation): array
    {
        return [
            $this->addConstraint(
                $operation->table,
                $operation->foreignKey->name,
                $this->foreignKeyBody($operation->foreignKey),
            ),
        ];
    }

    protected function compileDropForeignKey(DropForeignKey $operation): array
    {
        return [
            $this->alterTable($operation->table)
            . ' DROP FOREIGN KEY ' . $this->quoteIdentifier($operation->name),
        ];
    }

    protected function compileAddCheckConstraint(AddCheckConstraint $operation): array
    {
        return [
            $this->addConstraint(
                $operation->table,
                $operation->check->name,
                'CHECK (' . $operation->check->expression . ')',
            ),
        ];
    }

    protected function compileDropCheckConstraint(DropCheckConstraint $operation): array
    {
        return [$this->alterTable($operation->table) . ' DROP CHECK ' . $this->quoteIdentifier($operation->name)];
    }

    // ------------------------------------------------------------------- DML

    /**
     * O MySQL não sabe dizer OFFSET sem LIMIT, e o contorno é documentado por ele
     * mesmo: um limite de 2^64-1, que é maior que qualquer tabela possível.
     *
     * A alternativa seria recusar `offset()` sem `limit()`, mas isso proibiria no
     * MySQL uma consulta que o PostgreSQL aceita — e a diferença apareceria só em
     * produção, no banco que não foi usado no desenvolvimento.
     */
    public function limitOffsetClause(?string $limitPlaceholder, ?string $offsetPlaceholder): string
    {
        if ($limitPlaceholder === null && $offsetPlaceholder === null) {
            return '';
        }

        if ($limitPlaceholder === null) {
            return 'LIMIT 18446744073709551615 OFFSET ' . $offsetPlaceholder;
        }

        $clause = 'LIMIT ' . $limitPlaceholder;

        return $offsetPlaceholder === null ? $clause : $clause . ' OFFSET ' . $offsetPlaceholder;
    }

    public function supportsReturning(): bool
    {
        return false;
    }

    public function supportsReturningOnModify(): bool
    {
        return false;
    }

    /**
     * Sem `ILIKE` no MySQL: a insensibilidade a caixa depende do collation da
     * coluna, que a biblioteca não escolhe e não pode assumir.
     *
     * `LOWER()` nos dois lados descarta o índice da coluna, e é o preço de dar a
     * mesma resposta que o PostgreSQL daria. Quem precisa do índice usa `like()`
     * com o collation apropriado na coluna, que é uma decisão de schema.
     */
    public function caseInsensitiveLike(string $left, string $right): string
    {
        return 'LOWER(' . $left . ') LIKE LOWER(' . $right . ')';
    }

    public function supportsNullsPlacement(): bool
    {
        return false;
    }

    public function connectionSetup(): array
    {
        return ["SET time_zone = '+00:00'"];
    }
}
