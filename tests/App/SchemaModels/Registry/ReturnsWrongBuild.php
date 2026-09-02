<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Registry;

/**
 * Tem `build()`, mas ele devolve a coisa errada.
 */
final class ReturnsWrongBuild
{
    public static function table(): object
    {
        return new class () {
            public function build(): string
            {
                return 'não é um TableDefinition';
            }
        };
    }
}
