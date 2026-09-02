<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Recursive\Billing;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\App\SchemaModels\Recursive\Root;

/**
 * Um nível abaixo do root, e dependendo de uma tabela que mora acima dele.
 */
final class Invoice
{
    public const table = 'rec_invoice';

    public static function table(): Table
    {
        return Table::make(self::table)
            ->columns([
                'id' => Col::id(),
                'root' => Col::fk(Root::class),
            ]);
    }
}
