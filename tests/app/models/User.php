<?php

namespace Tests\App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class User extends Model {
    public const table = "user";

    public function __construct() {
        parent::__construct(self::table,self::class);
    }

    public static function table(){
        return (new Table(self::table, comment:"Users table"))->isAutoIncrement()
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("User ID"))
                ->addColumn((new Column("name", "VARCHAR", 120))->isNotNull()->setComment("User name"))
                ->addColumn((new Column("email", "VARCHAR", 120))->isNotNull()->isUnique()->setComment("User email"))
                ->addColumn((new Column("phone", "VARCHAR", 20))->setComment("User phone"))
                ->addColumn((new Column("tax_id", "VARCHAR", 20))->isUnique()->setComment("User tax ID"));
    }

    public static function seed() {
        // Seed method intentionally left empty for tests
    }
}