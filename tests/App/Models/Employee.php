<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\Generated\EmployeeTable;

/**
 * @extends Model<EmployeeTable>
 */
class Employee extends Model
{
    public const table = "employee";

    public static function table(): Table
    {
        return Table::make(self::table, comment: "Employees table")
            ->columns([
                'id' => Col::id()->comment("Employee ID"),
                'user_id' => Col::int()->notNull()->references(User::class)->comment("User ID associated with employee"),
                'name' => Col::varchar(120)->notNull()->comment("Employee name"),
                'tax_id' => Col::varchar(20)->unique()->comment("Employee tax ID"),
                'email' => Col::varchar(120)->notNull()->unique()->comment("Employee email"),
                'phone' => Col::varchar(20)->comment("Employee phone"),
                'start_time' => Col::time()->notNull()->comment("Employee start time"),
                'end_time' => Col::time()->notNull()->comment("Employee end time"),
                'lunch_start' => Col::time()->comment("Employee lunch start time"),
                'lunch_end' => Col::time()->comment("Employee lunch end time"),
                'days' => Col::varchar(20)->notNull()->comment("Working days (comma separated)"),
            ]);
    }
}
