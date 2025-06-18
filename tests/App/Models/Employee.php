<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class Employee extends Model {
    public const table = "employee";

    public function __construct() {
        parent::__construct(self::table,self::class);
    }

    public static function table() {
        return (new Table(self::table, comment:"Employees table"))->isAutoIncrement()
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("Employee ID"))
                ->addColumn((new Column("user_id", "INT"))->isNotNull()->setComment("User ID associated with employee"))
                ->addForeignKey(User::table, column:"user_id")
                ->addColumn((new Column("name", "VARCHAR", 120))->isNotNull()->setComment("Employee name"))
                ->addColumn((new Column("tax_id", "VARCHAR", 20))->isUnique()->setComment("Employee tax ID"))
                ->addColumn((new Column("email", "VARCHAR", 120))->isNotNull()->isUnique()->setComment("Employee email"))
                ->addColumn((new Column("phone", "VARCHAR", 20))->setComment("Employee phone"))
                ->addColumn((new Column("start_time", "TIME"))->isNotNull()->setComment("Employee start time"))
                ->addColumn((new Column("end_time", "TIME"))->isNotNull()->setComment("Employee end time"))
                ->addColumn((new Column("lunch_start", "TIME"))->setComment("Employee lunch start time"))
                ->addColumn((new Column("lunch_end", "TIME"))->setComment("Employee lunch end time"))
                ->addColumn((new Column("days", "VARCHAR", 20))->isNotNull()->setComment("Working days (comma separated)"));
    }

    public static function seed() {
        // Seed method intentionally left empty for tests
    }
}