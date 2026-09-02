<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * Comparação binária: `a = b`, `a > b`, `a LIKE b`.
 *
 * Os dois lados são expressões, não "coluna e valor". É o que permite comparar duas
 * colunas (`eq($p->author_id, $u->id)`, a condição de um join) com a mesma
 * construção que compara coluna e literal, sem um segundo tipo de nó.
 */
final readonly class Comparison implements Expression
{
    public function __construct(
        public Expression $left,
        public ComparisonOp $operator,
        public Expression $right,
    ) {
    }
}
