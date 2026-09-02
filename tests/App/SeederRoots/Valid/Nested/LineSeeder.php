<?php

declare(strict_types=1);

namespace Tests\App\SeederRoots\Valid\Nested;

use Diogodg\Neoorm\Migrations\Seed\Seeder;
use Diogodg\Neoorm\Query\Executor;
use Tests\App\SchemaModels\Recursive\Billing\Deep\Line;
use Tests\App\SeederRoots\Valid\RootSeeder;

/**
 * Numa subpasta: o loader de seeders também é recursivo, pela mesma razão que o de
 * models — um projeto grande organiza os dois por domínio.
 */
final class LineSeeder extends Seeder
{
    /** Deixe `true` para o teste de rollback. */
    public static bool $explode = false;

    public static function table(): string
    {
        return Line::table;
    }

    public function run(Executor $db): void
    {
        if (self::$explode) {
            throw new \RuntimeException('seed quebrou no meio');
        }

        RootSeeder::$ran[] = self::table();
    }
}
