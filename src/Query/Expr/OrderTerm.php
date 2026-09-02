<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * Um critério de ordenação.
 *
 * Não é uma `Expression`: `ORDER BY` não aceita uma expressão booleana qualquer no
 * lugar de um termo, e deixar o tipo separado é o que impede `where(desc($u->id))` de
 * compilar. Erro de posição vira erro de tipo em vez de SQL inválido.
 */
final readonly class OrderTerm
{
    public function __construct(
        public Expression $expression,
        public OrderDirection $direction = OrderDirection::Asc,
        public ?NullsPlacement $nulls = null,
    ) {
    }
}
