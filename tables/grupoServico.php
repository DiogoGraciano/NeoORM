<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class grupoServico extends model {
    public const table = "grupo_servico";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de grupos de serviços"))
                ->addColumn((new column("id","INT"))->isPrimary()->isNotNull()->setComment("ID do grupo de serviços"))
                ->addColumn((new column("id_empresa","INT"))->isForeingKey(empresa::table())->isNotNull()->setComment("ID da empresa"))
                ->addColumn((new column("nome", "VARCHAR", 250))->isNotNull()->setComment("Nome do grupo de serviços"));
    }
}