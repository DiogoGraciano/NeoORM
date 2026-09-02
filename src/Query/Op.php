<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\Logical;
use Diogodg\Neoorm\Query\Expr\LogicalConnective;
use Diogodg\Neoorm\Query\Expr\NotExpr;

/**
 * Os conectivos lógicos.
 *
 * São métodos estáticos, e não funções livres como `eq()` e `gt()`, por um motivo da
 * linguagem: `and`, `or` e `not` — `not` por simetria, já que só as duas primeiras
 * são de fato reservadas — não podem nomear uma função em PHP, mas **podem** nomear
 * um método desde o 7.0. `Op::and(...)` é o mais perto de `and(...)` que a linguagem
 * permite.
 *
 * ```php
 * ->where(Op::and(
 *     gt($u->age, 18),
 *     Op::or(eq($u->status, 'active'), isNull($u->deleted_at)),
 * ))
 * ```
 */
final class Op
{
    private function __construct()
    {
    }

    public static function and(Expression ...$conditions): Logical
    {
        return new Logical(LogicalConnective::And, array_values($conditions));
    }

    public static function or(Expression ...$conditions): Logical
    {
        return new Logical(LogicalConnective::Or, array_values($conditions));
    }

    public static function not(Expression $condition): NotExpr
    {
        return new NotExpr($condition);
    }
}
