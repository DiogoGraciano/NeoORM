<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Exception;

use RuntimeException;

/**
 * Erro ao montar ou compilar uma consulta.
 *
 * Separado das exceções de `Migrations\` de propósito: quem trata erro de consulta em
 * tempo de request não quer capturar, no mesmo `catch`, uma falha de migração.
 */
class QueryException extends RuntimeException
{
}
