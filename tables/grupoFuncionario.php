<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class grupoFuncionario extends model {
    public const table = "grupo_funcionario";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de funcionarios"))
                ->addColumn((new column("id","INT"))->isPrimary()->isNotNull()->setComment("ID do funcionario"))
                ->addColumn((new column("id_empresa","INT"))->isNotNull()->isForeingKey(empresa::table())->setComment("ID da tabela empresa"))
                ->addColumn((new column("nome", "VARCHAR", 200))->isNotNull()->setComment("Nome do grupo de funcionarios"));
    }
}