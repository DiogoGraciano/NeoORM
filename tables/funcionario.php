<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class funcionario extends model {
    public const table = "funcionario";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table, comment: "Tabela de funcionarios"))
                ->addColumn((new column("id","INT"))->isPrimary()->isNotNull()->setComment("ID do funcionario"))
                ->addColumn((new column("id_usuario","INT"))->isNotNull()->isForeingKey(usuario::table())->setComment("ID da tabela usuario"))
                ->addColumn((new column("nome", "VARCHAR", 200))->isNotNull()->setComment("Nome do funcionario"))
                ->addColumn((new column("cpf_cnpj", "VARCHAR", 14))->isNotNull()->setComment("CPF ou CNPJ do funcionario"))
                ->addColumn((new column("email", "VARCHAR", 200))->isNotNull()->setComment("Email do funcionario"))
                ->addColumn((new column("telefone", "VARCHAR", 13))->isNotNull()->setComment("Telefone do funcionario"))
                ->addColumn((new column("hora_ini", "TIME"))->isNotNull()->setComment("Horario inicial de atendimento"))
                ->addColumn((new column("hora_fim", "TIME"))->isNotNull()->setComment("Horario final de atendimento"))
                ->addColumn((new column("hora_almoco_ini", "TIME"))->isNotNull()->setComment("Horario inicial do almoco"))
                ->addColumn((new column("hora_almoco_fim", "TIME"))->isNotNull()->setComment("Horario final do almoco"))
                ->addColumn((new column("dias", "VARCHAR", 27))->isNotNull()->setComment("Dias de trabalho: dom,seg,ter,qua,qui,sex,sab"));
    }
}