<?php

declare(strict_types=1);

namespace Tests\Unit\Builder;

use Diogodg\Neoorm\Definitions\Raw;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\App\Models\Country;
use Tests\App\SchemaModels\Registry\HasNoTableMethod;
use Tests\Support\UnitTestCase;

/**
 * O builder de coluna.
 *
 * O que mudou em relação à `Column` é onde o erro aparece. `new Column('c', 'VARCAHR')`
 * só quebrava no `build()`, longe da linha errada; `Col::varchar(120)` não tem como ser
 * escrito com o tipo errado. As fábricas existem para isso, e `Col::type()` fica como via
 * de escape para o que elas não cobrem.
 */
final class ColTest extends UnitTestCase
{
    // ------------------------------------------------------------------ tipos

    /**
     * @param callable():Col $factory
     */
    #[DataProvider('typeFactories')]
    public function testEachFactoryProducesTheExpectedSignature(callable $factory, string $signature): void
    {
        $this->assertSame($signature, $factory()->build('c')->type->signature());
    }

    /**
     * @return iterable<string,array{callable():Col,string}>
     */
    public static function typeFactories(): iterable
    {
        yield 'tinyInt' => [static fn (): Col => Col::tinyInt(), 'TINYINT'];
        yield 'smallInt' => [static fn (): Col => Col::smallInt(), 'SMALLINT'];
        yield 'mediumInt' => [static fn (): Col => Col::mediumInt(), 'MEDIUMINT'];
        yield 'int' => [static fn (): Col => Col::int(), 'INT'];
        yield 'bigInt' => [static fn (): Col => Col::bigInt(), 'BIGINT'];
        yield 'decimal' => [static fn (): Col => Col::decimal(19, 4), 'DECIMAL(19,4)'];
        yield 'decimal sem escala' => [static fn (): Col => Col::decimal(10), 'DECIMAL(10,0)'];
        yield 'float' => [static fn (): Col => Col::float(), 'FLOAT'];
        yield 'double' => [static fn (): Col => Col::double(), 'DOUBLE'];
        yield 'boolean' => [static fn (): Col => Col::boolean(), 'BOOLEAN'];
        yield 'char' => [static fn (): Col => Col::char(2), 'CHAR(2)'];
        yield 'varchar' => [static fn (): Col => Col::varchar(120), 'VARCHAR(120)'];
        yield 'tinyText' => [static fn (): Col => Col::tinyText(), 'TINYTEXT'];
        yield 'text' => [static fn (): Col => Col::text(), 'TEXT'];
        yield 'mediumText' => [static fn (): Col => Col::mediumText(), 'MEDIUMTEXT'];
        yield 'longText' => [static fn (): Col => Col::longText(), 'LONGTEXT'];
        yield 'date' => [static fn (): Col => Col::date(), 'DATE'];
        yield 'time' => [static fn (): Col => Col::time(), 'TIME'];
        yield 'dateTime' => [static fn (): Col => Col::dateTime(), 'DATETIME'];
        yield 'timestamp' => [static fn (): Col => Col::timestamp(), 'TIMESTAMP'];
        yield 'timestampTz' => [static fn (): Col => Col::timestampTz(), 'TIMESTAMPTZ'];
        yield 'year' => [static fn (): Col => Col::year(), 'YEAR'];
        yield 'json' => [static fn (): Col => Col::json(), 'JSON'];
        yield 'jsonb' => [static fn (): Col => Col::jsonb(), 'JSONB'];
        yield 'uuid' => [static fn (): Col => Col::uuid(), 'UUID'];
        yield 'binary' => [static fn (): Col => Col::binary(16), 'BINARY(16)'];
        yield 'varBinary' => [static fn (): Col => Col::varBinary(64), 'VARBINARY(64)'];
        yield 'tinyBlob' => [static fn (): Col => Col::tinyBlob(), 'TINYBLOB'];
        yield 'blob' => [static fn (): Col => Col::blob(), 'BLOB'];
        yield 'mediumBlob' => [static fn (): Col => Col::mediumBlob(), 'MEDIUMBLOB'];
        yield 'longBlob' => [static fn (): Col => Col::longBlob(), 'LONGBLOB'];
        yield 'bytea' => [static fn (): Col => Col::bytea(), 'BYTEA'];
        yield 'enum' => [static fn (): Col => Col::enum(['on', 'off']), 'ENUM(on,off)'];
        yield 'unsigned' => [static fn (): Col => Col::int()->unsigned(), 'INT UNSIGNED'];
    }

    /**
     * A via de escape aceita a gramática do DSL antigo, inclusive o tamanho embutido.
     */
    #[DataProvider('escapeHatchCases')]
    public function testTypeIsTheEscapeHatch(string $type, string|int|null $size, string $signature): void
    {
        $this->assertSame($signature, Col::type($type, $size)->build('c')->type->signature());
    }

    /**
     * @return iterable<string,array{string,string|int|null,string}>
     */
    public static function escapeHatchCases(): iterable
    {
        yield 'tamanho separado' => ['VARCHAR', 120, 'VARCHAR(120)'];
        yield 'tamanho embutido' => ['VARCHAR(120)', null, 'VARCHAR(120)'];
        yield 'precisão e escala' => ['DECIMAL', '10,2', 'DECIMAL(10,2)'];
        yield 'alias de dialeto' => ['INTEGER', null, 'INT'];
        yield 'unsigned como sufixo' => ['INT UNSIGNED', null, 'INT UNSIGNED'];
    }

    /** Largura de exibição de inteiro não faz parte da identidade do tipo. */
    public function testIntegerDisplayWidthIsDropped(): void
    {
        $this->assertSame('INT', Col::type('INT', 11)->build('c')->type->signature());
    }

    // ---------------------------------------------------------------- atalhos

    public function testIdIsPrimaryAutoIncrement(): void
    {
        $column = Col::id()->build('id');

        $this->assertSame(TypeName::Int, $column->type->name);
        $this->assertTrue($column->autoIncrement);
        $this->assertTrue($column->notNull);
    }

    public function testBigIdIsTheSameThingInBigInt(): void
    {
        $this->assertSame(TypeName::BigInt, Col::bigId()->build('id')->type->name);
    }

    public function testFkIsAnIntNotNullReference(): void
    {
        $table = Table::make('t')
            ->columns(['id' => Col::id(), 'country' => Col::fk(Country::class)])
            ->build();

        $this->assertTrue($table->column('country')?->notNull);
        $this->assertSame('country', array_values($table->foreignKeys)[0]->referencedTable);
    }

    // ----------------------------------------------------------- modificadores

    /** Chave primária é NOT NULL por definição nos dois bancos. */
    public function testPrimaryImpliesNotNull(): void
    {
        $this->assertTrue(Col::int()->primary()->build('id')->notNull);
    }

    /** `nullable()` desfaz o `notNull()` de uma coluna usada como molde. */
    public function testNullableUndoesNotNull(): void
    {
        $this->assertFalse(Col::varchar(20)->notNull()->nullable()->build('c')->notNull);
    }

    /**
     * `Col` é IMUTÁVEL: um modificador devolve outra instância.
     *
     * É o que torna seguro reusar uma coluna como molde — com o builder mutável de antes,
     * `$molde->comment('x')` mudaria também a coluna já declarada com ele.
     */
    public function testModifiersDoNotMutateTheOriginal(): void
    {
        $molde = Col::decimal(19, 4)->notNull();
        $comComentario = $molde->comment('Limite');

        $this->assertNotSame($molde, $comComentario);
        $this->assertNull($molde->build('saldo')->comment);
        $this->assertSame('Limite', $comComentario->build('limite')->comment);
    }

    /** A mesma instância pode nomear duas colunas, e cada uma sai com o próprio nome. */
    public function testOneInstanceCanNameTwoColumns(): void
    {
        $dinheiro = Col::decimal(19, 4)->notNull();

        $table = Table::make('t')
            ->columns(['id' => Col::id(), 'saldo' => $dinheiro, 'limite' => $dinheiro])
            ->build();

        $this->assertSame(['id', 'saldo', 'limite'], $table->getColumnNames());
        $this->assertSame('DECIMAL(19,4)', $table->column('limite')?->type->signature());
    }

    /**
     * O comentário é TEXTO, não fragmento de SQL.
     *
     * Antes guardava `COMMENT 'City name'` já montado, o que fazia o PostgreSQL descartá-lo
     * inteiro (ele usa `COMMENT ON COLUMN`, comando separado) e fazia o PHPDoc gerado sair
     * como `@property string $name COMMENT 'City name'`.
     */
    public function testTheCommentIsPlainText(): void
    {
        $this->assertSame('City ID', Col::int()->comment('City ID')->build('id')->comment);
        $this->assertNull(Col::int()->comment('')->build('id')->comment);
    }

    public function testCollationIsKept(): void
    {
        $this->assertSame('utf8mb4_bin', Col::varchar(20)->collation('utf8mb4_bin')->build('c')->collation);
    }

    // ---------------------------------------------------------------- defaults

    #[DataProvider('defaultCases')]
    public function testDefaultKeepsItsMeaning(
        Raw|string|int|float|bool|null $value,
        DefaultValue $expected,
    ): void {
        $column = Col::varchar(20)->default($value)->build('c');

        $this->assertTrue($expected->equals($column->default), 'kind ou valor divergiu');
        $this->assertSame($expected->kind, $column->default->kind);
    }

    /**
     * @return iterable<string,array{Raw|string|int|float|bool|null,DefaultValue}>
     */
    public static function defaultCases(): iterable
    {
        yield 'texto' => ['scheduled', DefaultValue::literal('scheduled')];
        yield 'inteiro' => [0, DefaultValue::literal(0)];
        yield 'booleano' => [true, DefaultValue::literal(true)];
        yield 'string vazia' => ['', DefaultValue::literal('')];
        yield 'Raw' => [new Raw('CURRENT_TIMESTAMP'), DefaultValue::expression('CURRENT_TIMESTAMP')];

        // Distinto de "sem cláusula DEFAULT", que é o estado inicial.
        yield 'null explícito' => [null, DefaultValue::null()];
    }

    public function testNoDefaultIsDistinctFromDefaultNull(): void
    {
        $none = Col::varchar(20)->build('c');
        $null = Col::varchar(20)->defaultNull()->build('c');

        $this->assertTrue($none->default->isNone());
        $this->assertTrue($null->default->isNull());
        $this->assertFalse($none->default->equals($null->default));
    }

    public function testDefaultRawIsAnExpression(): void
    {
        $column = Col::timestamp()->defaultRaw('CURRENT_TIMESTAMP')->build('c');

        $this->assertTrue($column->default->isExpression());
        $this->assertSame('CURRENT_TIMESTAMP', $column->default->value);
    }

    // -------------------------------------------------------------- references

    /**
     * `references(Model::class)` lê a constante `table` do model.
     *
     * É o ganho sobre `Country::table`: a classe é verificável pela IDE e pelo PHPStan,
     * a string não.
     */
    public function testReferencesResolvesAModelClassToItsTableName(): void
    {
        $table = Table::make('state')
            ->columns([
                'id' => Col::id(),
                'country' => Col::int()->notNull()->references(Country::class),
            ])
            ->build();

        $this->assertSame('country', array_values($table->foreignKeys)[0]->referencedTable);
    }

    /** Uma string que não é classe passa adiante como nome de tabela. */
    public function testReferencesAcceptsARawTableName(): void
    {
        $table = Table::make('t')
            ->columns(['id' => Col::id(), 'ref' => Col::int()->references('tabela_legada')])
            ->build();

        $this->assertSame('tabela_legada', array_values($table->foreignKeys)[0]->referencedTable);
    }

    /** Uma classe existente SEM `const table` é erro de digitação, não nome de tabela. */
    public function testReferencesRefusesAClassWithoutATableConstant(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/constante \'table\'/');

        Col::int()->references(ColTest::class);
    }

    /** A constante existe, mas está vazia: é o default de `Model`, e não nomeia tabela. */
    public function testReferencesRefusesAnEmptyTableConstant(): void
    {
        $this->assertSame('has_no_table_method', HasNoTableMethod::table);

        $table = Table::make('t')
            ->columns(['id' => Col::id(), 'ref' => Col::int()->references(HasNoTableMethod::class)])
            ->build();

        $this->assertSame('has_no_table_method', array_values($table->foreignKeys)[0]->referencedTable);
    }

}
