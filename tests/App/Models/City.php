<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class City extends Model {
    public const table = "city";

    public function __construct() {
        parent::__construct(self::table,self::class);
    }

    public static function table() {
        return (new Table(self::table, comment:"Cities table"))->isAutoIncrement()
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("City ID"))
                ->addColumn((new Column("name", "VARCHAR", 120))->isNotNull()->setComment("City name"))
                ->addColumn((new Column("state", "INT"))->isNotNull()->setComment("State ID of the city"))
                ->addForeignKey(State::table, column:"state")
                ->addColumn((new Column("ibge", "INT"))->isUnique()->setComment("IBGE ID of the city"));
    }

    public static function seed() {
        // Seed method intentionally left empty for tests
    }
}