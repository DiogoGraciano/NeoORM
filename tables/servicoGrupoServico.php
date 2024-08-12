<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class servicoGrupoServico extends model {
    public const table = "servico_grupo_servico";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de relacionamento entre grupos de serviços e serviços"))
                ->addColumn((new column("id_grupo_servico", "INT"))->isPrimary()->isNotNull()->setComment("ID do grupo de serviço")->isForeingKey(grupoServico::table()))
                ->addColumn((new column("id_servico", "INT"))->isPrimary()->isNotNull()->setComment("ID do serviço")->isForeingKey(servico::table()));
    }
}