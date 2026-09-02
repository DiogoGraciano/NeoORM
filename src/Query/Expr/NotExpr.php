<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * `NOT (…)`.
 *
 * Sempre com parênteses no SQL gerado. `NOT a AND b` e `NOT (a AND b)` são coisas
 * diferentes por precedência, e a árvore não guarda parênteses — guarda estrutura.
 * Emitir o parêntese sempre é o que faz a estrutura sobreviver à tradução.
 */
final readonly class NotExpr implements Expression
{
    public function __construct(public Expression $operand)
    {
    }
}
