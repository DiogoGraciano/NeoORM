<?php

declare(strict_types=1);

namespace Tests\Unit\Query;

use Diogodg\Neoorm\Query\Expr\Aliased;
use Diogodg\Neoorm\Query\Expr\ColumnRef;
use Diogodg\Neoorm\Query\Expr\ComparisonOp;
use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\FuncCall;
use Diogodg\Neoorm\Query\Expr\InList;
use Diogodg\Neoorm\Query\Expr\Logical;
use Diogodg\Neoorm\Query\Expr\LogicalConnective;
use Diogodg\Neoorm\Query\Expr\OrderTerm;
use Diogodg\Neoorm\Query\Expr\Sql;
use Diogodg\Neoorm\Query\Expr\SqlFunction;
use Diogodg\Neoorm\Query\Expr\Value;
use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnitTestCase;

use function Diogodg\Neoorm\Query\eq;

/**
 * As invariantes dos nós da árvore de expressão.
 *
 * Nada aqui gera SQL: os nós são dado puro e é assim que devem ser testados. O que
 * se afirma é a FORMA do grafo e o que ele se recusa a construir — porque a decisão
 * de desenho é que combinação impossível vire erro na construção, e não SQL inválido
 * que o banco recusa sem dizer de onde veio.
 */
final class NodesTest extends UnitTestCase
{
    private function column(string $name = 'age', string $type = 'INT'): ColumnRef
    {
        return new ColumnRef('users', $name, TypeSpec::parse($type), notNull: true);
    }

    // ------------------------------------------------------------------ Value

    public function testWrapPassesExpressionsThroughUntouched(): void
    {
        $column = $this->column();

        $this->assertSame($column, Value::wrap($column));
        $this->assertInstanceOf(Value::class, Value::wrap(42));
    }

    // -------------------------------------------------------------- ColumnRef

    public function testColumnRefRejectsIdentifiersThatCouldReachTheSql(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new ColumnRef('users', 'name; DROP TABLE users --', TypeSpec::parse('VARCHAR', 120));
    }

    public function testAliasingAColumnKeepsTheNameAndSwapsTheQualifier(): void
    {
        $aliased = $this->column()->withQualifier('autor');

        $this->assertSame('autor', $aliased->qualifier);
        $this->assertSame('age', $aliased->name);
        $this->assertSame('autor.age', $aliased->qualified());
    }

    // ---------------------------------------------------------------- Logical

    /**
     * `AND` é associativo, então aninhar o mesmo conectivo só produzia parênteses
     * a mais. Achatar mantém o SQL legível e a árvore comparável em teste.
     */
    public function testTheSameConnectiveIsFlattened(): void
    {
        $c = $this->column();

        $inner = new Logical(LogicalConnective::And, [eq($c, 1), eq($c, 2)]);
        $outer = new Logical(LogicalConnective::And, [$inner, eq($c, 3)]);

        $this->assertCount(3, $outer->operands);
    }

    /**
     * Conectivos diferentes NÃO achatam: ali o parêntese muda o resultado.
     */
    public function testADifferentConnectiveIsPreserved(): void
    {
        $c = $this->column();

        $inner = new Logical(LogicalConnective::Or, [eq($c, 1), eq($c, 2)]);
        $outer = new Logical(LogicalConnective::And, [$inner, eq($c, 3)]);

        $this->assertCount(2, $outer->operands);
        $this->assertInstanceOf(Logical::class, $outer->operands[0]);
    }

    public function testAnEmptyConnectiveIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sem operandos/');

        new Logical(LogicalConnective::And, []);
    }

    // ----------------------------------------------------------------- InList

    /**
     * `IN ()` não é SQL válido, e quem chega aqui quase sempre montou a lista a
     * partir de um filtro que veio vazio — caso que precisa de decisão explícita.
     */
    public function testAnEmptyInListIsRejectedAndNamesTheColumn(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/users\.age/');

        new InList($this->column(), []);
    }

    public function testInListAcceptsASubqueryAsASingleExpression(): void
    {
        $subquery = new Sql('SELECT id FROM banned');
        $node = new InList($this->column(), $subquery);

        $this->assertSame($subquery, $node->values);
    }

    // -------------------------------------------------------------------- Sql

    public function testTheFragmentAndTheBindCountMustAgree(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/2 marcador/');

        new Sql('a = ? AND b = ?', 1);
    }

    public function testAFragmentWithoutBindsIsFine(): void
    {
        $this->assertCount(0, (new Sql('CURRENT_TIMESTAMP'))->binds);
    }

    // --------------------------------------------------------------- FuncCall

    public function testCountIsTheOnlyFunctionWithAStarForm(): void
    {
        $this->assertTrue((new FuncCall(SqlFunction::Count))->isStar());

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/SUM precisa de ao menos um argumento/');
        new FuncCall(SqlFunction::Sum);
    }

    public function testDistinctIsRefusedWhereItIsNotSql(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DISTINCT não faz sentido em LOWER/');

        new FuncCall(SqlFunction::Lower, [$this->column()], distinct: true);
    }

    public function testCountDistinctStarIsRefused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DISTINCT precisa saber sobre qual coluna/');

        new FuncCall(SqlFunction::Count, [], distinct: true);
    }

    // ---------------------------------------------------------------- Aliased

    /**
     * O atalho `[coluna, alias]` do sistema antigo não validava o alias, e
     * `['name', 'x FROM users; --']` entrava no SQL.
     */
    public function testTheAliasIsValidatedLikeAnyOtherIdentifier(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new Aliased($this->column(), 'alias FROM users; --');
    }

    // ----------------------------------------------------------- ComparisonOp

    public function testNegatingAnOperatorRoundTrips(): void
    {
        foreach ([ComparisonOp::Equal, ComparisonOp::LessThan, ComparisonOp::Like] as $operator) {
            $this->assertSame($operator, $operator->negated()->negated());
        }
    }

    public function testIlikeHasNoNegatedOperatorOfItsOwn(): void
    {
        $this->expectException(LogicException::class);
        ComparisonOp::ILike->negated();
    }

    // -------------------------------------------------------------- OrderTerm

    /**
     * `OrderTerm` não é `Expression` de propósito: é o que impede
     * `where(desc($u->id))` de compilar, trocando SQL inválido por erro de tipo.
     */
    public function testAnOrderTermIsNotAnExpression(): void
    {
        $this->assertNotInstanceOf(Expression::class, new OrderTerm($this->column()));
    }
}
