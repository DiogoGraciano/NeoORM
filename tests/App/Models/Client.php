<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\Generated\ClientTable;

/**
 * @extends Model<ClientTable>
 */
class Client extends Model
{
    public const table = "client";

    public static function table(): Table
    {
        return Table::make(self::table, comment: "Countries table")
            ->columns([
                'id' => Col::id()->comment("Country ID"),
                'name' => Col::varchar(120)->notNull()->comment("Country name"),
            ]);
    }
}
