<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class servico extends model {
    public const table = "servico";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de serviços"))
                ->addColumn((new column("id", "INT"))->isPrimary()->isNotNull()->setComment("ID do serviço"))
                ->addColumn((new column("nome", "VARCHAR", 250))->isNotNull()->setComment("Nome do serviço"))
                ->addColumn((new column("valor", "DECIMAL", "14,2"))->isNotNull()->setComment("Valor do serviço"))
                ->addColumn((new column("tempo", "TIME"))->isNotNull()->setComment("Tempo do serviço"))
                ->addColumn((new column("id_empresa", "INT"))->isNotNull()->setComment("ID da empresa"));
    }

   
}