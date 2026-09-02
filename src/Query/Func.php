<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\FuncCall;
use Diogodg\Neoorm\Query\Expr\SqlFunction;
use Diogodg\Neoorm\Query\Expr\Value;

/**
 * As funções SQL.
 *
 * Métodos estáticos porque `count`, `sum`, `min` e `max` já existem como funções
 * globais do PHP: importar `use function Diogodg\Neoorm\Query\count;` **sombreia a
 * `count()` global no arquivo inteiro**, e o efeito é qualquer `count($array)` no
 * mesmo arquivo passar a montar SQL. Um erro difícil de ver e trivial de cometer.
 *
 * O nome da classe é `Func` e não `Fn` porque `fn` virou palavra reservada no PHP 7.4
 * — `class Fn` é erro de parse.
 */
final class Func
{
    private function __construct()
    {
    }

    /**
     * Sem argumento vira `COUNT(*)`.
     */
    public static function count(Expression|null $expression = null, bool $distinct = false): FuncCall
    {
        return new FuncCall(
            SqlFunction::Count,
            $expression === null ? [] : [$expression],
            $distinct,
        );
    }

    public static function sum(Expression $expression, bool $distinct = false): FuncCall
    {
        return new FuncCall(SqlFunction::Sum, [$expression], $distinct);
    }

    public static function avg(Expression $expression, bool $distinct = false): FuncCall
    {
        return new FuncCall(SqlFunction::Avg, [$expression], $distinct);
    }

    public static function min(Expression $expression): FuncCall
    {
        return new FuncCall(SqlFunction::Min, [$expression]);
    }

    public static function max(Expression $expression): FuncCall
    {
        return new FuncCall(SqlFunction::Max, [$expression]);
    }

    public static function lower(Expression $expression): FuncCall
    {
        return new FuncCall(SqlFunction::Lower, [$expression]);
    }

    public static function upper(Expression $expression): FuncCall
    {
        return new FuncCall(SqlFunction::Upper, [$expression]);
    }

    /**
     * Aceita valor cru nos argumentos: `Func::coalesce($u->nickname, 'sem apelido')`
     * é o uso mais comum, e ali o segundo argumento é um literal.
     */
    public static function coalesce(Expression $first, mixed ...$rest): FuncCall
    {
        return new FuncCall(
            SqlFunction::Coalesce,
            [$first, ...Value::wrapAll($rest)],
        );
    }
}
