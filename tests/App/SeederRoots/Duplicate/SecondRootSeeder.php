<?php

declare(strict_types=1);

namespace Tests\App\SeederRoots\Duplicate;

use Diogodg\Neoorm\Migrations\Seed\Seeder;
use Diogodg\Neoorm\Query\Executor;
use Tests\App\SchemaModels\Recursive\Root;

/**
 * O segundo seeder da mesma tabela. Uma tabela, um seeder — dois é ambiguidade sobre a
 * ordem, e a ordem entre eles não é derivável de nada (o grafo só ordena TABELAS).
 */
final class SecondRootSeeder extends Seeder
{
    public static function table(): string
    {
        return Root::table;
    }

    public function run(Executor $db): void
    {
    }
}
