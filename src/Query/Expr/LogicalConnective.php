<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * Como um `Logical` junta os operandos.
 *
 * Só `AND` e `OR`. `XOR` fica de fora porque existe no MySQL e não no PostgreSQL —
 * e uma construção que só funciona em metade dos bancos suportados quebraria em
 * produção no banco que não foi usado no desenvolvimento. Quem precisa dela usa
 * `sql()`, que é a via de escape declarada.
 */
enum LogicalConnective: string
{
    case And = 'AND';
    case Or = 'OR';
}
