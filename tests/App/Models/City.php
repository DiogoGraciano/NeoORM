<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\Generated\CityTable;

/**
 * @extends Model<CityTable>
 */
class City extends Model
{
    public const table = "city";

    public static function table(): Table
    {
        return Table::make(self::table, comment: "Cities table")
            ->columns([
                'id' => Col::id()->comment("City ID"),
                'name' => Col::varchar(120)->notNull()->comment("City name"),
                'state' => Col::int()->notNull()->references(State::class)->comment("State ID of the city"),
                'ibge' => Col::int()->unique()->comment("IBGE ID of the city"),
            ]);
    }
}
