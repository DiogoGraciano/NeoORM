<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * `a IS NULL` e `a IS NOT NULL`.
 *
 * Nó próprio, e não um operador de {@see ComparisonOp}, porque o predicado não tem
 * lado direito. Tratá-lo como comparação foi exatamente a falha corrigida na 2.0: o
 * ramo `IS` do filtro antigo recebia `'NULL'` como *valor* e o interpolava cru na
 * query, então `addFilter('x', 'IS', $entrada)` era injeção direta. Sem lado direito
 * na estrutura, não existe valor para interpolar.
 */
final readonly class NullCheck implements Expression
{
    public function __construct(public Expression $operand, public bool $negated = false)
    {
    }
}
