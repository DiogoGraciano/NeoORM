<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Recursive\Billing\Deep;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\App\SchemaModels\Recursive\Billing\Invoice;

/**
 * Dois níveis abaixo: a recursão não pode parar no primeiro.
 */
final class Line
{
    public const table = 'rec_line';

    public static function table(): Table
    {
        return Table::make(self::table)
            ->columns([
                'id' => Col::id(),
                'invoice' => Col::fk(Invoice::class),
            ]);
    }
}
