<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class agenda extends model {
    public const table = "agenda";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de agendas"))
                ->addColumn((new column("id","INT"))->isPrimary()->setComment("ID agenda"))
                ->addColumn((new column("id_empresa","INT"))->isNotNull()->isForeingKey(empresa::table())->setComment("ID da tabela empresa"))
                ->addColumn((new column("nome","VARCHAR",250))->isNotNull()->setComment("Nome da agenda"))
                ->addColumn((new column("codigo","VARCHAR",7))->isNotNull()->setComment("Codigo da agenda"));
    }
}