<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * Os operadores de comparação binária, num conjunto fechado.
 *
 * Ser um enum é a diferença entre este sistema e o antigo, onde o operador era
 * `string` e a proteção era uma allowlist consultada em runtime — que existia
 * justamente porque `addFilter('id', $_GET['op'], $v)` aceitava `"= 1 OR 1=1 --"`
 * e alterava a semântica da query inteira. Aqui não há caminho de string: ou o
 * valor é um destes casos, ou o código não compila.
 *
 * `IN`, `BETWEEN` e `IS NULL` não estão aqui de propósito. O enum antigo os
 * misturava com a comparação binária e precisava de `requiresList()` e
 * `requiresRange()` para desempatar em runtime; aqui cada um é um nó com a própria
 * forma, e a aridade errada vira erro de tipo.
 */
enum ComparisonOp: string
{
    case Equal = '=';
    case NotEqual = '<>';
    case LessThan = '<';
    case LessOrEqual = '<=';
    case GreaterThan = '>';
    case GreaterOrEqual = '>=';
    case Like = 'LIKE';
    case NotLike = 'NOT LIKE';

    /**
     * `ILIKE` é o único caso cujo SQL não é o próprio valor.
     *
     * O PostgreSQL tem o operador; o MySQL não tem nada equivalente e precisa de
     * `LOWER() LIKE LOWER()`. Por isso o compilador desvia para
     * `Dialect::caseInsensitiveLike()` em vez de interpolar `->value`.
     */
    case ILike = 'ILIKE';

    public function isCaseInsensitive(): bool
    {
        return $this === self::ILike;
    }

    public function negated(): self
    {
        return match ($this) {
            self::Equal => self::NotEqual,
            self::NotEqual => self::Equal,
            self::LessThan => self::GreaterOrEqual,
            self::LessOrEqual => self::GreaterThan,
            self::GreaterThan => self::LessOrEqual,
            self::GreaterOrEqual => self::LessThan,
            self::Like => self::NotLike,
            self::NotLike => self::Like,
            // Negar ILIKE daria "NOT ILIKE", que não existe como operador único no
            // caminho do MySQL — lá a expressão inteira já é uma chamada de função.
            // Quem precisa disso envolve em Op::not(), que é explícito.
            self::ILike => throw new \LogicException(
                'ILIKE não tem operador negado próprio. Use Op::not() em volta da comparação.',
            ),
        };
    }
}
