<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Runtime\Casting;

use RuntimeException;

/**
 * Valor do banco que não cabe no tipo declarado da coluna.
 *
 * Sempre nomeia a coluna. É a diferença entre "Cannot parse date" e
 * "users.created_at: valor temporal inválido '0000-00-00'" — a primeira manda você
 * procurar, a segunda diz onde está.
 */
final class CastException extends RuntimeException
{
    public static function for(string $context, string $problem, mixed $value): self
    {
        return new self("{$context}: {$problem} " . self::describe($value));
    }

    private static function describe(mixed $value): string
    {
        return match (true) {
            is_string($value) => "'" . (strlen($value) > 64 ? substr($value, 0, 61) . '...' : $value) . "'",
            is_scalar($value) => var_export($value, true),
            $value === null => 'null',
            is_resource($value) => 'resource(' . get_resource_type($value) . ')',
            is_object($value) => 'objeto ' . $value::class,
            default => get_debug_type($value),
        };
    }
}
