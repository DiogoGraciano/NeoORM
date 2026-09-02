<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * `a BETWEEN x AND y`.
 *
 * Três campos declarados em vez de um array de dois elementos: a aridade passa a ser
 * verificada pelo PHP na chamada. O sistema antigo passava `[$x, $y]` como valor e
 * conferia `count($value) !== 2` em runtime, uma vez por query executada.
 */
final readonly class Between implements Expression
{
    public function __construct(
        public Expression $operand,
        public Expression $low,
        public Expression $high,
        public bool $negated = false,
    ) {
    }
}
