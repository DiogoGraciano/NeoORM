<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * As funções SQL que a biblioteca sabe escrever.
 *
 * Conjunto fechado pelo mesmo motivo de {@see ComparisonOp}: nome de função vai
 * direto para o SQL e nunca pode ser bind. Aceitar `string` aqui reabriria, num
 * lugar novo, exatamente o buraco que a validação de operadores fechou.
 *
 * Estas oito são as que se escrevem igual nos dois bancos. Qualquer outra passa
 * por `sql()`, que declara no nome que a responsabilidade é de quem chama.
 */
enum SqlFunction: string
{
    case Count = 'COUNT';
    case Sum = 'SUM';
    case Avg = 'AVG';
    case Min = 'MIN';
    case Max = 'MAX';
    case Lower = 'LOWER';
    case Upper = 'UPPER';
    case Coalesce = 'COALESCE';

    /**
     * Se `DISTINCT` faz sentido dentro dos parênteses.
     *
     * `COUNT(DISTINCT x)` é comum; `LOWER(DISTINCT x)` não é SQL. Sem esta
     * distinção o construtor de `FuncCall` aceitaria a combinação e o erro só
     * apareceria no banco, como erro de sintaxe sem contexto.
     */
    public function acceptsDistinct(): bool
    {
        return match ($this) {
            self::Count, self::Sum, self::Avg, self::Min, self::Max => true,
            self::Lower, self::Upper, self::Coalesce => false,
        };
    }

    /**
     * Se a função agrega linhas — o que decide se a consulta precisa de GROUP BY.
     */
    public function isAggregate(): bool
    {
        return match ($this) {
            self::Count, self::Sum, self::Avg, self::Min, self::Max => true,
            self::Lower, self::Upper, self::Coalesce => false,
        };
    }
}
