<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\Generated\ScheduleUserTable;

/**
 * @extends Model<ScheduleUserTable>
 */
class ScheduleUser extends Model
{
    public const table = "schedule_user";

    public static function table(): Table
    {
        return Table::make(self::table, comment: "Schedule User associations")
            ->columns([
                'id' => Col::id()->comment("Association ID"),
                'schedule_id' => Col::int()->notNull()->references(Schedule::class)->comment("Schedule ID"),
                'user_id' => Col::int()->notNull()->references(User::class)->comment("User ID"),
            ]);
    }
}
