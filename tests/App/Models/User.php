<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\Generated\UsersTable;

/**
 * @extends Model<UsersTable>
 */
class User extends Model
{
    public const table = "users";

    public static function table(): Table
    {
        return Table::make(self::table, comment: "Users table")
            ->columns([
                'id' => Col::id()->comment("User ID"),
                'name' => Col::varchar(120)->notNull()->comment("User name"),
                'email' => Col::varchar(120)->notNull()->unique()->comment("User email"),
                'phone' => Col::varchar(20)->comment("User phone"),
                'tax_id' => Col::varchar(20)->unique()->comment("User tax ID"),
            ]);
    }
}
