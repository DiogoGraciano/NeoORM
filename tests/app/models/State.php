<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class State extends Model {
    public const table = "state";

    public function __construct() {
        parent::__construct(self::table,self::class);
    }

    public static function table() {
        return (new Table(self::table, comment:"States table"))->isAutoIncrement()
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("State ID"))
                ->addColumn((new Column("name", "VARCHAR", 120))->isNotNull()->setComment("State name"))
                ->addColumn((new Column("abbreviation", "VARCHAR", 2))->isNotNull()->setComment("State abbreviation"))
                ->addColumn((new Column("country", "INT"))->isNotNull()->setComment("Country ID of the state"))
                ->addForeignKey(Country::table, column:"country")
                ->addColumn((new Column("ibge", "INT"))->isUnique()->setComment("IBGE ID of the state"))
                ->addColumn((new Column("area_code", "VARCHAR", 50))->setComment("Area codes separated by comma"));
    }

    public static function seed() {
        // Seed method intentionally left empty for tests
    }
}