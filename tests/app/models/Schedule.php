<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class Schedule extends Model {
    public const table = "schedule";

    public function __construct() {
        parent::__construct(self::table,self::class);
    }

    public static function table() {
        return (new Table(self::table, comment:"Schedules table"))->isAutoIncrement()
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("Schedule ID"))
                ->addColumn((new Column("name", "VARCHAR", 120))->isNotNull()->setComment("Schedule name"))
                ->addColumn((new Column("company_id", "INT"))->isNotNull()->setComment("Company ID"))
                ->addColumn((new Column("employee_id", "INT"))->setComment("Default employee ID for this schedule"))
                ->addForeignKey(Employee::table, column:"employee_id");
    }

    public static function seed() {
        // Seed method intentionally left empty for tests
    }
}