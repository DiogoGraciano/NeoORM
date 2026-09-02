<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use Diogodg\Neoorm\Schema\SchemaValidator;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Factory\Ir;
use Tests\Support\UnitTestCase;

/**
 * O validador é o oposto direto do `try { } catch { /* ignora *\/ }` que o
 * sistema antigo usava: nada é engolido, e cada problema aponta a tabela e a
 * coluna. Também é o que permite ao IR ser neutro de dialeto — a checagem de
 * "este banco suporta este tipo?" acontece aqui, não dentro do construtor de
 * Column, que era onde declarar uma coluna JSONB explodia só porque o DRIVER
 * configurado no ambiente calhava de ser mysql.
 */
final class SchemaValidatorTest extends UnitTestCase
{
    private SchemaValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new SchemaValidator();
    }

    public static function dialects(): iterable
    {
        yield 'mysql' => ['mysql'];
        yield 'pgsql' => ['pgsql'];
    }

    #[DataProvider('dialects')]
    public function testARealisticSchemaValidatesCleanOnBothDialects(string $dialect): void
    {
        $this->assertSame([], $this->validator->validate(Ir::geographySchema(), $dialect));
    }

    /**
     * Todos os problemas de uma vez, não o primeiro. Quem roda `generate` quer
     * a lista para corrigir de uma vez, e não um erro por execução.
     */
    public function testEveryProblemIsReportedNotJustTheFirst(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [
                Ir::id(),
                Ir::column('a', 'INT', notNull: true, default: DefaultValue::null()),
                Ir::column('b', 'INT', notNull: true, default: DefaultValue::null()),
            ]),
        ]);

        $errors = $this->validator->validate($schema, 'pgsql');

        $this->assertCount(2, $errors);
    }

    public function testMessagesNameTheTableAndTheColumn(): void
    {
        $schema = Ir::schema([
            Ir::table('pedido', [
                Ir::id(),
                Ir::column('valor', 'INT', notNull: true, default: DefaultValue::null()),
            ]),
        ]);

        $errors = $this->validator->validate($schema, 'pgsql');

        $this->assertStringContainsString('pedido.valor', $errors[0]);
    }

    // ------------------------------------------------------------- colunas

    public function testNotNullColumnWithDefaultNullIsRejected(): void
    {
        // Aceito em silêncio antes, e sem efeito nenhum: sendo NOT NULL, o
        // default NULL nunca poderia ser aplicado.
        $schema = Ir::schema([
            Ir::table('t', [Ir::id(), Ir::column('a', 'INT', notNull: true, default: DefaultValue::null())]),
        ]);

        $errors = $this->validator->validate($schema, 'pgsql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('DEFAULT NULL', $errors[0]);
    }

    public function testAutoIncrementOnANonIntegerColumnIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [
                Ir::column('codigo', 'VARCHAR', 20, notNull: true, autoIncrement: true),
            ], primaryKey: ['codigo']),
        ]);

        $errors = $this->validator->validate($schema, 'mysql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('auto incremento exige tipo inteiro', $errors[0]);
    }

    public function testAutoIncrementOutsideThePrimaryKeyIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [
                Ir::column('id', 'INT', notNull: true),
                Ir::column('seq', 'INT', notNull: true, autoIncrement: true),
            ]),
        ]);

        $errors = $this->validator->validate($schema, 'mysql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('chave primária', $errors[0]);
    }

    public function testTwoAutoIncrementColumnsAreRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [
                Ir::column('a', 'INT', notNull: true, autoIncrement: true),
                Ir::column('b', 'INT', notNull: true, autoIncrement: true),
            ], primaryKey: ['a', 'b']),
        ]);

        $errors = $this->validator->validate($schema, 'mysql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('mais de uma coluna com auto incremento', $errors[0]);
    }

    /**
     * Coluna de PK é NOT NULL por definição nos dois bancos. Deixar o IR
     * discordar disso faria a coluna parecer alterada em todo round-trip, já
     * que a introspecção sempre devolve NOT NULL.
     */
    public function testNullablePrimaryKeyColumnIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [Ir::column('id', 'INT')]),
        ]);

        $errors = $this->validator->validate($schema, 'pgsql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('NOT NULL', $errors[0]);
    }

    // ------------------------------------------------------ tipos e dialeto

    #[DataProvider('typesUnsupportedByDialect')]
    public function testTypesUnsupportedByADialectAreReportedWithAnAlternative(
        string $type,
        string $dialect,
        string $expectedHint,
    ): void {
        $schema = Ir::schema([
            Ir::table('t', [Ir::id(), Ir::column('campo', $type)]),
        ]);

        $errors = $this->validator->validate($schema, $dialect);

        $this->assertNotEmpty($errors, "{$type} deveria ser recusado em {$dialect}");
        $this->assertStringContainsString($expectedHint, $errors[0]);
    }

    public static function typesUnsupportedByDialect(): iterable
    {
        yield 'jsonb no mysql' => ['JSONB', 'mysql', 'JSON'];
        yield 'uuid no mysql' => ['UUID', 'mysql', 'CHAR(36)'];
        yield 'bytea no mysql' => ['BYTEA', 'mysql', 'BLOB'];
        yield 'timestamptz no mysql' => ['TIMESTAMPTZ', 'mysql', 'TIMESTAMP'];
        yield 'tinyint no pgsql' => ['TINYINT', 'pgsql', 'SMALLINT'];
        yield 'mediumtext no pgsql' => ['MEDIUMTEXT', 'pgsql', 'TEXT'];
        yield 'blob no pgsql' => ['BLOB', 'pgsql', 'BYTEA'];
        yield 'enum no pgsql' => ["ENUM('a','b')", 'pgsql', 'CHECK'];
    }

    #[DataProvider('typesSupportedByBoth')]
    public function testTypesCommonToBothDialectsPassOnBoth(string $type, string|int|null $size): void
    {
        $schema = Ir::schema([
            Ir::table('t', [Ir::id(), Ir::column('campo', $type, $size)]),
        ]);

        $this->assertSame([], $this->validator->validate($schema, 'mysql'), "{$type} no mysql");
        $this->assertSame([], $this->validator->validate($schema, 'pgsql'), "{$type} no pgsql");
    }

    public static function typesSupportedByBoth(): iterable
    {
        yield 'int' => ['INT', null];
        yield 'bigint' => ['BIGINT', null];
        yield 'smallint' => ['SMALLINT', null];
        yield 'varchar' => ['VARCHAR', 120];
        yield 'char' => ['CHAR', 2];
        yield 'text' => ['TEXT', null];
        yield 'decimal' => ['DECIMAL', '10,2'];
        yield 'boolean' => ['BOOLEAN', null];
        yield 'date' => ['DATE', null];
        yield 'time' => ['TIME', null];
        yield 'timestamp' => ['TIMESTAMP', null];
        yield 'json' => ['JSON', null];
    }

    public function testVarcharWithoutLengthIsRejectedOnMysqlAndAllowedOnPostgres(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [Ir::id(), Ir::column('campo', 'VARCHAR')]),
        ]);

        $this->assertNotEmpty($this->validator->validate($schema, 'mysql'));
        $this->assertSame([], $this->validator->validate($schema, 'pgsql'));
    }

    public function testLengthBeyondTheDialectLimitIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [Ir::id(), Ir::column('campo', 'CHAR', 300)]),
        ]);

        $errors = $this->validator->validate($schema, 'mysql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('excede o máximo', $errors[0]);
    }

    public function testPerColumnCollationIsRejectedOutsideMysql(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [Ir::id(), Ir::column('campo', 'VARCHAR', 10, collation: 'utf8mb4_bin')]),
        ]);

        $this->assertSame([], $this->validator->validate($schema, 'mysql'));
        $this->assertNotEmpty($this->validator->validate($schema, 'pgsql'));
    }

    // ------------------------------------------------------- referências

    /**
     * A validação acontece sobre o schema pronto, não no momento da chamada.
     * É isso que torna a ordem das chamadas do builder irrelevante — antes,
     * `index()` só funcionava se viesse depois das colunas.
     */
    public function testIndexOnAMissingColumnIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [Ir::id()], indexes: [
                new \Diogodg\Neoorm\Schema\IndexDefinition('t_fantasma_index', ['fantasma']),
            ]),
        ]);

        $errors = $this->validator->validate($schema, 'pgsql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('fantasma', $errors[0]);
    }

    public function testUniqueConstraintOnAMissingColumnIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [Ir::id()], uniques: [
                new \Diogodg\Neoorm\Schema\UniqueConstraintDefinition('t_fantasma_unique', ['fantasma']),
            ]),
        ]);

        $this->assertNotEmpty($this->validator->validate($schema, 'pgsql'));
    }

    public function testPrimaryKeyOnAMissingColumnIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('t', [Ir::column('outra', 'INT', notNull: true)], primaryKey: ['fantasma']),
        ]);

        $errors = $this->validator->validate($schema, 'pgsql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('fantasma', $errors[0]);
    }

    public function testForeignKeyToAMissingTableIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('city', [Ir::id(), Ir::column('state', 'INT')], foreignKeys: [
                Ir::foreignKey('city', ['state'], 'state'),
            ]),
        ]);

        $errors = $this->validator->validate($schema, 'pgsql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString("'state'", $errors[0]);
    }

    public function testForeignKeyToAMissingColumnIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('state', [Ir::id()]),
            Ir::table('city', [Ir::id(), Ir::column('state', 'INT')], foreignKeys: [
                Ir::foreignKey('city', ['state'], 'state', ['codigo']),
            ]),
        ]);

        $errors = $this->validator->validate($schema, 'pgsql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('state.codigo', $errors[0]);
    }

    /**
     * ON DELETE SET NULL numa coluna NOT NULL só falha quando a linha pai é
     * removida — em produção, muito depois de a migração ter passado.
     */
    public function testSetNullOnANotNullColumnIsRejected(): void
    {
        $schema = Ir::schema([
            Ir::table('categoria', [Ir::id()]),
            Ir::table('produto', [Ir::id(), Ir::column('categoria_id', 'INT', notNull: true)], foreignKeys: [
                Ir::foreignKey('produto', ['categoria_id'], 'categoria', ['id'], ReferentialAction::SetNull),
            ]),
        ]);

        $errors = $this->validator->validate($schema, 'pgsql');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('SET NULL', $errors[0]);
    }

    public function testSelfReferencingForeignKeyIsValid(): void
    {
        $schema = Ir::schema([
            Ir::table('categoria', [Ir::id(), Ir::column('parent_id', 'INT')], foreignKeys: [
                Ir::foreignKey('categoria', ['parent_id'], 'categoria', ['id'], ReferentialAction::SetNull),
            ]),
        ]);

        $this->assertSame([], $this->validator->validate($schema, 'pgsql'));
    }

    public function testMutuallyReferencingForeignKeysAreValid(): void
    {
        // FK mútua é SQL legítimo; o que resolve isso é a ordenação das
        // operações (tabelas primeiro, FKs depois), não uma proibição aqui.
        $schema = Ir::schema([
            Ir::table('a', [Ir::id(), Ir::column('b_id', 'INT')], foreignKeys: [
                Ir::foreignKey('a', ['b_id'], 'b'),
            ]),
            Ir::table('b', [Ir::id(), Ir::column('a_id', 'INT')], foreignKeys: [
                Ir::foreignKey('b', ['a_id'], 'a'),
            ]),
        ]);

        $this->assertSame([], $this->validator->validate($schema, 'pgsql'));
    }

    public function testCompositePrimaryKeyWithoutAutoIncrementIsValid(): void
    {
        $schema = Ir::schema([
            Ir::table('produto', [Ir::id()]),
            Ir::table('categoria', [Ir::id()]),
            Ir::table('produto_categoria', [
                Ir::column('produto_id', 'INT', notNull: true),
                Ir::column('categoria_id', 'INT', notNull: true),
            ],
                primaryKey: ['produto_id', 'categoria_id'],
                foreignKeys: [
                    Ir::foreignKey('produto_categoria', ['produto_id'], 'produto', ['id'], ReferentialAction::Cascade),
                    Ir::foreignKey('produto_categoria', ['categoria_id'], 'categoria', ['id'], ReferentialAction::Cascade),
                ],
            ),
        ]);

        $this->assertSame([], $this->validator->validate($schema, 'mysql'));
        $this->assertSame([], $this->validator->validate($schema, 'pgsql'));
    }

    public function testAnUnknownDialectIsReportedInsteadOfSilentlyPassing(): void
    {
        $errors = $this->validator->validate(Ir::geographySchema(), 'sqlite');

        $this->assertNotEmpty($errors);
        $this->assertStringContainsString('sqlite', $errors[0]);
    }
}
