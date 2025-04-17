<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class Appointment extends Model {
    public const table = "appointment";

    public function __construct() {
        parent::__construct(self::table,self::class);
    }

    public static function table() {
        return (new Table(self::table, comment:"Appointments table"))->isAutoIncrement()
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("Appointment ID"))
                ->addColumn((new Column("user_id", "INT"))->isNotNull()->setComment("User ID who made the appointment"))
                ->addForeignKey(User::table, column:"user_id")
                ->addColumn((new Column("schedule_id", "INT"))->isNotNull()->setComment("Schedule ID"))
                ->addForeignKey(Schedule::table, column:"schedule_id")
                ->addColumn((new Column("client_id", "INT"))->setComment("Client ID"))
                ->addForeignKey(Client::table, column:"client_id")
                ->addColumn((new Column("employee_id", "INT"))->isNotNull()->setComment("Employee ID assigned to appointment"))
                ->addForeignKey(Employee::table, column:"employee_id")
                ->addColumn((new Column("start_date", "DATETIME"))->isNotNull()->setComment("Appointment start date and time"))
                ->addColumn((new Column("end_date", "DATETIME"))->isNotNull()->setComment("Appointment end date and time"))
                ->addColumn((new Column("status", "VARCHAR", 20))->isNotNull()->setDefault("scheduled")->setComment("Appointment status"));
    }

    public static function seed() {
        // Seed method intentionally left empty for tests
    }
}