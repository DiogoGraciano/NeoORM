<?php

namespace Diogodg\Neoorm\Enums;

use Exception;

/**
 * Operadores de comparação permitidos em filtros (WHERE/HAVING) e joins.
 *
 * Esta enum é a allowlist do ORM: qualquer operador fora dela é recusado antes
 * de chegar ao SQL. Operadores nunca são parametrizáveis pelo PDO, então eles
 * precisam ser validados por lista fechada.
 */
enum LogicalOperator: string
{
    case EQUAL = '=';
    case NOT_EQUAL = '!=';
    case DIFFERENT = '<>';
    case GREATER = '>';
    case GREATER_OR_EQUAL = '>=';
    case LESS = '<';
    case LESS_OR_EQUAL = '<=';
    case LIKE = 'LIKE';
    case NOT_LIKE = 'NOT LIKE';
    case IN = 'IN';
    case NOT_IN = 'NOT IN';
    case BETWEEN = 'BETWEEN';
    case NOT_BETWEEN = 'NOT BETWEEN';

    /**
     * Normaliza e valida um operador vindo do chamador.
     *
     * @throws Exception Se o operador não estiver na allowlist.
     */
    public static function fromMixed(string|self $operator): self
    {
        if ($operator instanceof self) {
            return $operator;
        }

        // Normaliza espaçamento interno ("not   in" => "NOT IN")
        $normalized = strtoupper(preg_replace('/\s+/', ' ', trim($operator)));

        $case = self::tryFrom($normalized);

        if ($case === null) {
            throw new Exception("Operador inválido: {$operator}");
        }

        return $case;
    }

    /**
     * Operadores que recebem uma lista de valores.
     */
    public function requiresList(): bool
    {
        return $this === self::IN || $this === self::NOT_IN;
    }

    /**
     * Operadores que recebem exatamente dois valores (limite inferior e superior).
     */
    public function requiresRange(): bool
    {
        return $this === self::BETWEEN || $this === self::NOT_BETWEEN;
    }
}
