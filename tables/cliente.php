<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class cliente extends model {
    public const table = "cliente";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de clientes"))
                ->addColumn((new column("id","INT"))->isPrimary()->setComment("ID do cliente"))
                ->addColumn((new column("nome","VARCHAR",300))->isNotNull()->setComment("Nome do cliente"))
                ->addColumn((new column("id_funcionario","INT"))->isForeingKey(funcionario::table())->setComment("id funcionario"));
    }
}