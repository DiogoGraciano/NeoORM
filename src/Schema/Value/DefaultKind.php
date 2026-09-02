<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Value;

/**
 * As quatro coisas distintas que "default" pode significar.
 *
 * O DSL antigo colapsava três delas num bool `$is_constant`, e o resultado era
 * que `DEFAULT CURRENT_TIMESTAMP` e `DEFAULT 'CURRENT_TIMESTAMP'` só se
 * distinguiam por um parâmetro posicional — e "sem cláusula DEFAULT" não se
 * distinguia de "DEFAULT NULL" de jeito nenhum.
 */
enum DefaultKind: string
{
    /** Sem cláusula DEFAULT na coluna. */
    case None = 'none';

    /** DEFAULT NULL, explícito. */
    case Null = 'null';

    /** Um valor: string, número ou booleano. Vai citado conforme o tipo. */
    case Literal = 'literal';

    /** Uma expressão SQL, como CURRENT_TIMESTAMP. Nunca vai citada. */
    case Expression = 'expression';
}
