<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class pais extends model {
    public const table = "pais";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de paises"))
                ->addColumn((new column("id","INT"))->isPrimary()->setComment("ID da pais"))
                ->addColumn((new column("nome","VARCHAR",250))->isNotNull()->setComment("Nome do pais"))
                ->addColumn((new column("nome_internacial","VARCHAR",250))->isNotNull()->setComment("nome internacial do pais"));
    }

    public static function seed(){
        $object = new self;
        if(!$object->addLimit(1)->selectColumns("id")){
            $object->nome = "Brasil";
            $object->nome_internacial = "Brazil";
            $object->store();
        }
    }
}