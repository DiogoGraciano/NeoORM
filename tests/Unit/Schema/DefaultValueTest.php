<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use Diogodg\Neoorm\Schema\Value\DefaultKind;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnitTestCase;

/**
 * O DSL antigo colapsava quatro intenções distintas num bool `$is_constant`.
 * As quatro precisam sobreviver separadas até o SQL, e precisam continuar
 * distintas depois de ida e volta pelo snapshot.
 */
final class DefaultValueTest extends UnitTestCase
{
    public function testTheFourKindsAreDistinct(): void
    {
        $this->assertSame(DefaultKind::None, DefaultValue::none()->kind);
        $this->assertSame(DefaultKind::Null, DefaultValue::null()->kind);
        $this->assertSame(DefaultKind::Literal, DefaultValue::literal('x')->kind);
        $this->assertSame(DefaultKind::Expression, DefaultValue::expression('CURRENT_TIMESTAMP')->kind);
    }

    /**
     * A distinção que o bool antigo não conseguia expressar: "não tem cláusula
     * DEFAULT" e "tem DEFAULT NULL" produzem DDL diferente, e confundi-las faz
     * o differ ora perder uma mudança, ora inventar uma.
     */
    public function testNoDefaultIsNotTheSameThingAsDefaultNull(): void
    {
        $this->assertFalse(DefaultValue::none()->equals(DefaultValue::null()));
        $this->assertNull(DefaultValue::none()->toArray());
        $this->assertSame(['kind' => 'null'], DefaultValue::null()->toArray());
    }

    /**
     * O outro par que o bool confundia: a expressão vira SQL cru, o literal vai
     * citado. Trocá-los grava a string "CURRENT_TIMESTAMP" na coluna.
     */
    public function testExpressionIsNotTheSameThingAsALiteralWithTheSameText(): void
    {
        $this->assertFalse(
            DefaultValue::expression('CURRENT_TIMESTAMP')->equals(DefaultValue::literal('CURRENT_TIMESTAMP')),
        );
    }

    /**
     * `now()` do catálogo do PostgreSQL e `CURRENT_TIMESTAMP` do model são a
     * mesma coisa. Sem canonicalizar, toda coluna com default de tempo geraria
     * uma migração espúria a cada `generate`.
     */
    #[DataProvider('equivalentExpressions')]
    public function testEquivalentExpressionSpellingsCanonicalizeToTheSameValue(string $a, string $b): void
    {
        $this->assertTrue(
            DefaultValue::expression($a)->equals(DefaultValue::expression($b)),
            "'{$a}' e '{$b}' deveriam ser a mesma expressão",
        );
    }

    public static function equivalentExpressions(): iterable
    {
        yield 'now vs current_timestamp' => ['now()', 'CURRENT_TIMESTAMP'];
        yield 'caixa' => ['current_timestamp', 'CURRENT_TIMESTAMP'];
        yield 'com parênteses vazios' => ['CURRENT_TIMESTAMP()', 'CURRENT_TIMESTAMP'];
        yield 'localtimestamp' => ['localtimestamp', 'CURRENT_TIMESTAMP'];
        yield 'envolvida em parênteses' => ['(CURRENT_TIMESTAMP)', 'CURRENT_TIMESTAMP'];
        yield 'espaços extras' => ["CURRENT_TIMESTAMP  \n ", 'CURRENT_TIMESTAMP'];
        yield 'current_date' => ['current_date', 'CURRENT_DATE'];
    }

    /**
     * A remoção de parênteses externos não pode ser cega: em `(a) + (b)` o
     * primeiro parêntese não fecha no último caractere, e cortar as pontas
     * produziria `a) + (b`.
     */
    public function testOuterParenthesisStrippingDoesNotCorruptCompoundExpressions(): void
    {
        $this->assertSame('(a) + (b)', DefaultValue::expression('(a) + (b)')->value);
        $this->assertSame('a + b', DefaultValue::expression('((a + b))')->value);
    }

    public function testExpressionCaseIsNotUppercasedIndiscriminately(): void
    {
        // Uppercase cego destruiria literais de string dentro da expressão.
        $this->assertSame("concat('Olá', nome)", DefaultValue::expression("concat('Olá', nome)")->value);
    }

    /**
     * O catálogo do MySQL devolve todo default como string. Exigir que o int 0
     * e a string "0" fossem diferentes geraria drift falso em toda coluna
     * numérica com default.
     */
    public function testNumericLiteralsCompareByValueNotByPhpType(): void
    {
        $this->assertTrue(DefaultValue::literal(0)->equals(DefaultValue::literal('0')));
        $this->assertTrue(DefaultValue::literal(1.5)->equals(DefaultValue::literal('1.5')));
        $this->assertFalse(DefaultValue::literal(0)->equals(DefaultValue::literal(1)));
    }

    public function testBooleanLiteralsCompareAsBooleans(): void
    {
        $this->assertTrue(DefaultValue::literal(true)->equals(DefaultValue::literal(true)));
        $this->assertFalse(DefaultValue::literal(true)->equals(DefaultValue::literal(false)));
    }

    public function testStringLiteralsCompareExactly(): void
    {
        $this->assertTrue(DefaultValue::literal('scheduled')->equals(DefaultValue::literal('scheduled')));
        $this->assertFalse(DefaultValue::literal('scheduled')->equals(DefaultValue::literal('Scheduled')));
        $this->assertFalse(DefaultValue::literal('')->equals(DefaultValue::none()));
    }

    #[DataProvider('roundTripCases')]
    public function testSerializationRoundTripsWithoutLosingTheIntent(DefaultValue $value): void
    {
        $restored = DefaultValue::fromArray($value->toArray());

        $this->assertSame($value->kind, $restored->kind);
        $this->assertTrue($value->equals($restored));
        $this->assertSame($value->toArray(), $restored->toArray());
    }

    public static function roundTripCases(): iterable
    {
        yield 'nenhum' => [DefaultValue::none()];
        yield 'null' => [DefaultValue::null()];
        yield 'literal string' => [DefaultValue::literal('scheduled')];
        yield 'literal string vazia' => [DefaultValue::literal('')];
        yield 'literal int' => [DefaultValue::literal(0)];
        yield 'literal float' => [DefaultValue::literal(1.5)];
        yield 'literal bool' => [DefaultValue::literal(true)];
        yield 'literal com aspas' => [DefaultValue::literal("d'Ávila")];
        yield 'literal com barra' => [DefaultValue::literal('c:\\temp')];
        yield 'expressão' => [DefaultValue::expression('CURRENT_TIMESTAMP')];
        yield 'expressão composta' => [DefaultValue::expression("concat('a', 'b')")];
    }
}
