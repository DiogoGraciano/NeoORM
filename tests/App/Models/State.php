<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\Generated\StateTable;

/**
 * @extends Model<StateTable>
 */
class State extends Model
{
    public const table = "state";

    public static function table(): Table
    {
        return Table::make(self::table, comment: "States table")
            ->columns([
                'id' => Col::id()->comment("State ID"),
                'name' => Col::varchar(120)->notNull()->comment("State name"),
                'abbreviation' => Col::varchar(2)->notNull()->comment("State abbreviation"),
                'country' => Col::int()->notNull()->references(Country::class)->comment("Country ID of the state"),
                'ibge' => Col::int()->unique()->comment("IBGE ID of the state"),
                'area_code' => Col::varchar(50)->comment("Area codes separated by comma"),
            ]);
    }
}
