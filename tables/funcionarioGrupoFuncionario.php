<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class funcionarioGrupoFuncionario extends model {
    public const table = "funcionario_grupo_funcionario";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de relacionamento entre funcionarios e grupos de funcionarios"))
                ->addColumn((new column("id_funcionario","INT"))->isNotNull()->setComment("ID do funcionario")->isForeingKey(funcionario::table()))
                ->addColumn((new column("id_grupo_funcionario","INT"))->isNotNull()->setComment("ID do grupo de funcionarios")->isForeingKey(grupoFuncionario::table()));
    }
}