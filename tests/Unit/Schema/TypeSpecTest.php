<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use Diogodg\Neoorm\Schema\Exception\InvalidTypeException;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnitTestCase;

final class TypeSpecTest extends UnitTestCase
{
    /**
     * O DSL dos models passa (tipo, tamanho) separados; os catálogos dos bancos
     * devolvem o tamanho embutido no tipo. As duas formas têm que produzir
     * exatamente a mesma assinatura, senão o round-trip nunca fecha.
     */
    #[DataProvider('parsingCases')]
    public function testParsingProducesTheExpectedSignature(string $type, string|int|null $size, string $expected): void
    {
        $this->assertSame($expected, TypeSpec::parse($type, $size)->signature());
    }

    public static function parsingCases(): iterable
    {
        yield 'int sem tamanho' => ['INT', null, 'INT'];
        yield 'int minúsculo' => ['int', null, 'INT'];
        yield 'varchar com tamanho separado' => ['VARCHAR', 120, 'VARCHAR(120)'];
        yield 'varchar com tamanho string' => ['VARCHAR', '120', 'VARCHAR(120)'];
        yield 'varchar com tamanho embutido' => ['VARCHAR(120)', null, 'VARCHAR(120)'];
        yield 'decimal precisão e escala' => ['DECIMAL', '10,2', 'DECIMAL(10,2)'];
        yield 'decimal embutido' => ['DECIMAL(10,2)', null, 'DECIMAL(10,2)'];
        yield 'decimal só precisão' => ['DECIMAL', '10', 'DECIMAL(10,0)'];
        yield 'unsigned' => ['INT UNSIGNED', null, 'INT UNSIGNED'];
        yield 'enum' => ["ENUM('a','b')", null, 'ENUM(a,b)'];
        yield 'texto sem tamanho' => ['TEXT', null, 'TEXT'];
        yield 'timestamp' => ['TIMESTAMP', null, 'TIMESTAMP'];
    }

    /**
     * Grafias equivalentes vindas dos dois catálogos precisam colapsar num tipo
     * só: o MySQL diz `int`, o PostgreSQL diz `integer`, e se os dois
     * sobrevivessem no IR toda coluna inteira pareceria divergente.
     */
    #[DataProvider('aliasCases')]
    public function testDialectSpellingsCollapseToOneCanonicalType(string $spelling, TypeName $expected): void
    {
        $this->assertSame($expected, TypeSpec::parse($spelling)->name);
    }

    public static function aliasCases(): iterable
    {
        yield 'integer' => ['integer', TypeName::Int];
        yield 'int4' => ['int4', TypeName::Int];
        yield 'character varying' => ['character varying', TypeName::Varchar];
        yield 'numeric' => ['numeric', TypeName::Decimal];
        yield 'double precision' => ['double precision', TypeName::Double];
        yield 'timestamp without time zone' => ['timestamp without time zone', TypeName::Timestamp];
        yield 'timestamp with time zone' => ['timestamp with time zone', TypeName::TimestampTz];
        yield 'bool' => ['bool', TypeName::Boolean];
        yield 'serial' => ['serial', TypeName::Int];
        yield 'bigserial' => ['bigserial', TypeName::BigInt];
    }

    public function testUnknownTypeIsRejectedWithTheOffendingSpelling(): void
    {
        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessageMatches("/'GEOGRAFIA'/");

        TypeSpec::parse('GEOGRAFIA');
    }

    public function testScaleGreaterThanPrecisionIsRejected(): void
    {
        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessageMatches('/Escala maior que a precisão/');

        new TypeSpec(TypeName::Decimal, precision: 2, scale: 10);
    }

    public function testNegativeLengthIsRejected(): void
    {
        $this->expectException(InvalidTypeException::class);

        new TypeSpec(TypeName::Varchar, length: -1);
    }

    public function testEnumWithoutValuesIsRejected(): void
    {
        $this->expectException(InvalidTypeException::class);
        $this->expectExceptionMessageMatches('/ENUM/');

        new TypeSpec(TypeName::Enum, values: []);
    }

    /**
     * Largura de exibição de tipo integral não é parte da identidade do tipo.
     *
     * `INT(11)` e `INT` são o mesmo INT, e o MySQL 8.0.19 parou de reportar a
     * largura no catálogo. Guardá-la faria todo model que escreve `INT(11)`
     * divergir para sempre do banco introspectado — e como a divergência é de
     * representação, não de semântica, nenhuma migração conseguiria resolvê-la.
     */
    public function testIntegralDisplayWidthIsDroppedAtEveryEntrance(): void
    {
        $this->assertNull(TypeSpec::parse('INT', 11)->length);
        $this->assertNull(TypeSpec::parse('INT(11)')->length);
        $this->assertNull(TypeSpec::parse('BIGINT', 20)->length);
        $this->assertNull(new TypeSpec(TypeName::Int, length: 11)->length);
        $this->assertNull(TypeSpec::fromArray(['name' => 'INT', 'length' => 11])->length);

        $this->assertTrue(TypeSpec::parse('INT', 11)->equals(TypeSpec::parse('INT')));
    }

    /**
     * Regressão: UNSIGNED depois do modificador, que é como o MySQL escreve.
     *
     * Um `INT UNSIGNED ZEROFILL` é reportado no catálogo como `int(10) unsigned` — o
     * atributo vem DEPOIS dos parênteses. A extração do modificador exige que o `)`
     * feche a string, então, procurando o modificador antes do atributo, essa grafia não
     * casava com nenhuma das duas regras e chegava a `TypeName::fromAlias()` como a
     * string `int(10)`: tipo desconhecido, introspecção derrubada, numa tabela
     * perfeitamente legítima.
     */
    public function testUnsignedIsAcceptedAfterTheModifier(): void
    {
        $type = TypeSpec::parse('int(10) unsigned');

        $this->assertSame(TypeName::Int, $type->name);
        $this->assertTrue($type->unsigned);
        $this->assertNull($type->length);

        $decimal = TypeSpec::parse('decimal(10,2) unsigned');

        $this->assertSame(10, $decimal->precision);
        $this->assertSame(2, $decimal->scale);
        $this->assertTrue($decimal->unsigned);

        // E a ordem antiga continua valendo, porque é o que o DSL dos models escreve.
        $this->assertTrue(TypeSpec::parse('INT UNSIGNED')->unsigned);
    }

    /**
     * A troca de ordem não pode alcançar dentro dos parênteses: um membro de ENUM
     * chamado `unsigned` é um valor, não um atributo.
     */
    public function testUnsignedInsideAnEnumMemberIsAValue(): void
    {
        $type = TypeSpec::parse("ENUM('a','b unsigned')");

        $this->assertSame(['a', 'b unsigned'], $type->values);
        $this->assertFalse($type->unsigned);
    }

    /**
     * O outro lado da regra: em VARCHAR o comprimento *é* a identidade, e é o que o
     * gerador antigo perdia no PostgreSQL.
     */
    public function testLengthSurvivesOnTypesWhereItIsPartOfTheIdentity(): void
    {
        $this->assertSame(120, TypeSpec::parse('VARCHAR', 120)->length);
        $this->assertSame(64, TypeSpec::parse('CHAR', 64)->length);
        $this->assertFalse(TypeSpec::parse('VARCHAR', 120)->equals(TypeSpec::parse('VARCHAR')));
    }

    /**
     * O efeito colateral que faz TINYINT e BOOLEAN conviverem no MySQL: BOOLEAN é
     * renderizado como `TINYINT(1)` e voltará como BOOLEAN, enquanto TINYINT é
     * renderizado como `TINYINT` e voltará como TINYINT. São distinguíveis no
     * catálogo justamente porque a largura não chega ao IR por outro caminho.
     */
    public function testDeclaredTinyintOneIsNotTheSameThingAsBoolean(): void
    {
        $this->assertFalse(TypeSpec::parse('TINYINT', 1)->equals(TypeSpec::parse('BOOLEAN')));
        $this->assertSame('TINYINT', TypeSpec::parse('TINYINT', 1)->signature());
    }

    /**
     * Membro de ENUM pode ter vírgula e pode ter aspa, escrita dobrada à moda do
     * SQL. Partir na vírgula quebrava o primeiro caso e, no segundo, deixava a aspa
     * dobrada dentro do valor — que ao ser citada de novo na geração de SQL virava
     * uma aspa quádrupla e mudava o valor.
     */
    #[DataProvider('enumParsingCases')]
    public function testEnumMembersSurviveCommasAndQuotes(string $type, array $expected): void
    {
        $this->assertSame($expected, TypeSpec::parse($type)->values);
    }

    /**
     * @return iterable<string,array{string,list<string>}>
     */
    public static function enumParsingCases(): iterable
    {
        yield 'simples' => ["ENUM('a','b')", ['a', 'b']];
        yield 'com espaço entre membros' => ["ENUM('a', 'b')", ['a', 'b']];
        yield 'com aspa dobrada' => ["ENUM('d''água','seco')", ["d'água", 'seco']];
        yield 'com vírgula dentro do valor' => ["ENUM('Sim, senhor','Não')", ['Sim, senhor', 'Não']];
        yield 'membro vazio é membro legítimo' => ["ENUM('','a')", ['', 'a']];
        yield 'aspas duplas' => ['ENUM("a","b")', ['a', 'b']];
    }

    public function testNonNumericSizeIsRejectedInsteadOfSilentlyBecomingZero(): void
    {
        $this->expectException(InvalidTypeException::class);

        TypeSpec::parse('VARCHAR', 'grande');
    }

    public function testEqualityIsBySignatureNotByIdentity(): void
    {
        $this->assertTrue(TypeSpec::parse('VARCHAR', 120)->equals(TypeSpec::parse('varchar(120)')));
        $this->assertFalse(TypeSpec::parse('VARCHAR', 120)->equals(TypeSpec::parse('VARCHAR', 255)));
        $this->assertFalse(TypeSpec::parse('INT')->equals(TypeSpec::parse('BIGINT')));
    }

    /**
     * O snapshot só carrega os campos que existem. Serializar nulos faria `INT`
     * ocupar cinco chaves e, pior, tornaria o JSON sensível a mudanças internas
     * da classe.
     */
    public function testSerializationOmitsEmptyFields(): void
    {
        $this->assertSame(['name' => 'INT'], TypeSpec::parse('INT')->toArray());

        $this->assertSame(
            ['length' => 120, 'name' => 'VARCHAR'],
            TypeSpec::parse('VARCHAR', 120)->toArray(),
        );

        $this->assertSame(
            ['name' => 'DECIMAL', 'precision' => 10, 'scale' => 2],
            TypeSpec::parse('DECIMAL', '10,2')->toArray(),
        );
    }

    #[DataProvider('roundTripCases')]
    public function testSerializationRoundTripsWithoutLoss(string $type, string|int|null $size): void
    {
        $original = TypeSpec::parse($type, $size);
        $restored = TypeSpec::fromArray($original->toArray());

        $this->assertSame($original->signature(), $restored->signature());
        $this->assertSame($original->toArray(), $restored->toArray());
    }

    public static function roundTripCases(): iterable
    {
        yield 'int' => ['INT', null];
        yield 'varchar' => ['VARCHAR', 120];
        yield 'decimal' => ['DECIMAL', '10,2'];
        yield 'unsigned' => ['BIGINT UNSIGNED', null];
        yield 'enum' => ["ENUM('a','b','c')", null];
        yield 'boolean' => ['BOOLEAN', null];
        yield 'json' => ['JSON', null];
    }

    public function testTypeClassificationHelpers(): void
    {
        $this->assertTrue(TypeSpec::parse('INT')->isIntegral());
        $this->assertTrue(TypeSpec::parse('DECIMAL', '10,2')->isNumeric());
        $this->assertFalse(TypeSpec::parse('DECIMAL', '10,2')->isIntegral());
        $this->assertTrue(TypeSpec::parse('VARCHAR', 10)->isTextual());
        $this->assertTrue(TypeSpec::parse('BOOLEAN')->isBoolean());
        $this->assertFalse(TypeSpec::parse('VARCHAR', 10)->isNumeric());
    }
}
