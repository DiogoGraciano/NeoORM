<?php

declare(strict_types=1);

namespace Tests\Unit\Sql;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Exception\UnsupportedTypeException;
use Diogodg\Neoorm\Migrations\Operation\AddColumn;
use Diogodg\Neoorm\Migrations\Operation\AlterColumn;
use Diogodg\Neoorm\Migrations\Operation\CreateIndex;
use Diogodg\Neoorm\Migrations\Operation\CreateTable;
use Diogodg\Neoorm\Migrations\Operation\DropIndex;
use Diogodg\Neoorm\Migrations\Operation\DropPrimaryKey;
use Diogodg\Neoorm\Migrations\Operation\DropUniqueConstraint;
use Diogodg\Neoorm\Migrations\Operation\SetTableComment;
use Diogodg\Neoorm\Migrations\Operation\SetTableOptions;
use Diogodg\Neoorm\Schema\IndexDefinition;
use Diogodg\Neoorm\Schema\TableOptions;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\SqlAssertions;
use Tests\Support\Factory\Ir;
use Tests\Support\Factory\Ops;
use Tests\Support\UnitTestCase;

/**
 * O que é específico do PostgreSQL.
 *
 * Como no teste do MySQL, cada asserção foi verificada contra um PostgreSQL 17 de
 * verdade.
 */
final class PgsqlDialectTest extends UnitTestCase
{
    use SqlAssertions;

    private function pgsql(): Dialect
    {
        return DialectFactory::pgsql();
    }

    public function testIdentifiersUseDoubleQuotes(): void
    {
        $this->assertSame('"order"', $this->pgsql()->quoteIdentifier('order'));
    }

    public function testMaxIdentifierLengthIsSixtyThree(): void
    {
        $this->assertSame(63, $this->pgsql()->maxIdentifierLength());
    }

    /**
     * É o que permite ao runner aplicar a migração e o próprio bookkeeping num
     * único COMMIT — a assimetria com o MySQL vai declarada na doc, não escondida.
     */
    public function testDdlIsTransactional(): void
    {
        $this->assertTrue($this->pgsql()->supportsTransactionalDdl());
    }

    // ----------------------------------------------------------------- tipos

    #[DataProvider('typeRenderings')]
    public function testTypesRenderWithPostgresSpelling(string $type, string|int|null $size, string $expected): void
    {
        $this->assertSame($expected, $this->pgsql()->renderType(TypeSpec::parse($type, $size)));
    }

    /**
     * As grafias escolhidas são as que o catálogo do PostgreSQL devolve, para a
     * introspecção fechar o círculo por `TypeName::fromAlias()`.
     *
     * @return iterable<string,array{string,string|int|null,string}>
     */
    public static function typeRenderings(): iterable
    {
        yield 'int vira integer' => ['INT', null, 'INTEGER'];
        yield 'bigint' => ['BIGINT', null, 'BIGINT'];
        yield 'smallint' => ['SMALLINT', null, 'SMALLINT'];
        yield 'decimal vira numeric' => ['DECIMAL', '10,2', 'NUMERIC(10,2)'];
        yield 'double vira double precision' => ['DOUBLE', null, 'DOUBLE PRECISION'];
        yield 'float vira real' => ['FLOAT', null, 'REAL'];
        yield 'boolean' => ['BOOLEAN', null, 'BOOLEAN'];
        yield 'varchar com tamanho' => ['VARCHAR', 120, 'VARCHAR(120)'];
        yield 'varchar sem tamanho é legal aqui' => ['VARCHAR', null, 'VARCHAR'];
        yield 'text' => ['TEXT', null, 'TEXT'];
        yield 'timestamp' => ['TIMESTAMP', null, 'TIMESTAMP'];
        yield 'timestamptz' => ['TIMESTAMPTZ', null, 'TIMESTAMPTZ'];
        yield 'jsonb' => ['JSONB', null, 'JSONB'];
        yield 'uuid' => ['UUID', null, 'UUID'];
        yield 'int(11) declarado perde a largura' => ['INT', 11, 'INTEGER'];
    }

    #[DataProvider('typesTheEngineDoesNotHave')]
    public function testTypesFromTheOtherEngineAreRejectedWithAnAlternative(string $type, string $hint): void
    {
        $spec = TypeSpec::parse($type);

        $this->assertStringContainsString($hint, (string) $this->pgsql()->checkType($spec));

        $this->expectException(UnsupportedTypeException::class);
        $this->pgsql()->renderType($spec);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function typesTheEngineDoesNotHave(): iterable
    {
        yield 'tinyint' => ['TINYINT', 'SMALLINT'];
        yield 'mediumint' => ['MEDIUMINT', 'SMALLINT'];
        yield 'longtext' => ['LONGTEXT', 'TEXT'];
        yield 'blob' => ['BLOB', 'BYTEA'];
        yield 'varbinary' => ['VARBINARY', 'BYTEA'];
        yield 'year' => ['YEAR', 'SMALLINT'];
        yield 'enum' => ["ENUM('a','b')", 'VARCHAR'];
    }

    /**
     * DATETIME e TIMESTAMP são o mesmo tipo aqui (`timestamp without time zone`).
     * Aceitar os dois faria um model que escreve DATETIME renderizar TIMESTAMP,
     * introspectar de volta como TIMESTAMP e divergir do próprio snapshot em toda
     * execução — uma divergência de representação, que nenhuma migração resolve.
     */
    public function testDatetimeIsRejectedBecauseItWouldCollideWithTimestamp(): void
    {
        $reason = (string) $this->pgsql()->checkType(TypeSpec::parse('DATETIME'));

        $this->assertStringContainsString('TIMESTAMP', $reason);
        $this->expectException(UnsupportedTypeException::class);
        $this->pgsql()->renderType(TypeSpec::parse('DATETIME'));
    }

    /**
     * Mesma razão: sem tipo sem sinal no PostgreSQL, o UNSIGNED seria descartado na
     * renderização e reapareceria como diferença em cada introspecção.
     */
    public function testUnsignedIsRejectedInsteadOfSilentlyDiscarded(): void
    {
        $reason = (string) $this->pgsql()->checkType(TypeSpec::parse('INT UNSIGNED'));

        $this->assertStringContainsString('UNSIGNED', $reason);
        $this->expectException(UnsupportedTypeException::class);
        $this->pgsql()->renderType(TypeSpec::parse('BIGINT UNSIGNED'));
    }

    /** REAL e DOUBLE PRECISION não têm precisão declarável no PostgreSQL. */
    public function testPrecisionIsOnlyRenderedForNumeric(): void
    {
        $this->assertSame('DOUBLE PRECISION', $this->pgsql()->renderType(TypeSpec::parse('DOUBLE', '10,2')));
        $this->assertSame('NUMERIC(10,2)', $this->pgsql()->renderType(TypeSpec::parse('DECIMAL', '10,2')));
    }

    // ------------------------------------------------------------- operações

    /**
     * Comentário aqui não é atributo de coluna, é comando à parte — e é exatamente
     * o que o sistema antigo não fazia. A DDL vinha montada com o `COMMENT '...'`
     * inline do MySQL e o driver pgsql descartava a parte que não reconhecia, junto
     * com o tamanho da coluna: todo `VARCHAR(120)` virava `varchar` sem limite.
     */
    public function testCreateTableEmitsCommentsAsSeparateStatements(): void
    {
        $statements = $this->pgsql()->compile(new CreateTable(Ops::productTable()));

        $this->assertCount(3, $statements);

        $this->assertSqlEquals(
            'CREATE TABLE "product" ('
            . ' "id" INTEGER GENERATED BY DEFAULT AS IDENTITY NOT NULL,'
            . ' "name" VARCHAR(120) NOT NULL,'
            . ' "price" NUMERIC(10,2) NOT NULL DEFAULT 0,'
            . ' "active" BOOLEAN NOT NULL DEFAULT true,'
            . ' "description" TEXT,'
            . ' "created_at" TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,'
            . ' "category" INTEGER,'
            . ' CONSTRAINT "product_pk" PRIMARY KEY ("id")'
            . ' )',
            $statements[0],
        );

        $this->assertSqlEquals('COMMENT ON TABLE "product" IS \'Catálogo de produtos\'', $statements[1]);
        $this->assertSqlEquals('COMMENT ON COLUMN "product"."name" IS \'Nome do produto\'', $statements[2]);
    }

    /**
     * O tamanho declarado chega ao SQL. É a regressão direta de B7, e é o teste que
     * falharia contra o gerador antigo em toda coluna VARCHAR dos dez models.
     */
    public function testColumnLengthReachesTheGeneratedSql(): void
    {
        $sql = $this->pgsql()->compile(new CreateTable(Ops::productTable()))[0];

        $this->assertStringContainsString('VARCHAR(120)', $sql);
        $this->assertStringNotContainsString('VARCHAR,', $sql);
    }

    /**
     * `CONSTRAINT nome` explícito, sempre: o PostgreSQL guarda o nome, e omiti-lo
     * faria o banco inventar `product_pkey`, que não é o nome que o
     * `ConstraintNamer` registra no snapshot.
     */
    public function testThePrimaryKeyIsNamedBecauseTheEngineKeepsTheName(): void
    {
        $sql = $this->pgsql()->compile(new CreateTable(Ops::productTable()))[0];

        $this->assertStringContainsString('CONSTRAINT "product_pk" PRIMARY KEY ("id")', $sql);
    }

    /**
     * `BY DEFAULT`, nunca `ALWAYS`. Com ALWAYS o banco recusa INSERT que informe o
     * id explicitamente, o que quebraria import, seed com id fixo e qualquer teste
     * que fixe chaves — que é justamente o que o gerador antigo fazia.
     */
    public function testIdentityIsByDefaultSoExplicitIdsStillWork(): void
    {
        $sql = $this->pgsql()->compile(new CreateTable(Ops::productTable()))[0];

        $this->assertStringContainsString('GENERATED BY DEFAULT AS IDENTITY', $sql);
        $this->assertStringNotContainsString('ALWAYS', $sql);
    }

    public function testBooleanLiteralsAreKeywords(): void
    {
        $this->assertSame('true', $this->pgsql()->quoteLiteral(true));
        $this->assertSame('false', $this->pgsql()->quoteLiteral(false));
    }

    /**
     * Com `standard_conforming_strings` (padrão desde a 9.1) a barra invertida não é
     * especial dentro de uma string comum, então dobrar a aspa é todo o escape
     * necessário — ao contrário do MySQL.
     */
    public function testOnlyTheQuoteIsEscaped(): void
    {
        $this->assertSame("'a\\b'", $this->pgsql()->quoteLiteral('a\\b'));
        $this->assertSame("'d''água'", $this->pgsql()->quoteLiteral("d'água"));
    }

    public function testAddColumnEmitsTheCommentSeparately(): void
    {
        $statements = $this->pgsql()->compile(new AddColumn(
            'product',
            Ir::column('sku', 'VARCHAR', 32, notNull: true, comment: 'Código'),
        ));

        $this->assertSqlListEquals(
            [
                'ALTER TABLE "product" ADD COLUMN "sku" VARCHAR(32) NOT NULL',
                'COMMENT ON COLUMN "product"."sku" IS \'Código\'',
            ],
            $statements,
        );
    }

    /**
     * A ordem não é arbitrária: um default que não converte para o tipo novo
     * bloqueia a conversão inteira com "default for column cannot be cast
     * automatically". Tirar o default antes e recolocá-lo depois é o que faz
     * VARCHAR para INTEGER caber numa migração só.
     */
    public function testAlterColumnDropsTheDefaultBeforeChangingTypeAndRestoresItAfter(): void
    {
        $operation = new AlterColumn(
            'product',
            Ir::column('code', 'VARCHAR', 10, notNull: true, default: DefaultValue::literal('0')),
            Ir::column('code', 'INT', notNull: true, default: DefaultValue::literal(0)),
        );

        $this->assertSqlListEquals(
            [
                'ALTER TABLE "product" ALTER COLUMN "code" DROP DEFAULT',
                'ALTER TABLE "product" ALTER COLUMN "code" TYPE INTEGER USING "code"::INTEGER',
                'ALTER TABLE "product" ALTER COLUMN "code" SET DEFAULT 0',
            ],
            $this->pgsql()->compile($operation),
        );
    }

    /**
     * USING explícito sempre: sem ele o PostgreSQL só aceita as conversões que
     * considera implícitas, e quais são varia por par de tipos. Um caminho único é
     * um caminho testável.
     */
    public function testTypeChangesAlwaysCarryAnExplicitUsingCast(): void
    {
        $operation = new AlterColumn(
            'product',
            Ir::column('name', 'VARCHAR', 120, notNull: true),
            Ir::column('name', 'VARCHAR', 200, notNull: true),
        );

        $this->assertSingleSql(
            'ALTER TABLE "product" ALTER COLUMN "name" TYPE VARCHAR(200) USING "name"::VARCHAR(200)',
            $this->pgsql()->compile($operation),
        );
    }

    /** Aqui uma mudança só de comentário nem chega a ser um ALTER TABLE. */
    public function testACommentOnlyChangeIsNotAnAlterTable(): void
    {
        $operation = new AlterColumn(
            'product',
            Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'antes'),
            Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'depois'),
        );

        $this->assertSingleSql(
            'COMMENT ON COLUMN "product"."name" IS \'depois\'',
            $this->pgsql()->compile($operation),
        );
    }

    public function testEachAspectBecomesItsOwnStatement(): void
    {
        $operation = new AlterColumn(
            'product',
            Ir::column('description', 'TEXT', notNull: true),
            Ir::column('description', 'TEXT', comment: 'Descrição'),
        );

        $this->assertSqlListEquals(
            [
                'ALTER TABLE "product" ALTER COLUMN "description" DROP NOT NULL',
                'COMMENT ON COLUMN "product"."description" IS \'Descrição\'',
            ],
            $this->pgsql()->compile($operation),
        );
    }

    public function testRemovingADefaultIsDropDefault(): void
    {
        $operation = new AlterColumn(
            'product',
            Ir::column('price', 'DECIMAL', '10,2', notNull: true, default: DefaultValue::literal(0)),
            Ir::column('price', 'DECIMAL', '10,2', notNull: true),
        );

        $this->assertSingleSql(
            'ALTER TABLE "product" ALTER COLUMN "price" DROP DEFAULT',
            $this->pgsql()->compile($operation),
        );
    }

    /**
     * `DEFAULT NULL` e "sem cláusula DEFAULT" são estados diferentes no catálogo, e
     * distingui-los é o ponto de `DefaultKind`: sem isso o differ não conseguiria
     * expressar a remoção de um default.
     */
    public function testExplicitNullDefaultIsNotTheSameAsNoDefault(): void
    {
        $withNull = $this->pgsql()->compile(new AddColumn(
            'product',
            Ir::column('note', 'VARCHAR', 80, default: DefaultValue::null()),
        ));

        $withNone = $this->pgsql()->compile(new AddColumn('product', Ir::column('note', 'VARCHAR', 80)));

        $this->assertStringContainsString('DEFAULT NULL', $withNull[0]);
        $this->assertStringNotContainsString('DEFAULT', $withNone[0]);
    }

    public function testIdentityIsAddedAndDroppedAsItsOwnStatement(): void
    {
        $plain = Ir::column('id', 'INT', notNull: true);
        $identity = Ir::column('id', 'INT', notNull: true, autoIncrement: true);

        $this->assertSingleSql(
            'ALTER TABLE "product" ALTER COLUMN "id" ADD GENERATED BY DEFAULT AS IDENTITY',
            $this->pgsql()->compile(new AlterColumn('product', $plain, $identity)),
        );

        $this->assertSingleSql(
            'ALTER TABLE "product" ALTER COLUMN "id" DROP IDENTITY',
            $this->pgsql()->compile(new AlterColumn('product', $identity, $plain)),
        );
    }

    /** Aqui identity e chave primária são independentes: nada a desfazer antes. */
    public function testDroppingAPrimaryKeyIsASingleDropConstraintEvenWithIdentity(): void
    {
        $this->assertSingleSql(
            'ALTER TABLE "product" DROP CONSTRAINT "product_pk"',
            $this->pgsql()->compile(new DropPrimaryKey('product', 'product_pk', Ir::id())),
        );
    }

    public function testEverythingNamedIsDroppedAsAConstraint(): void
    {
        $this->assertSingleSql(
            'ALTER TABLE "product" DROP CONSTRAINT "product_sku_unique"',
            $this->pgsql()->compile(new DropUniqueConstraint('product', 'product_sku_unique')),
        );
    }

    /** Índice é objeto de schema, não de tabela: DROP INDEX não menciona a tabela. */
    public function testDropIndexDoesNotMentionTheTable(): void
    {
        $this->assertSingleSql(
            'DROP INDEX "product_category_index"',
            $this->pgsql()->compile(new DropIndex('product', 'product_category_index')),
        );
    }

    /** No PostgreSQL o USING vem depois da tabela; no MySQL, depois do nome. */
    public function testIndexMethodComesAfterTheTable(): void
    {
        $operation = new CreateIndex(
            'product',
            new IndexDefinition('product_payload_index', ['payload'], method: 'GIN'),
        );

        $this->assertSingleSql(
            'CREATE INDEX "product_payload_index" ON "product" USING GIN ("payload")',
            $this->pgsql()->compile($operation),
        );
    }

    public function testRemovingATableCommentSetsItToNull(): void
    {
        $this->assertSingleSql(
            'COMMENT ON TABLE "product" IS NULL',
            $this->pgsql()->compile(new SetTableComment('product', null)),
        );
    }

    /**
     * Engine e collation de tabela não existem aqui. Lista vazia é a resposta
     * correta, não um buraco: o IR carrega os dois campos porque o MySQL os tem.
     */
    public function testTableOptionsCompileToNothing(): void
    {
        $this->assertSame(
            [],
            $this->pgsql()->compile(new SetTableOptions(
                'product',
                new TableOptions(engine: 'InnoDB', collation: 'utf8mb4_bin'),
            )),
        );
    }

    /**
     * Collation por coluna existe no PostgreSQL, mas os nomes dependem do locale do
     * sistema operacional (`pt_BR.utf8`, `de-DE-x-icu`) e nunca fechariam o
     * round-trip entre máquinas. O SchemaValidator recusa antes; isto é a rede.
     */
    public function testPerColumnCollationIsRefusedRatherThanDroppedSilently(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/collation por coluna/');

        $this->pgsql()->compile(new AddColumn(
            'product',
            Ir::column('name', 'VARCHAR', 60, collation: 'utf8mb4_bin'),
        ));
    }

    /** Identity e DEFAULT são mutuamente exclusivos aqui. */
    public function testAnIdentityColumnWithADefaultIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/identity não pode ter DEFAULT/');

        $this->pgsql()->compile(new AddColumn('product', Ir::column(
            'id',
            'INT',
            notNull: true,
            autoIncrement: true,
            default: DefaultValue::literal(1),
        )));
    }

    // ------------------------------------------------------------------- DML

    /**
     * As duas cláusulas são independentes: OFFSET sozinho é válido aqui, ao
     * contrário do MySQL, que precisa de um limite sentinela.
     */
    public function testPaginationClausesAreIndependent(): void
    {
        $this->assertSame('LIMIT :lim OFFSET :off', $this->pgsql()->limitOffsetClause(':lim', ':off'));
        $this->assertSame('LIMIT :lim', $this->pgsql()->limitOffsetClause(':lim', null));
        $this->assertSame('OFFSET :off', $this->pgsql()->limitOffsetClause(null, ':off'));
    }

    public function testReturningIsAvailableEverywhere(): void
    {
        $this->assertTrue($this->pgsql()->supportsReturning());
        $this->assertTrue($this->pgsql()->supportsReturningOnModify());
    }

    public function testCaseInsensitiveLikeUsesIlike(): void
    {
        $this->assertSame('"nome" ILIKE :p0', $this->pgsql()->caseInsensitiveLike('"nome"', ':p0'));
    }

    public function testSavepointNamesAreQuotedWithDoubleQuotes(): void
    {
        $this->assertSame('SAVEPOINT "neoorm_sp1"', $this->pgsql()->savepoint(1));
        $this->assertSame('RELEASE SAVEPOINT "neoorm_sp1"', $this->pgsql()->releaseSavepoint(1));
        $this->assertSame('ROLLBACK TO SAVEPOINT "neoorm_sp1"', $this->pgsql()->rollbackToSavepoint(1));
    }
}
