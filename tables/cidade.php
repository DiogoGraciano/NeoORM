<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class cidade extends model {
    public const table = "cidade";

    public function __construct() {
        parent::__construct(self::table);
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de cidades"))
                ->addColumn((new column("id","INT"))->isPrimary()->setComment("ID da cidade"))
                ->addColumn((new column("nome","VARCHAR",120))->isNotNull()->setComment("Nome da cidade"))
                ->addColumn((new column("uf","INT"))->isNotNull()->isForeingKey(estado::table())->setComment("id da Uf da cidade"))
                ->addColumn((new column("ibge","INT"))->setComment("id do IBJE da cidade"));
    }
}