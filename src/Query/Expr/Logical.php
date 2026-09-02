<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * Vários operandos ligados por `AND` ou `OR`.
 *
 * N-ário, e não binário: `Op::and($a, $b, $c)` é um nó com três operandos, não dois
 * nós encadeados. A diferença aparece no SQL gerado — `(a AND b AND c)` em vez de
 * `((a AND b) AND c)` — e em teste, onde a árvore de uma condição de cinco termos é
 * um objeto e não cinco.
 *
 * Aninhamento do mesmo conectivo é achatado no construtor. `Op::and(Op::and($a,$b), $c)`
 * vira um único nó de três, porque `AND` é associativo e a estrutura aninhada não
 * significava nada além de parênteses a mais. Conectivos diferentes NÃO são achatados:
 * ali o parêntese muda o resultado.
 */
final readonly class Logical implements Expression
{
    /** @var non-empty-list<Expression> */
    public array $operands;

    /**
     * @param list<Expression> $operands
     */
    public function __construct(public LogicalConnective $connective, array $operands)
    {
        if ($operands === []) {
            throw new \InvalidArgumentException(
                "{$connective->value} sem operandos. Uma condição vazia produziria `WHERE ()`, "
                . 'que não é SQL — e quando alguém chega aqui é porque montou a lista de '
                . 'condições dinamicamente e ela veio vazia, caso que precisa de decisão '
                . 'explícita: sem filtro nenhum ou nenhum resultado?',
            );
        }

        $flattened = [];

        foreach ($operands as $operand) {
            if ($operand instanceof self && $operand->connective === $connective) {
                foreach ($operand->operands as $inner) {
                    $flattened[] = $inner;
                }

                continue;
            }

            $flattened[] = $operand;
        }

        /** @var non-empty-list<Expression> $flattened */
        $this->operands = $flattened;
    }
}
