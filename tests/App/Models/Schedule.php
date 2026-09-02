<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\Generated\ScheduleTable;

/**
 * @extends Model<ScheduleTable>
 */
class Schedule extends Model
{
    public const table = "schedule";

    public static function table(): Table
    {
        return Table::make(self::table, comment: "Schedules table")
            ->columns([
                'id' => Col::id()->comment("Schedule ID"),
                'name' => Col::varchar(120)->notNull()->comment("Schedule name"),
                'company_id' => Col::int()->notNull()->comment("Company ID"),
                'employee_id' => Col::int()->references(Employee::class)->comment("Default employee ID for this schedule"),
            ]);
    }
}
