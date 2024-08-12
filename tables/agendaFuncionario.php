<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class agendaFuncionario extends model {
    public const table = "agenda_funcionario";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de vinculo entre agendamentos e funcionarios"))
                ->addColumn((new column("id_agenda","INT"))->isPrimary()->isForeingKey(agenda::table())->setComment("ID agenda"))
                ->addColumn((new column("id_funcionario","INT"))->isPrimary()->isForeingKey(funcionario::table())->setComment("ID Funcionario"));
    }
}