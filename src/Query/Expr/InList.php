<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * `a IN (…)` e `a NOT IN (…)`.
 *
 * O conjunto é uma lista de expressões ou uma expressão só — a segunda forma é o
 * caso da subconsulta, `IN (SELECT …)`, que chega aqui como um nó único.
 */
final readonly class InList implements Expression
{
    /** @var list<Expression>|Expression */
    public array|Expression $values;

    /**
     * @param list<Expression>|Expression $values
     */
    public function __construct(
        public Expression $left,
        array|Expression $values,
        public bool $negated = false,
    ) {
        // `IN ()` não é SQL válido em nenhum dos dois bancos, e o erro que o banco
        // devolve não diz qual coluna. Pior: quem escreve `inArray($u->id, $ids)`
        // com `$ids` vindo de um filtro vazio quase sempre queria "nenhuma linha",
        // e passar direto produziria erro de sintaxe em vez de zero resultados.
        // Recusar aqui força a decisão a ser explícita em quem chama.
        if (is_array($values) && $values === []) {
            $coluna = $left instanceof ColumnRef ? " para {$left->qualified()}" : '';

            throw new \InvalidArgumentException(
                "Lista vazia em IN{$coluna}. `IN ()` não é SQL válido. Se a lista vazia "
                . 'significa "nenhum resultado", trate isso antes de montar a consulta.',
            );
        }

        $this->values = $values;
    }
}
