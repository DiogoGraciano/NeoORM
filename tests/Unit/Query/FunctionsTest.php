<?php

declare(strict_types=1);

namespace Tests\Unit\Query;

use Diogodg\Neoorm\Query\Expr\Between;
use Diogodg\Neoorm\Query\Expr\ColumnRef;
use Diogodg\Neoorm\Query\Expr\Comparison;
use Diogodg\Neoorm\Query\Expr\ComparisonOp;
use Diogodg\Neoorm\Query\Expr\InList;
use Diogodg\Neoorm\Query\Expr\LogicalConnective;
use Diogodg\Neoorm\Query\Expr\NotExpr;
use Diogodg\Neoorm\Query\Expr\NullCheck;
use Diogodg\Neoorm\Query\Expr\NullsPlacement;
use Diogodg\Neoorm\Query\Expr\OrderDirection;
use Diogodg\Neoorm\Query\Expr\SqlFunction;
use Diogodg\Neoorm\Query\Expr\Value;
use Diogodg\Neoorm\Query\Func;
use Diogodg\Neoorm\Query\Op;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnitTestCase;

use function Diogodg\Neoorm\Query\asc;
use function Diogodg\Neoorm\Query\between;
use function Diogodg\Neoorm\Query\desc;
use function Diogodg\Neoorm\Query\eq;
use function Diogodg\Neoorm\Query\gt;
use function Diogodg\Neoorm\Query\gte;
use function Diogodg\Neoorm\Query\ilike;
use function Diogodg\Neoorm\Query\inArray;
use function Diogodg\Neoorm\Query\isNotNull;
use function Diogodg\Neoorm\Query\isNull;
use function Diogodg\Neoorm\Query\like;
use function Diogodg\Neoorm\Query\lt;
use function Diogodg\Neoorm\Query\lte;
use function Diogodg\Neoorm\Query\ne;
use function Diogodg\Neoorm\Query\notBetween;
use function Diogodg\Neoorm\Query\notInArray;
use function Diogodg\Neoorm\Query\notLike;
use function Diogodg\Neoorm\Query\sql;

/**
 * A superfície pública: as funções livres, `Op` e `Func`.
 *
 * O teste é sobre o grafo que cada uma monta. Vale porque essas funções são a única
 * coisa que o usuário da biblioteca escreve — um `gte()` que montasse `>` em vez de
 * `>=` devolveria resultado errado sem erro nenhum, e nenhum teste de compilador
 * pegaria isso.
 */
final class FunctionsTest extends UnitTestCase
{
    private function column(string $name = 'age'): ColumnRef
    {
        return new ColumnRef('users', $name, TypeSpec::parse('INT'), notNull: true);
    }

    #[DataProvider('comparisons')]
    public function testEachFunctionBuildsItsOwnOperator(string $function, ComparisonOp $expected): void
    {
        $built = ('Diogodg\\Neoorm\\Query\\' . $function)($this->column(), 1);

        $this->assertInstanceOf(Comparison::class, $built);
        $this->assertSame($expected, $built->operator);
    }

    /**
     * @return iterable<string,array{string,ComparisonOp}>
     */
    public static function comparisons(): iterable
    {
        yield 'eq' => ['eq', ComparisonOp::Equal];
        yield 'ne' => ['ne', ComparisonOp::NotEqual];
        yield 'gt' => ['gt', ComparisonOp::GreaterThan];
        yield 'gte' => ['gte', ComparisonOp::GreaterOrEqual];
        yield 'lt' => ['lt', ComparisonOp::LessThan];
        yield 'lte' => ['lte', ComparisonOp::LessOrEqual];
        yield 'like' => ['like', ComparisonOp::Like];
        yield 'notLike' => ['notLike', ComparisonOp::NotLike];
        yield 'ilike' => ['ilike', ComparisonOp::ILike];
    }

    /**
     * A propriedade que sustenta a segurança da camada inteira: um valor do chamador
     * vira `Value`, nunca texto.
     *
     * O vetor abaixo é o do teste de regressão do sistema antigo. Aqui ele não tem
     * como virar SQL — o compilador não sabe transformar `Value` em texto, só em
     * placeholder.
     */
    public function testAValueFromTheCallerAlwaysBecomesABind(): void
    {
        $comparison = eq($this->column('name'), "' OR '1'='1");

        $this->assertInstanceOf(Value::class, $comparison->right);
        $this->assertSame("' OR '1'='1", $comparison->right->value);
    }

    public function testComparingTwoColumnsKeepsBothSidesAsReferences(): void
    {
        $left = $this->column('id');
        $right = new ColumnRef('posts', 'author_id', TypeSpec::parse('INT'));

        $comparison = eq($left, $right);

        $this->assertSame($left, $comparison->left);
        $this->assertSame($right, $comparison->right);
    }

    #[DataProvider('nullComparisons')]
    public function testEqualityComparisonsRefuseNull(string $function, string $replacement): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/' . $replacement . '/');

        ('Diogodg\\Neoorm\\Query\\' . $function)($this->column(), null);
    }

    /** @return iterable<string,array{string,string}> */
    public static function nullComparisons(): iterable
    {
        yield 'eq' => ['eq', 'isNull'];
        yield 'ne' => ['ne', 'isNotNull'];
    }

    public function testInArrayWrapsEveryValue(): void
    {
        $node = inArray($this->column(), [1, 2, 3]);

        $this->assertIsArray($node->values);
        $this->assertCount(3, $node->values);
        $this->assertContainsOnlyInstancesOf(Value::class, $node->values);
        $this->assertFalse($node->negated);
    }

    public function testTheNegatedVariantsSetTheFlag(): void
    {
        $this->assertTrue(notInArray($this->column(), [1])->negated);
        $this->assertTrue(notBetween($this->column(), 1, 10)->negated);
        $this->assertTrue(isNotNull($this->column())->negated);

        $this->assertFalse(inArray($this->column(), [1])->negated);
        $this->assertFalse(between($this->column(), 1, 10)->negated);
        $this->assertFalse(isNull($this->column())->negated);
    }

    public function testBetweenKeepsTheBoundsSeparate(): void
    {
        $node = between($this->column(), 1, 10);

        $this->assertInstanceOf(Between::class, $node);
        $this->assertInstanceOf(Value::class, $node->low);
        $this->assertInstanceOf(Value::class, $node->high);
        $this->assertSame(1, $node->low->value);
        $this->assertSame(10, $node->high->value);
    }

    public function testNullChecksHaveNoRightHandSide(): void
    {
        // O ramo `IS` do sistema antigo recebia 'NULL' como valor e o interpolava
        // cru. Sem lado direito na estrutura, não existe valor para interpolar.
        $node = isNull($this->column('deleted_at'));

        $this->assertInstanceOf(NullCheck::class, $node);
        $this->assertObjectNotHasProperty('right', $node);
    }

    public function testOrderTermsCarryDirectionAndNullPlacement(): void
    {
        $this->assertSame(OrderDirection::Asc, asc($this->column())->direction);
        $this->assertSame(OrderDirection::Desc, desc($this->column())->direction);
        $this->assertNull(asc($this->column())->nulls);
        $this->assertSame(
            NullsPlacement::Last,
            desc($this->column(), NullsPlacement::Last)->nulls,
        );
    }

    public function testSqlCarriesItsBinds(): void
    {
        $node = sql('EXTRACT(YEAR FROM ?) = ?', $this->column('created_at'), 2026);

        $this->assertCount(2, $node->binds);
        $this->assertInstanceOf(ColumnRef::class, $node->binds[0]);
        $this->assertInstanceOf(Value::class, $node->binds[1]);
    }

    // --------------------------------------------------------------------- Op

    public function testTheConnectives(): void
    {
        $a = eq($this->column(), 1);
        $b = eq($this->column(), 2);

        $this->assertSame(LogicalConnective::And, Op::and($a, $b)->connective);
        $this->assertSame(LogicalConnective::Or, Op::or($a, $b)->connective);
        $this->assertInstanceOf(NotExpr::class, Op::not($a));
    }

    // ------------------------------------------------------------------- Func

    public function testCountWithoutArgumentsIsTheStarForm(): void
    {
        $this->assertTrue(Func::count()->isStar());
        $this->assertSame(SqlFunction::Count, Func::count()->function);
        $this->assertFalse(Func::count($this->column())->isStar());
        $this->assertTrue(Func::count($this->column(), distinct: true)->distinct);
    }

    public function testCoalesceWrapsLiteralArguments(): void
    {
        $node = Func::coalesce($this->column('nickname'), 'sem apelido');

        $this->assertSame(SqlFunction::Coalesce, $node->function);
        $this->assertCount(2, $node->arguments);
        $this->assertInstanceOf(ColumnRef::class, $node->arguments[0]);
        $this->assertInstanceOf(Value::class, $node->arguments[1]);
    }

    /**
     * Um agregado precisa de GROUP BY; `LOWER` não. O compilador vai usar isso para
     * decidir se a consulta está mal formada.
     */
    public function testAggregatesAreDistinguishableFromScalarFunctions(): void
    {
        $this->assertTrue(Func::sum($this->column())->function->isAggregate());
        $this->assertFalse(Func::lower($this->column())->function->isAggregate());
    }
}
