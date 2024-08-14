<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class agendamento extends model {
    public const table = "agendamento";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de agendamentos"))
                ->addColumn((new column("id","INT"))->isPrimary()->setComment("ID agendamento"))
                ->addColumn((new column("id_agenda","INT"))->isNotNull()->isForeingKey(agenda::table())->setComment("ID da tabela agenda"))
                ->addColumn((new column("id_usuario","INT"))->isForeingKey(usuario::table())->setComment("ID da tabela usuario"))
                ->addColumn((new column("id_cliente","INT"))->isForeingKey(cliente::table())->setComment("ID da tabela cliente"))
                ->addColumn((new column("id_funcionario","INT"))->isForeingKey(funcionario::table())->setComment("ID da tabela funcionario"))
                ->addColumn((new column("titulo","VARCHAR",150))->isNotNull()->setComment("titulo do agendamento"))
                ->addColumn((new column("dt_ini","TIMESTAMP"))->isNotNull()->setComment("Data inicial de agendamento"))
                ->addColumn((new column("dt_fim","TIMESTAMP"))->isNotNull()->setComment("Data final de agendamento"))
                ->addColumn((new column("cor","VARCHAR",7))->setDefaut("#4267b2")->isNotNull()->setComment("Cor do agendamento"))
                ->addColumn((new column("total","DECIMAL","10,2"))->isNotNull()->setComment("Total do agendamento"))
                ->addColumn((new column("id_status","INT"))->isForeingKey(status::table())->isNotNull()->setComment("id do Status do agendamento"))
                ->addColumn((new column("obs","VARCHAR",400))->setComment("Observações do agendamento"))
                ->addIndex("getEventsbyFuncionario",["dt_ini","dt_fim","id_agenda","id_funcionario"]);
    }
}