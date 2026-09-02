<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Registry;

/**
 * `table()` que não devolve objeto nenhum.
 */
final class ReturnsScalar
{
    public static function table(): string
    {
        return 'returns_scalar';
    }
}
