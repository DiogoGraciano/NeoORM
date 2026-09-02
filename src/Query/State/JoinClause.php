<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\State;

use Diogodg\Neoorm\Query\Expr\Expression;

/**
 * Um join: tipo, tabela e condição.
 *
 * A condição é obrigatória e é uma `Expression`, não um par de colunas. Assim
 * `->leftJoin($p, Op::and(eq($p->author_id, $u->id), eq($p->published, true)))` usa a
 * mesma construção do WHERE, em vez de um formato próprio limitado a igualdade — que
 * era a limitação do `addJoin($tabela, $col1, $col2)` antigo.
 */
final readonly class JoinClause
{
    public function __construct(
        public JoinType $type,
        public TableRef $table,
        public Expression $on,
    ) {
    }
}
