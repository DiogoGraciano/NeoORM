<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Tests\Generated\AppointmentTable;

/**
 * @extends Model<AppointmentTable>
 */
class Appointment extends Model
{
    public const table = "appointment";

    public static function table(): Table
    {
        return Table::make(self::table, comment: "Appointments table")
            ->columns([
                'id' => Col::id()->comment("Appointment ID"),
                'user_id' => Col::int()->notNull()->references(User::class)->comment("User ID who made the appointment"),
                'schedule_id' => Col::int()->notNull()->references(Schedule::class)->comment("Schedule ID"),
                'client_id' => Col::int()->references(Client::class)->comment("Client ID"),
                'employee_id' => Col::int()->notNull()->references(Employee::class)->comment("Employee ID assigned to appointment"),
                'start_date' => Col::timestamp()->notNull()->comment("Appointment start date and time"),
                'end_date' => Col::timestamp()->notNull()->comment("Appointment end date and time"),
                'status' => Col::varchar(20)->notNull()->default("scheduled")->comment("Appointment status"),
            ]);
    }
}
