<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class ScheduleEmployee extends Model {
    public const table = "schedule_employee";

    public function __construct() {
        parent::__construct(self::table,self::class);
    }

    public static function table() {
        return (new Table(self::table, comment:"Schedule Employee associations"))->isAutoIncrement()
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("Association ID"))
                ->addColumn((new Column("schedule_id", "INT"))->isNotNull()->setComment("Schedule ID"))
                ->addForeignKey(Schedule::table, column:"schedule_id")
                ->addColumn((new Column("employee_id", "INT"))->isNotNull()->setComment("Employee ID"))
                ->addForeignKey(Employee::table, column:"employee_id");
    }

    public static function seed() {
        // Seed method intentionally left empty for tests
    }
}