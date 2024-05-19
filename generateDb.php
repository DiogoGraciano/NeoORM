<?php
require __DIR__.DIRECTORY_SEPARATOR."configDb.php";
require str_replace("\app\db","",__DIR__.DIRECTORY_SEPARATOR."vendor".DIRECTORY_SEPARATOR."autoload.php");

use app\db\tableDb;
use app\db\columnDb;

try{

$recreate = false;

$configTb = new tableDb("config",comment:"Tabela de configurações");
$configTb->beginTransaction();
$configTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID agenda"))
        ->addColumn((new columnDb("id_empresa","INT"))->isNotNull()->isForeingKey($empresaTb,"id")->setComment("ID da tabela empresa"))
        ->addColumn((new columnDb("identificador","VARCHAR",30))->isNotNull()->isUnique()->setComment("Identificador da configuração"))
        ->addColumn((new columnDb("configuracao","BLOB"))->isNotNull()->setComment("Configuração"))
        ->execute($recreate);
$configTb->commit();

$agendaTb = new tableDb("agenda",comment:"Tabela de agendas");
$agendaTb->beginTransaction();
$agendaTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID agenda"))
        ->addColumn((new columnDb("id_empresa","INT"))->isNotNull()->isForeingKey($empresaTb)->setComment("ID da tabela empresa"))
        ->addColumn((new columnDb("nome","VARCHAR",250))->isNotNull()->setComment("Nome da agenda"))
        ->addColumn((new columnDb("codigo","VARCHAR",7))->isNotNull()->setComment("Codigo da agenda"))
        ->execute($recreate);
$agendaTb->commit();

$agendamentoTb = new tableDb("agendamento",comment:"Tabela de agendamentos");
$agendamentoTb->beginTransaction();
$agendamentoTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID agendamento"))
            ->addColumn((new columnDb("id_agenda","INT"))->isNotNull()->isForeingKey($agendaTb)->setComment("ID da tabela agenda"))
            ->addColumn((new columnDb("id_usuario","INT"))->isForeingKey($usuarioTb)->setComment("ID da tabela usuario"))
            ->addColumn((new columnDb("id_cliente","INT"))->isForeingKey($clienteTb)->setComment("ID da tabela cliente"))
            ->addColumn((new columnDb("id_funcionario","INT"))->isForeingKey($funcionarioTb)->setComment("ID da tabela funcionario"))
            ->addColumn((new columnDb("titulo","VARCHAR",150))->isNotNull()->setComment("titulo do agendamento"))
            ->addColumn((new columnDb("dt_ini","DATETIME"))->isNotNull()->setComment("Data inicial de agendamento"))
            ->addColumn((new columnDb("dt_fim","DATETIME"))->isNotNull()->setComment("Data final de agendamento"))
            ->addColumn((new columnDb("cor","VARCHAR",7))->isNotNull()->setComment("Cor do agendamento"))
            ->addColumn((new columnDb("total","DECIMAL","10,2"))->isNotNull()->setComment("Total do agendamento"))
            ->addColumn((new columnDb("obs","VARCHAR",400))->setComment("Observações do agendamento"));
$agendamentoTb->execute($recreate);
$agendamentoTb->commit();

$agendamentoItemTb = new tableDb("agendamento_item",comment:"Tabela de itens agendamentos");
$agendamentoItemTb->beginTransaction();
$agendamentoItemTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID do item"))
            ->addColumn((new columnDb("id_agendamento","INT"))->isNotNull()->isForeingKey($agendamentoTb)->setComment("ID agendamento"))
            ->addColumn((new columnDb("id_servico","INT"))->isNotNull()->isForeingKey($servicotb)->setComment("ID serviço"))
            ->addColumn((new columnDb("qtd_item","INT"))->isNotNull()->setComment("QTD de serviços"))
            ->addColumn((new columnDb("tempo_item","TIME"))->isNotNull()->setComment("Tempo total do serviço"))
            ->addColumn((new columnDb("total_item","DECIMAL","10,2"))->isNotNull()->setComment("Valor do serviço"));      
$agendamentoItemTb->execute($recreate);
$agendamentoItemTb->commit();

$agendamentoFuncionarioTb = new tableDb("agendamento_funcionario",comment:"Tabela de vinculo entre agendamentos e funcionarios");
$agendamentoFuncionarioTb->beginTransaction();
$agendamentoFuncionarioTb->addColumn((new columnDb("id_agenda","INT"))->isPrimary()->isForeingKey($agendaTb)->setComment("ID agenda"))
                         ->addColumn((new columnDb("id_funcionario","INT"))->isPrimary()->isForeingKey($funcionarioTb)->setComment("ID Funcionario"));
$agendamentoFuncionarioTb->execute($recreate);
$agendamentoFuncionarioTb->commit();

$agendamentoUsuarioTb = new tableDb("agendamento_usuario",comment:"Tabela de vinculo entre agendamentos e usuarios");
$agendamentoUsuarioTb->beginTransaction();
$agendamentoUsuarioTb->addColumn((new columnDb("id_agenda","INT"))->isPrimary()->isForeingKey($agendaTb)->setComment("ID agenda"))
                         ->addColumn((new columnDb("id_usuario","INT"))->isPrimary()->isForeingKey($usuarioTb)->setComment("ID Usuario"));
$agendamentoUsuarioTb->execute($recreate);
$agendamentoUsuarioTb->commit();


} catch(Exception $e) {
    $configTb->rollBack();
    echo $e->getMessage();
}

?>