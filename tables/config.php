<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class config extends model {
    public const table = "config";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de configurações"))
                ->addColumn((new column("id","INT"))->isPrimary()->setComment("ID Config"))
                ->addColumn((new column("id_empresa","INT"))->isNotNull()->isForeingKey(empresa::table(),"id")->setComment("ID da tabela empresa"))
                ->addColumn((new column("identificador","VARCHAR",30))->isNotNull()->isUnique()->setComment("Identificador da configuração"))
                ->addColumn((new column("configuracao","BLOB"))->isNotNull()->setComment("Configuração"));
    }
}