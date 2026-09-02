<?php

declare(strict_types=1);

namespace Tests\App\SeederRoots\Duplicate;

use Diogodg\Neoorm\Migrations\Seed\Seeder;
use Diogodg\Neoorm\Query\Executor;
use Tests\App\SchemaModels\Recursive\Root;

final class FirstRootSeeder extends Seeder
{
    public static function table(): string
    {
        return Root::table;
    }

    public function run(Executor $db): void
    {
    }
}
