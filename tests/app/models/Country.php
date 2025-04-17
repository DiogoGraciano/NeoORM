<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class Country extends Model {
    public const table = "country";

    public function __construct() {
        parent::__construct(self::table,self::class);
    }

    public static function table() {
        return (new Table(self::table, comment:"Countries table"))->isAutoIncrement()
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("Country ID"))
                ->addColumn((new Column("name", "VARCHAR", 120))->isNotNull()->setComment("Country name"))
                ->addColumn((new Column("abbreviation", "VARCHAR", 2))->isNotNull()->setComment("Country abbreviation"));
    }

    public static function seed() {
        // Seed method intentionally left empty for tests
    }
}
