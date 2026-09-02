<?php

declare(strict_types=1);

namespace Tests\App\SeederRoots\Valid;

use Diogodg\Neoorm\Migrations\Seed\Seeder;
use Diogodg\Neoorm\Query\Executor;
use Tests\App\SchemaModels\Recursive\Billing\Invoice;

/**
 * Depende de `rec_root`. Guarda o executor que recebeu, que é o que prova que ele é o
 * `Tx` da transação em vez de uma conexão que o seeder abriu por conta própria.
 */
final class InvoiceSeeder extends Seeder
{
    public static ?Executor $seen = null;

    public static function table(): string
    {
        return Invoice::table;
    }

    public function run(Executor $db): void
    {
        self::$seen = $db;
        RootSeeder::$ran[] = self::table();
    }
}
