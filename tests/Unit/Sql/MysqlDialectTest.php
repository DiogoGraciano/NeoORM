<?php

declare(strict_types=1);

namespace Tests\Unit\Sql;

use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Exception\UnsupportedTypeException;
use Diogodg\Neoorm\Migrations\Operation\AddColumn;
use Diogodg\Neoorm\Migrations\Operation\AlterColumn;
use Diogodg\Neoorm\Migrations\Operation\CreateTable;
use Diogodg\Neoorm\Migrations\Operation\DropIndex;
use Diogodg\Neoorm\Migrations\Operation\DropPrimaryKey;
use Diogodg\Neoorm\Migrations\Operation\DropUniqueConstraint;
use Diogodg\Neoorm\Migrations\Operation\SetTableComment;
use Diogodg\Neoorm\Migrations\Operation\SetTableOptions;
use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;
use Diogodg\Neoorm\Schema\TableOptions;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\SqlAssertions;
use Tests\Support\Factory\Ir;
use Tests\Support\Factory\Ops;
use Tests\Support\UnitTestCase;

/**
 * O que é específico do MySQL.
 *
 * Cada asserção aqui foi verificada contra um MySQL 8.0 de verdade — não é SQL
 * que parece certo, é SQL que o servidor aceitou. O contrário é como se acumula
 * um gerador que produz DDL plausível e inválida.
 */
final class MysqlDialectTest extends UnitTestCase
{
    use SqlAssertions;

    private function mysql(): \Diogodg\Neoorm\Dialect\Dialect
    {
        return DialectFactory::mysql();
    }

    public function testIdentifiersUseBackticks(): void
    {
        $this->assertSame('`order`', $this->mysql()->quoteIdentifier('order'));
    }

    /**
     * O escape acontece mesmo que a validação já garanta que nada assim chega
     * aqui: se um dia o validador afrouxar, a segunda camada continua de pé.
     */
    public function testBacktickEscapingIsTheSecondLayerBehindValidation(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->mysql()->quoteIdentifier('us`ers');
    }

    public function testMaxIdentifierLengthIsSixtyFour(): void
    {
        $this->assertSame(64, $this->mysql()->maxIdentifierLength());
    }

    /**
     * Não é uma limitação contornável: era a causa da transação furada do sistema
     * antigo, que rodava DDL dentro dela e ganhava um commit implícito de brinde.
     */
    public function testDdlIsNotTransactional(): void
    {
        $this->assertFalse($this->mysql()->supportsTransactionalDdl());
    }

    // ----------------------------------------------------------------- tipos

    #[DataProvider('typeRenderings')]
    public function testTypesRenderWithMysqlSpelling(string $type, string|int|null $size, string $expected): void
    {
        $this->assertSame($expected, $this->mysql()->renderType(TypeSpec::parse($type, $size)));
    }

    /**
     * @return iterable<string,array{string,string|int|null,string}>
     */
    public static function typeRenderings(): iterable
    {
        yield 'int' => ['INT', null, 'INT'];
        yield 'integer vira int' => ['INTEGER', null, 'INT'];
        yield 'bigint' => ['BIGINT', null, 'BIGINT'];
        yield 'varchar com tamanho' => ['VARCHAR', 120, 'VARCHAR(120)'];
        yield 'char' => ['CHAR', 64, 'CHAR(64)'];
        yield 'text' => ['TEXT', null, 'TEXT'];
        yield 'decimal' => ['DECIMAL', '10,2', 'DECIMAL(10,2)'];
        yield 'double' => ['DOUBLE', null, 'DOUBLE'];
        yield 'datetime' => ['DATETIME', null, 'DATETIME'];
        yield 'timestamp' => ['TIMESTAMP', null, 'TIMESTAMP'];
        yield 'json' => ['JSON', null, 'JSON'];
        yield 'unsigned' => ['INT UNSIGNED', null, 'INT UNSIGNED'];

        // A forma expandida é o que deixa a introspecção distinguir os dois: o
        // catálogo devolve `tinyint(1)` para BOOLEAN e `tinyint` para TINYINT,
        // porque largura de exibição de tipo integral nunca entra no IR.
        yield 'boolean vira tinyint(1)' => ['BOOLEAN', null, 'TINYINT(1)'];
        yield 'tinyint fica tinyint' => ['TINYINT', null, 'TINYINT'];
        yield 'tinyint(1) declarado perde a largura' => ['TINYINT', 1, 'TINYINT'];
        yield 'int(11) declarado perde a largura' => ['INT', 11, 'INT'];

        yield 'enum' => ["ENUM('a','b')", null, "ENUM('a', 'b')"];
    }

    public function testEnumValuesGoThroughLiteralQuoting(): void
    {
        $rendered = $this->mysql()->renderType(TypeSpec::parse("ENUM('pode''ser','simples')"));

        $this->assertSame("ENUM('pode''ser', 'simples')", $rendered);
    }

    #[DataProvider('typesTheEngineDoesNotHave')]
    public function testTypesFromTheOtherEngineAreRejectedWithAnAlternative(string $type, string $hint): void
    {
        $spec = TypeSpec::parse($type);

        $this->assertNotNull($this->mysql()->checkType($spec));
        $this->assertStringContainsString($hint, (string) $this->mysql()->checkType($spec));

        $this->expectException(UnsupportedTypeException::class);
        $this->mysql()->renderType($spec);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function typesTheEngineDoesNotHave(): iterable
    {
        yield 'jsonb' => ['JSONB', 'JSON'];
        yield 'uuid' => ['UUID', 'CHAR(36)'];
        yield 'bytea' => ['BYTEA', 'BLOB'];
    }

    /**
     * VARCHAR sem tamanho não é sintaxe válida no MySQL. O caminho normal para
     * isso é o SchemaValidator; aqui é a rede embaixo, e ela existe para a falha
     * vir com uma mensagem nossa em vez de um erro de sintaxe do servidor.
     */
    public function testVarcharWithoutLengthIsRejected(): void
    {
        $this->expectException(UnsupportedTypeException::class);
        $this->mysql()->renderType(TypeSpec::parse('VARCHAR'));
    }

    // --------------------------------------------------------------- colunas

    public function testCreateTableIsOneStatementWithInlineCommentsAndOptions(): void
    {
        $statements = $this->mysql()->compile(new CreateTable(Ops::productTable()));

        $this->assertSingleSql(
            "CREATE TABLE `product` ("
            . " `id` INT NOT NULL AUTO_INCREMENT,"
            . " `name` VARCHAR(120) NOT NULL COMMENT 'Nome do produto',"
            . " `price` DECIMAL(10,2) NOT NULL DEFAULT 0,"
            . " `active` TINYINT(1) NOT NULL DEFAULT 1,"
            . " `description` TEXT NULL,"
            . " `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,"
            . " `category` INT NULL,"
            . " PRIMARY KEY (`id`)"
            . " ) ENGINE=InnoDB COMMENT='Catálogo de produtos'",
            $statements,
        );
    }

    /**
     * O nome da PK não é escrito porque o MySQL não o guarda: a chave primária se
     * chama sempre PRIMARY. Escrever `CONSTRAINT product_pk` seria o DDL afirmando
     * uma coisa que a introspecção não confirma.
     */
    public function testThePrimaryKeyNameIsNotWrittenBecauseTheEngineDiscardsIt(): void
    {
        $sql = $this->mysql()->compile(new CreateTable(Ops::productTable()))[0];

        $this->assertStringContainsString('PRIMARY KEY (`id`)', $sql);
        $this->assertStringNotContainsString('product_pk', $sql);
    }

    /**
     * `NULL` explícito em toda coluna nullable.
     *
     * Omiti-lo faria uma coluna TIMESTAMP nullable depender de
     * `explicit_defaults_for_timestamp`: com a variável desligada, o servidor lhe
     * dá `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP` sem ninguém
     * pedir, e a introspecção acusa uma diferença que nenhuma migração resolve.
     */
    public function testNullabilityIsAlwaysExplicit(): void
    {
        $sql = $this->mysql()->compile(new AddColumn('t', Ir::column('at', 'TIMESTAMP')))[0];

        $this->assertSqlEquals('ALTER TABLE `t` ADD COLUMN `at` TIMESTAMP NULL', $sql);
    }

    /**
     * O MySQL 8 exige parênteses em default de expressão, menos para
     * CURRENT_TIMESTAMP puro — e essa exceção não é cosmética. Com parênteses a
     * coluna é registrada como DEFAULT_GENERATED, o catálogo devolve a expressão em
     * outra forma, e como quase todo model tem uma coluna `created_at`, seria uma
     * migração espúria por tabela em cada `generate`.
     */
    public function testCurrentTimestampDefaultHasNoParenthesesButOtherExpressionsDo(): void
    {
        $bare = $this->mysql()->compile(new AddColumn(
            't',
            Ir::column('at', 'TIMESTAMP', notNull: true, default: DefaultValue::expression('CURRENT_TIMESTAMP')),
        ))[0];

        $this->assertStringContainsString('DEFAULT CURRENT_TIMESTAMP', $bare);
        $this->assertStringNotContainsString('(CURRENT_TIMESTAMP)', $bare);

        $wrapped = $this->mysql()->compile(new AddColumn(
            't',
            Ir::column('total', 'INT', notNull: true, default: DefaultValue::expression('1 + 1')),
        ))[0];

        $this->assertStringContainsString('DEFAULT (1 + 1)', $wrapped);
    }

    public function testBooleanLiteralsAreNumericBecauseBooleanIsTinyint(): void
    {
        $this->assertSame('1', $this->mysql()->quoteLiteral(true));
        $this->assertSame('0', $this->mysql()->quoteLiteral(false));
    }

    /**
     * Assume `NO_BACKSLASH_ESCAPES` desligado, que é o padrão do MySQL. O dialeto
     * não tem conexão por construção — `generate` roda offline —, então quem fixa
     * o modo da sessão é o runner.
     */
    public function testBackslashAndQuoteAreBothEscaped(): void
    {
        $this->assertSame("'a\\\\b'", $this->mysql()->quoteLiteral('a\\b'));
        $this->assertSame("'d''água'", $this->mysql()->quoteLiteral("d'água"));
        $this->assertSame("'a\\0b'", $this->mysql()->quoteLiteral("a\0b"));
    }

    // ------------------------------------------------------------- operações

    /**
     * Um MODIFY COLUMN, com a definição inteira.
     *
     * Não é escolha de estilo: MODIFY COLUMN substitui a definição da coluna por
     * completo, então omitir o comentário apaga o comentário. É a razão de
     * `AlterColumn` carregar a definição de destino inteira em vez de só o delta.
     */
    public function testAlterColumnRedeclaresTheWholeDefinition(): void
    {
        $operation = new AlterColumn(
            'product',
            Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'antes'),
            Ir::column('name', 'VARCHAR', 200, notNull: true, comment: 'depois'),
        );

        $this->assertSingleSql(
            "ALTER TABLE `product` MODIFY COLUMN `name` VARCHAR(200) NOT NULL COMMENT 'depois'",
            $this->mysql()->compile($operation),
        );
    }

    /**
     * Mesmo mudando só o comentário: não existe outra forma no MySQL, e é por isso
     * que `SetColumnComment` não é uma operação — o conceito não se separa aqui.
     */
    public function testACommentOnlyChangeIsStillAFullModifyColumn(): void
    {
        $operation = new AlterColumn(
            'product',
            Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'antes'),
            Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'depois'),
        );

        $this->assertTrue($operation->changes->isCommentOnly());
        $this->assertSingleSql(
            "ALTER TABLE `product` MODIFY COLUMN `name` VARCHAR(120) NOT NULL COMMENT 'depois'",
            $this->mysql()->compile($operation),
        );
    }

    public function testAnAlterColumnWithNoChangesCompilesToNothing(): void
    {
        $column = Ir::column('name', 'VARCHAR', 120, notNull: true);

        $this->assertSame([], $this->mysql()->compile(new AlterColumn('product', $column, $column)));
    }

    /**
     * O MySQL recusa `DROP PRIMARY KEY` enquanto a coluna for AUTO_INCREMENT:
     * "there can be only one auto column and it must be defined as a key".
     */
    public function testDroppingAPrimaryKeyStripsAutoIncrementFirst(): void
    {
        $this->assertSqlListEquals(
            [
                'ALTER TABLE `product` MODIFY COLUMN `id` INT NOT NULL',
                'ALTER TABLE `product` DROP PRIMARY KEY',
            ],
            $this->mysql()->compile(new DropPrimaryKey('product', 'product_pk', Ir::id())),
        );
    }

    public function testDroppingAPrimaryKeyWithoutAutoIncrementIsASingleStatement(): void
    {
        $this->assertSingleSql(
            'ALTER TABLE `product` DROP PRIMARY KEY',
            $this->mysql()->compile(new DropPrimaryKey('product', 'product_pk')),
        );
    }

    /**
     * No MySQL uma restrição UNIQUE *é* um índice, e as duas remoções são o mesmo
     * comando. `DROP CONSTRAINT` só existe a partir do 8.0.19.
     */
    public function testUniqueConstraintsAndIndexesAreBothDroppedAsIndexes(): void
    {
        $this->assertSingleSql(
            'ALTER TABLE `product` DROP INDEX `product_sku_unique`',
            $this->mysql()->compile(new DropUniqueConstraint('product', 'product_sku_unique')),
        );

        $this->assertSingleSql(
            'ALTER TABLE `product` DROP INDEX `product_category_index`',
            $this->mysql()->compile(new DropIndex('product', 'product_category_index')),
        );
    }

    /** Não existe "remover comentário" no MySQL; a ausência é a string vazia. */
    public function testRemovingATableCommentSetsItToTheEmptyString(): void
    {
        $this->assertSingleSql(
            "ALTER TABLE `product` COMMENT=''",
            $this->mysql()->compile(new SetTableComment('product', null)),
        );
    }

    public function testTableOptionsAreEmittedOnlyWhenSet(): void
    {
        $this->assertSingleSql(
            'ALTER TABLE `product` ENGINE=InnoDB',
            $this->mysql()->compile(new SetTableOptions('product', new TableOptions(engine: 'InnoDB'))),
        );

        $this->assertSingleSql(
            'ALTER TABLE `product` COLLATE=utf8mb4_bin',
            $this->mysql()->compile(new SetTableOptions('product', new TableOptions(collation: 'utf8mb4_bin'))),
        );
    }

    /**
     * Tudo nulo significa "default do servidor", e não há DDL que peça o default de
     * volta sem afirmar um valor concreto. É esta lista vazia que mata na raiz o
     * diff fantasma de collation: o sistema antigo assumia `utf8mb4_general_ci`
     * como default do builder e o comparava contra um servidor cujo default é
     * `utf8mb4_0900_ai_ci`, gerando um ALTER que a comparação seguinte nunca
     * aceitava.
     */
    public function testUnsetTableOptionsCompileToNothingInsteadOfAssertingADefault(): void
    {
        $this->assertSame(
            [],
            $this->mysql()->compile(new SetTableOptions('product', new TableOptions())),
        );
    }

    /**
     * Engine e collation entram na DDL sem citação, então a validação é o que
     * protege — e ela existe.
     */
    public function testEngineAndCollationAreValidatedBeforeEnteringTheDdl(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        $this->mysql()->compile(new SetTableOptions(
            'product',
            new TableOptions(engine: "InnoDB' ; DROP TABLE users --"),
        ));
    }

    public function testIndexMethodComesBeforeTheOnClause(): void
    {
        $operation = new \Diogodg\Neoorm\Migrations\Operation\CreateIndex(
            'product',
            new \Diogodg\Neoorm\Schema\IndexDefinition('product_name_index', ['name'], method: 'BTREE'),
        );

        $this->assertSingleSql(
            'CREATE INDEX `product_name_index` USING BTREE ON `product` (`name`)',
            $this->mysql()->compile($operation),
        );
    }

    // ------------------------------------------------------------------- DML

    public function testPaginationUsesTheStandardFormNotTheCommaForm(): void
    {
        // `LIMIT :off, :lim` é a forma do MySQL e seria erro de sintaxe no
        // PostgreSQL — além de trocar a ordem dos dois números em relação ao que
        // os nomes sugerem. O compilador emite uma forma só, a dos dois bancos.
        $this->assertSame('LIMIT :lim OFFSET :off', $this->mysql()->limitOffsetClause(':lim', ':off'));
        $this->assertSame('LIMIT :lim', $this->mysql()->limitOffsetClause(':lim', null));
    }

    /**
     * 2^64-1 é o contorno documentado pelo próprio MySQL para OFFSET sem LIMIT.
     */
    public function testOffsetWithoutLimitUsesTheDocumentedSentinel(): void
    {
        $this->assertSame(
            'LIMIT 18446744073709551615 OFFSET :off',
            $this->mysql()->limitOffsetClause(null, ':off'),
        );
    }

    public function testThereIsNoReturning(): void
    {
        $this->assertFalse($this->mysql()->supportsReturning());
        $this->assertFalse($this->mysql()->supportsReturningOnModify());
    }

    /**
     * Sem ILIKE: a insensibilidade a caixa no MySQL depende do collation da
     * coluna, que a biblioteca não escolhe. `LOWER()` nos dois lados custa o
     * índice e devolve a mesma resposta que o PostgreSQL daria.
     */
    public function testCaseInsensitiveLikeIsLoweredOnBothSides(): void
    {
        $this->assertSame(
            'LOWER(`nome`) LIKE LOWER(:p0)',
            $this->mysql()->caseInsensitiveLike('`nome`', ':p0'),
        );
    }

    public function testSavepointNamesAreQuotedWithBackticks(): void
    {
        $this->assertSame('SAVEPOINT `neoorm_sp1`', $this->mysql()->savepoint(1));
        $this->assertSame('RELEASE SAVEPOINT `neoorm_sp1`', $this->mysql()->releaseSavepoint(1));
        $this->assertSame('ROLLBACK TO SAVEPOINT `neoorm_sp1`', $this->mysql()->rollbackToSavepoint(1));
    }
}
