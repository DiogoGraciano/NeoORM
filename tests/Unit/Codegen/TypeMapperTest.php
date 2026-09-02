<?php

declare(strict_types=1);

namespace Tests\Unit\Codegen;

use Diogodg\Neoorm\Codegen\GeneratorOptions;
use Diogodg\Neoorm\Codegen\TypeMapper;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnitTestCase;

/**
 * O mapa de tipo SQL para tipo PHP.
 *
 * O teste central é o de exaustividade: um `TypeName` novo sem braço no `match` do
 * mapper derruba a suíte inteira, em vez de cair em `mixed` sem ninguém notar — que era
 * o comportamento do gerador antigo e a razão de `BIGINT`, `BOOLEAN`, `DATE` e `JSON`
 * não terem tipo nenhum no docblock.
 */
final class TypeMapperTest extends UnitTestCase
{
    private function mapper(GeneratorOptions $options = new GeneratorOptions()): TypeMapper
    {
        return new TypeMapper($options);
    }

    /**
     * Um `TypeSpec` válido para cada nome de tipo — alguns exigem tamanho ou valores.
     */
    private static function spec(TypeName $name): TypeSpec
    {
        return match ($name) {
            TypeName::Enum => new TypeSpec($name, values: ['ativo', 'bloqueado']),
            TypeName::Char, TypeName::Varchar,
            TypeName::Binary, TypeName::VarBinary => new TypeSpec($name, length: 120),
            TypeName::Decimal => new TypeSpec($name, precision: 10, scale: 2),
            default => new TypeSpec($name),
        };
    }

    /**
     * @return iterable<string,array{TypeName}>
     */
    public static function everyTypeName(): iterable
    {
        foreach (TypeName::cases() as $case) {
            yield $case->value => [$case];
        }
    }

    /**
     * A guarda de cobertura: todo tipo do IR tem tradução, e ela é utilizável.
     *
     * `native()` precisa ser um tipo que o PHP aceite numa assinatura, e `castMethod()`
     * precisa existir de fato em `Cast` — sem isso o gerador emitiria arquivo que não
     * compila ou que morre na primeira linha lida.
     */
    #[DataProvider('everyTypeName')]
    public function testEveryTypeHasAUsableMapping(TypeName $name): void
    {
        $type = $this->mapper()->forType(self::spec($name));

        $this->assertNotSame('', $type->native());
        $this->assertNotSame('', $type->docblock());

        $this->assertTrue(
            method_exists(\Diogodg\Neoorm\Runtime\Casting\Cast::class, $type->castMethod()),
            "Cast::{$type->castMethod()}() não existe, mas o mapper aponta para ele em {$name->value}.",
        );

        $nullable = $type->asNullable();

        $this->assertTrue(
            method_exists(\Diogodg\Neoorm\Runtime\Casting\Cast::class, $nullable->castMethod()),
            "Cast::{$nullable->castMethod()}() não existe (variante nullable de {$name->value}).",
        );
    }

    #[DataProvider('mappings')]
    public function testTheMappingsThatMatter(TypeName $name, string $native, string $caster): void
    {
        $type = $this->mapper()->forType(self::spec($name));

        $this->assertSame($native, $type->native());
        $this->assertSame($caster, $type->caster);
    }

    /**
     * @return iterable<string,array{TypeName,string,string}>
     */
    public static function mappings(): iterable
    {
        yield 'int' => [TypeName::Int, 'int', 'int'];
        yield 'bigint' => [TypeName::BigInt, 'int', 'int'];
        yield 'float' => [TypeName::Float, 'float', 'float'];
        // DECIMAL como string: é o que o PDO devolve, e float perderia a precisão que
        // o banco teve o cuidado de guardar.
        yield 'decimal' => [TypeName::Decimal, 'string', 'decimal'];
        yield 'boolean' => [TypeName::Boolean, 'bool', 'bool'];
        yield 'varchar' => [TypeName::Varchar, 'string', 'string'];
        // CHAR tem caster próprio: MySQL corta o espaço à direita, PostgreSQL preenche.
        yield 'char' => [TypeName::Char, 'string', 'char'];
        yield 'date' => [TypeName::Date, 'DateTimeImmutable', 'dateTime'];
        yield 'datetime' => [TypeName::DateTime, 'DateTimeImmutable', 'dateTime'];
        yield 'timestamptz' => [TypeName::TimestampTz, 'DateTimeImmutable', 'dateTimeTz'];
        // TIME é duração no MySQL (±838h), não hora do dia: DateTimeImmutable corromperia.
        yield 'time' => [TypeName::Time, 'string', 'string'];
        yield 'json' => [TypeName::Json, 'mixed', 'json'];
        yield 'jsonb' => [TypeName::Jsonb, 'mixed', 'json'];
        yield 'uuid' => [TypeName::Uuid, 'string', 'string'];
        // bytea chega como resource de stream no pdo_pgsql.
        yield 'bytea' => [TypeName::Bytea, 'string', 'binary'];
        yield 'blob' => [TypeName::Blob, 'string', 'string'];
    }

    /**
     * `?mixed` é erro de parse, e `mixed` já contém null. É o ponto onde concatenar
     * `'?'` ingenuamente geraria arquivo que não compila.
     */
    public function testMixedNeverBecomesNullable(): void
    {
        $json = $this->mapper()->forType(self::spec(TypeName::Json))->asNullable();

        $this->assertSame('mixed', $json->native());
        $this->assertSame('mixed', $json->docblock());
    }

    public function testNullabilityComesFromTheColumnNotTheType(): void
    {
        $mapper = $this->mapper();
        $spec = self::spec(TypeName::Varchar);

        $obrigatoria = new ColumnDefinition('name', $spec, notNull: true);
        $opcional = new ColumnDefinition('phone', $spec);

        $this->assertSame('string', $mapper->for($obrigatoria)->native());
        $this->assertSame('?string', $mapper->for($opcional)->native());
        $this->assertSame('nullableString', $mapper->for($opcional)->castMethod());
    }

    /**
     * O PHP não tem inteiro sem sinal, então `UNSIGNED` refina só o docblock — de graça
     * para o analisador, sem mentir na assinatura.
     */
    public function testUnsignedRefinesOnlyTheDocblock(): void
    {
        $type = $this->mapper()->forType(new TypeSpec(TypeName::Int, unsigned: true));

        $this->assertSame('int', $type->native());
        $this->assertSame('int<0, max>', $type->docblock());
    }

    /**
     * O conjunto do ENUM vira união literal: é o que dá ao analisador a chance de
     * recusar `'ativoo'` antes de rodar, mesmo sem enum PHP gerado.
     */
    public function testEnumValuesBecomeALiteralUnion(): void
    {
        $type = $this->mapper()->forType(self::spec(TypeName::Enum));

        $this->assertSame("'ativo'|'bloqueado'", $type->docblock());
    }

    public function testDecimalAsFloatIsAvailableForWhoAcceptsTheLoss(): void
    {
        $type = $this->mapper(new GeneratorOptions(decimalAsFloat: true))
            ->forType(self::spec(TypeName::Decimal));

        $this->assertSame('float', $type->native());
        $this->assertSame('float', $type->caster);
    }

    public function testTemporalAsStringSkipsTheConversion(): void
    {
        $type = $this->mapper(new GeneratorOptions(temporalAsString: true))
            ->forType(self::spec(TypeName::DateTime));

        $this->assertSame('string', $type->native());
        $this->assertNull($type->import);
    }

    public function testBigIntAsStringForIdsBeyondPhpIntMax(): void
    {
        $type = $this->mapper(new GeneratorOptions(bigIntAsString: true))
            ->forType(self::spec(TypeName::BigInt));

        $this->assertSame('string', $type->native());
        $this->assertSame('numeric-string', $type->docblock());
    }

    /**
     * Só os tipos não escalares pedem import — e o gerador precisa saber quais para
     * emitir o `use` certo no topo do arquivo.
     */
    public function testOnlyNonScalarTypesRequireAnImport(): void
    {
        $this->assertSame(
            \DateTimeImmutable::class,
            $this->mapper()->forType(self::spec(TypeName::DateTime))->import,
        );

        $this->assertNull($this->mapper()->forType(self::spec(TypeName::Int))->import);
    }
}
