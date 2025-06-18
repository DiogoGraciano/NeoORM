<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class ScheduleUser extends Model {
    public const table = "schedule_user";

    public function __construct() {
        parent::__construct(self::table,self::class);
    }

    public static function table() {
        return (new Table(self::table, comment:"Schedule User associations"))->isAutoIncrement()
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("Association ID"))
                ->addColumn((new Column("schedule_id", "INT"))->isNotNull()->setComment("Schedule ID"))
                ->addForeignKey(Schedule::table, column:"schedule_id")
                ->addColumn((new Column("user_id", "INT"))->isNotNull()->setComment("User ID"))
                ->addForeignKey(User::table, column:"user_id");
    }

    public static function seed() {
        // Seed method intentionally left empty for tests
    }
}