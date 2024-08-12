<?php
namespace app\db\tables;

use app\db\abstract\model;
use app\db\migrations\table;
use app\db\migrations\column;

class agendamentoItem extends model {
    public const table = "agendamento_item";

    public function __construct() {
        parent::__construct(self::table,get_class($this));
    }

    public static function table(){
        return (new table(self::table,comment:"Tabela de itens agendamentos"))
                ->addColumn((new column("id","INT"))->isPrimary()->setComment("ID do item"))
                ->addColumn((new column("id_agendamento","INT"))->isNotNull()->isForeingKey(agendamento::table())->setComment("ID agendamento"))
                ->addColumn((new column("id_servico","INT"))->isNotNull()->isForeingKey(servico::table())->setComment("ID serviço"))
                ->addColumn((new column("qtd_item","INT"))->isNotNull()->setComment("QTD de serviços"))
                ->addColumn((new column("tempo_item","TIME"))->isNotNull()->setComment("Tempo total do serviço"))
                ->addColumn((new column("total_item","DECIMAL","10,2"))->isNotNull()->setComment("Valor do serviço"));
    }
}