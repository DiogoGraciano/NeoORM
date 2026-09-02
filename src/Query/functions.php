<?php

declare(strict_types=1);

/**
 * As funções de construção de condição.
 *
 * São funções livres, e não métodos estáticos, porque é isso que dá a leitura do
 * Drizzle — `where(eq($u->id, 1))` em vez de `where(Expr::eq($u->id, 1))`. Ficam
 * neste arquivo, carregado por `autoload.files`, e são importadas em bloco:
 *
 * ```php
 * use function Diogodg\Neoorm\Query\{eq, gt, like, inArray, desc};
 * ```
 *
 * O que NÃO está aqui: os conectivos (`Op::and`), porque `and` e `or` são palavras
 * reservadas, e as funções SQL (`Func::count`), porque `count`/`sum`/`min`/`max`
 * sombreariam as globais do PHP no arquivo que as importasse.
 *
 * Todas aceitam valor cru do lado direito e o envolvem em `Value`, que é o que
 * garante bind. Nenhuma delas tem caminho por onde um valor vire texto de SQL.
 */

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Query\Expr\Between;
use Diogodg\Neoorm\Query\Expr\Comparison;
use Diogodg\Neoorm\Query\Expr\ComparisonOp;
use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\InList;
use Diogodg\Neoorm\Query\Expr\NullCheck;
use Diogodg\Neoorm\Query\Expr\NullsPlacement;
use Diogodg\Neoorm\Query\Expr\OrderDirection;
use Diogodg\Neoorm\Query\Expr\OrderTerm;
use Diogodg\Neoorm\Query\Expr\Sql;
use Diogodg\Neoorm\Query\Expr\Value;

function eq(Expression $left, mixed $right): Comparison
{
    if ($right === null) {
        throw new \InvalidArgumentException(
            'eq(..., null) nunca é verdadeiro em SQL. Use isNull($coluna).',
        );
    }

    return new Comparison($left, ComparisonOp::Equal, Value::wrap($right, Value::typeOf($left)));
}

function ne(Expression $left, mixed $right): Comparison
{
    if ($right === null) {
        throw new \InvalidArgumentException(
            'ne(..., null) nunca é verdadeiro em SQL. Use isNotNull($coluna).',
        );
    }

    return new Comparison($left, ComparisonOp::NotEqual, Value::wrap($right, Value::typeOf($left)));
}

function gt(Expression $left, mixed $right): Comparison
{
    return new Comparison($left, ComparisonOp::GreaterThan, Value::wrap($right, Value::typeOf($left)));
}

function gte(Expression $left, mixed $right): Comparison
{
    return new Comparison($left, ComparisonOp::GreaterOrEqual, Value::wrap($right, Value::typeOf($left)));
}

function lt(Expression $left, mixed $right): Comparison
{
    return new Comparison($left, ComparisonOp::LessThan, Value::wrap($right, Value::typeOf($left)));
}

function lte(Expression $left, mixed $right): Comparison
{
    return new Comparison($left, ComparisonOp::LessOrEqual, Value::wrap($right, Value::typeOf($left)));
}

/**
 * O `%` faz parte do padrão e é responsabilidade de quem chama: `like($u->name, "{$q}%")`.
 *
 * A biblioteca não acrescenta curingas sozinha porque não tem como saber se você quer
 * prefixo, sufixo ou contém — e porque um `%` posto pela biblioteca no início mata o
 * índice sem que nada no código diga isso.
 */
function like(Expression $left, mixed $pattern): Comparison
{
    return new Comparison($left, ComparisonOp::Like, Value::wrap($pattern, Value::typeOf($left)));
}

function notLike(Expression $left, mixed $pattern): Comparison
{
    return new Comparison($left, ComparisonOp::NotLike, Value::wrap($pattern, Value::typeOf($left)));
}

/**
 * Comparação sem diferenciar caixa.
 *
 * `ILIKE` no PostgreSQL; no MySQL o dialeto rebaixa para `LOWER() LIKE LOWER()`, que
 * dá a mesma resposta e custa o índice da coluna.
 */
function ilike(Expression $left, mixed $pattern): Comparison
{
    return new Comparison($left, ComparisonOp::ILike, Value::wrap($pattern, Value::typeOf($left)));
}

/**
 * @param iterable<mixed>|Expression $values lista de valores, ou uma subconsulta
 */
function inArray(Expression $left, iterable|Expression $values): InList
{
    return new InList($left, $values instanceof Expression ? $values : Value::wrapAll($values, Value::typeOf($left)));
}

/**
 * @param iterable<mixed>|Expression $values
 */
function notInArray(Expression $left, iterable|Expression $values): InList
{
    return new InList(
        $left,
        $values instanceof Expression ? $values : Value::wrapAll($values, Value::typeOf($left)),
        negated: true,
    );
}

function between(Expression $operand, mixed $low, mixed $high): Between
{
    return new Between($operand, Value::wrap($low, Value::typeOf($operand)), Value::wrap($high, Value::typeOf($operand)));
}

function notBetween(Expression $operand, mixed $low, mixed $high): Between
{
    return new Between($operand, Value::wrap($low, Value::typeOf($operand)), Value::wrap($high, Value::typeOf($operand)), negated: true);
}

function isNull(Expression $operand): NullCheck
{
    return new NullCheck($operand);
}

function isNotNull(Expression $operand): NullCheck
{
    return new NullCheck($operand, negated: true);
}

function asc(Expression $expression, ?NullsPlacement $nulls = null): OrderTerm
{
    return new OrderTerm($expression, OrderDirection::Asc, $nulls);
}

function desc(Expression $expression, ?NullsPlacement $nulls = null): OrderTerm
{
    return new OrderTerm($expression, OrderDirection::Desc, $nulls);
}

/**
 * A via de escape. O fragmento **não é sanitizado**; os valores continuam sendo binds.
 *
 * ```php
 * sql('EXTRACT(YEAR FROM ?) = ?', $u->created_at, 2026)
 * ```
 */
function sql(string $fragment, mixed ...$binds): Sql
{
    return new Sql($fragment, ...$binds);
}
