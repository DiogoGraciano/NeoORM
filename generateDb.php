<?php
require __DIR__.DIRECTORY_SEPARATOR."configDb.php";
require str_replace("\app\db","",__DIR__.DIRECTORY_SEPARATOR."vendor".DIRECTORY_SEPARATOR."autoload.php");

use app\db\tableDb;
use app\db\columnDb;
use app\db\db;
use app\db\transactionManeger;

$recreate = false;

try{

transactionManeger::init();
transactionManeger::beginTransaction();

$empresaTb = new tableDb("empresa",comment:"Tabela de empresas");
$empresaTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID do cliente"))
        ->addColumn((new columnDb("nome","VARCHAR",300))->isNotNull()->isUnique()->setComment("Nome da empresa"))
        ->addColumn((new columnDb("email","VARCHAR",300))->isNotNull()->setComment("Email da empresa"))
        ->addColumn((new columnDb("telefone","VARCHAR",13))->isNotNull()->setComment("Telefone da empresa"))
        ->addColumn((new columnDb("cnpj","VARCHAR",14))->isNotNull()->setComment("CNPJ da empresa"))
        ->addColumn((new columnDb("razao","VARCHAR",300))->isNotNull()->isUnique()->setComment("Razão social da empresa"))
        ->addColumn((new columnDb("fantasia","VARCHAR",300))->isNotNull()->setComment("Nome fantasia da empresa"));
$empresaTb->execute($recreate);

$usuarioTb = new tableDb("usuario", comment: "Tabela de usuários");
$usuarioTb->addColumn((new columnDb("id", "INT"))->isPrimary()->isNotNull()->setComment("ID do usuário"))
          ->addColumn((new columnDb("nome", "VARCHAR", 500))->isNotNull()->setComment("Nome do usuário"))
          ->addColumn((new columnDb("cpf_cnpj", "VARCHAR", 14))->isUnique()->isNotNull()->setComment("CPF ou CNPJ do usuário"))
          ->addColumn((new columnDb("telefone", "VARCHAR", 11))->isNotNull()->setComment("Telefone do usuário"))
          ->addColumn((new columnDb("senha", "VARCHAR", 150))->isNotNull()->setComment("Senha do usuário"))
          ->addColumn((new columnDb("email", "VARCHAR", 200))->isUnique()->setComment("Email do usuário"))
          ->addColumn((new columnDb("tipo_usuario", "INT"))->isNotNull()->setComment("Tipo de usuário: 0 -> ADM, 1 -> empresa, 2 -> funcionario, 3 -> usuário, 4 -> cliente cadastrado"))
          ->addColumn((new columnDb("id_empresa", "INT"))->isForeingKey($empresaTb)->setComment("ID da empresa"))
          ->execute($recreate);

$funcionarioTb = new tableDb("funcionario", comment: "Tabela de funcionarios");
$funcionarioTb->addColumn((new columnDb("id", "INT"))->isPrimary()->isNotNull()->setComment("ID do funcionario"))
              ->addColumn((new columnDb("id_usuario", "INT"))->isNotNull()->isForeingKey($usuarioTb)->setComment("ID da tabela usuario"))
              ->addColumn((new columnDb("nome", "VARCHAR", 200))->isNotNull()->setComment("Nome do funcionario"))
              ->addColumn((new columnDb("cpf_cnpj", "VARCHAR", 14))->isNotNull()->setComment("CPF ou CNPJ do funcionario"))
              ->addColumn((new columnDb("email", "VARCHAR", 200))->isNotNull()->setComment("Email do funcionario"))
              ->addColumn((new columnDb("telefone", "VARCHAR", 13))->isNotNull()->setComment("Telefone do funcionario"))
              ->addColumn((new columnDb("hora_ini", "TIME"))->isNotNull()->setComment("Horario inicial de atendimento"))
              ->addColumn((new columnDb("hora_fim", "TIME"))->isNotNull()->setComment("Horario final de atendimento"))
              ->addColumn((new columnDb("hora_almoco_ini", "TIME"))->isNotNull()->setComment("Horario inicial do almoco"))
              ->addColumn((new columnDb("hora_almoco_fim", "TIME"))->isNotNull()->setComment("Horario final do almoco"))
              ->addColumn((new columnDb("dias", "VARCHAR", 27))->isNotNull()->setComment("Dias de trabalho: dom,seg,ter,qua,qui,sex,sab"))
              ->execute($recreate);

$grupoFuncionarioTb = new tableDb("grupo_funcionario", comment: "Tabela de grupos de funcionarios");
$grupoFuncionarioTb->addColumn((new columnDb("id", "INT"))->isPrimary()->isNotNull()->setComment("ID do grupo de funcionarios"))
                   ->addColumn((new columnDb("id_empresa", "INT"))->isNotNull()->setComment("ID da empresa"))
                   ->addColumn((new columnDb("nome", "VARCHAR", 250))->isNotNull()->setComment("Nome do grupo de funcionarios"))
                   ->execute($recreate);

$funcionarioGrupoFuncionarioTb = new tableDb("funcionario_grupo_funcionario", comment: "Tabela de relacionamento entre funcionarios e grupos de funcionarios");
$funcionarioGrupoFuncionarioTb->addColumn((new columnDb("id_funcionario", "INT"))->isNotNull()->setComment("ID do funcionario")->isForeingKey($funcionarioTb))
                              ->addColumn((new columnDb("id_grupo_funcionario", "INT"))->isNotNull()->setComment("ID do grupo de funcionarios")->isForeingKey($grupoFuncionarioTb))
                              ->execute($recreate);


$clienteTb = new tableDb("cliente",comment:"Tabela de clientes");
$clienteTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID do cliente"))
        ->addColumn((new columnDb("nome","VARCHAR",300))->isNotNull()->setComment("Nome do cliente"))
        ->addColumn((new columnDb("id_funcionario","INT"))->isForeingKey($funcionarioTb)->setComment("id funcionario"))
        ->execute($recreate);

$servicoTb = new tableDb("servico", comment: "Tabela de serviços");
$servicoTb->addColumn((new columnDb("id", "INT"))->isPrimary()->isNotNull()->setComment("ID do serviço"))
          ->addColumn((new columnDb("nome", "VARCHAR", 250))->isNotNull()->setComment("Nome do serviço"))
          ->addColumn((new columnDb("valor", "DECIMAL", "14,2"))->isNotNull()->setComment("Valor do serviço"))
          ->addColumn((new columnDb("tempo", "TIME"))->isNotNull()->setComment("Tempo do serviço"))
          ->addColumn((new columnDb("id_empresa", "INT"))->isNotNull()->setComment("ID da empresa"))
          ->execute($recreate);

$servicoFuncionarioTb = new tableDb("servico_funcionario", comment: "Tabela de relacionamento entre serviços e funcionários");
$servicoFuncionarioTb->addColumn((new columnDb("id_funcionario", "INT"))->isPrimary()->isNotNull()->setComment("ID do funcionário")->isForeingKey($funcionarioTb))
                     ->addColumn((new columnDb("id_servico", "INT"))->isPrimary()->isNotNull()->setComment("ID do serviço")->isForeingKey($servicoTb))
                     ->execute($recreate);

$grupoServicoTb = new tableDb("grupo_servico", comment: "Tabela de grupos de serviços");
$grupoServicoTb->addColumn((new columnDb("id", "INT"))->isPrimary()->isNotNull()->setComment("ID do grupo de serviços"))
        ->addColumn((new columnDb("id_empresa", "INT"))->isForeingKey($empresaTb)->isNotNull()->setComment("ID da empresa"))
        ->addColumn((new columnDb("nome", "VARCHAR", 250))->isNotNull()->setComment("Nome do grupo de serviços"))
        ->execute($recreate);

$servicoGrupoServicoTb = new tableDb("servico_grupo_servico", comment: "Tabela de relacionamento entre grupos de serviços e serviços");
$servicoGrupoServicoTb->addColumn((new columnDb("id_grupo_servico", "INT"))->isPrimary()->isNotNull()->setComment("ID do grupo de serviço")->isForeingKey($grupoServicoTb))
                      ->addColumn((new columnDb("id_servico", "INT"))->isPrimary()->isNotNull()->setComment("ID do serviço")->isForeingKey($servicoTb))
                      ->execute($recreate);

$agendaTb = new tableDb("agenda",comment:"Tabela de agendas");
$agendaTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID agenda"))
        ->addColumn((new columnDb("id_empresa","INT"))->isNotNull()->isForeingKey($empresaTb)->setComment("ID da tabela empresa"))
        ->addColumn((new columnDb("nome","VARCHAR",250))->isNotNull()->setComment("Nome da agenda"))
        ->addColumn((new columnDb("codigo","VARCHAR",7))->isNotNull()->setComment("Codigo da agenda"))
        ->execute($recreate);

$statusTb = new tableDb("status",comment:"Tabela de status");
$statusTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID agenda"))
        ->addColumn((new columnDb("nome","VARCHAR",250))->isNotNull()->setComment("Status do agendamento"))
        ->execute($recreate);

$object = new db("status");
if(!$object->addLimit(1)->selectColumns("id")){
        $object->nome = "Agendado";
        $status->store($object);
        $object->nome = "Finalizado";
        $status->store($object);
        $object->nome = "Não atendido";
        $status->store($object);
        $object->nome = "Cancelado";
        $status->store($object);
}

$agendamentoTb = new tableDb("agendamento",comment:"Tabela de agendamentos");
$agendamentoTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID agendamento"))
            ->addColumn((new columnDb("id_agenda","INT"))->isNotNull()->isForeingKey($agendaTb)->setComment("ID da tabela agenda"))
            ->addColumn((new columnDb("id_usuario","INT"))->isForeingKey($usuarioTb)->setComment("ID da tabela usuario"))
            ->addColumn((new columnDb("id_cliente","INT"))->isForeingKey($clienteTb)->setComment("ID da tabela cliente"))
            ->addColumn((new columnDb("id_funcionario","INT"))->isForeingKey($funcionarioTb)->setComment("ID da tabela funcionario"))
            ->addColumn((new columnDb("titulo","VARCHAR",150))->isNotNull()->setComment("titulo do agendamento"))
            ->addColumn((new columnDb("dt_ini","DATETIME"))->isNotNull()->setComment("Data inicial de agendamento"))
            ->addColumn((new columnDb("dt_fim","DATETIME"))->isNotNull()->setComment("Data final de agendamento"))
            ->addColumn((new columnDb("cor","VARCHAR",7))->setDefaut("#4267b2")->isNotNull()->setComment("Cor do agendamento"))
            ->addColumn((new columnDb("total","DECIMAL","10,2"))->isNotNull()->setComment("Total do agendamento"))
            ->addColumn((new columnDb("id_status","INT"))->isForeingKey($statusTb)->isNotNull()->setComment("id do Status do agendamento"))
            ->addColumn((new columnDb("obs","VARCHAR",400))->setComment("Observações do agendamento"))
            ->addIndex("getEventsbyFuncionario",["dt_ini","dt_fim","id_agenda","id_funcionario"])
            ->execute($recreate);

$agendamentoItemTb = new tableDb("agendamento_item",comment:"Tabela de itens agendamentos");
$agendamentoItemTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID do item"))
            ->addColumn((new columnDb("id_agendamento","INT"))->isNotNull()->isForeingKey($agendamentoTb)->setComment("ID agendamento"))
            ->addColumn((new columnDb("id_servico","INT"))->isNotNull()->isForeingKey($servicoTb)->setComment("ID serviço"))
            ->addColumn((new columnDb("qtd_item","INT"))->isNotNull()->setComment("QTD de serviços"))
            ->addColumn((new columnDb("tempo_item","TIME"))->isNotNull()->setComment("Tempo total do serviço"))
            ->addColumn((new columnDb("total_item","DECIMAL","10,2"))->isNotNull()->setComment("Valor do serviço"))
            ->execute($recreate);

$agendamentoFuncionarioTb = new tableDb("agenda_funcionario",comment:"Tabela de vinculo entre agendamentos e funcionarios");
$agendamentoFuncionarioTb->addColumn((new columnDb("id_agenda","INT"))->isPrimary()->isForeingKey($agendaTb)->setComment("ID agenda"))
                         ->addColumn((new columnDb("id_funcionario","INT"))->isPrimary()->isForeingKey($funcionarioTb)->setComment("ID Funcionario"))
                         ->execute($recreate);


$agendamentoUsuarioTb = new tableDb("agenda_usuario",comment:"Tabela de vinculo entre agendamentos e usuarios");
$agendamentoUsuarioTb->addColumn((new columnDb("id_agenda","INT"))->isPrimary()->isForeingKey($agendaTb)->setComment("ID agenda"))
                         ->addColumn((new columnDb("id_usuario","INT"))->isPrimary()->isForeingKey($usuarioTb)->setComment("ID Usuario"))
                         ->execute($recreate);

$paisTb = new tableDb("pais",comment:"Tabela de paises");
$paisTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID da pais"))
        ->addColumn((new columnDb("nome","VARCHAR",250))->isNotNull()->setComment("Nome do pais"))
        ->addColumn((new columnDb("nome_internacial","VARCHAR",250))->isNotNull()->setComment("nome internacial do pais"))
        ->execute($recreate);


$estadoTb = new tableDb("estado",comment:"Tabela de estados");
$estadoTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID da cidade"))
        ->addColumn((new columnDb("nome","VARCHAR",120))->isNotNull()->setComment("Nome da cidade"))
        ->addColumn((new columnDb("uf","VARCHAR",2))->isNotNull()->setComment("nome da Uf"))
        ->addColumn((new columnDb("pais","INT"))->isNotNull()->isForeingKey($paisTb)->setComment("id da pais do estado"))
        ->addColumn((new columnDb("ibge","INT"))->isUnique()->setComment("id do IBJE do estado"))
        ->addColumn((new columnDb("ddd","VARCHAR",50))->setComment("DDDs separado por , da Uf"))
        ->execute($recreate);

$cidadeTb = new tableDb("cidade",comment:"Tabela de cidades");
$cidadeTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID da cidade"))
        ->addColumn((new columnDb("nome","VARCHAR",120))->isNotNull()->setComment("Nome da cidade"))
        ->addColumn((new columnDb("uf","INT"))->isNotNull()->isForeingKey($estadoTb)->setComment("id da Uf da cidade"))
        ->addColumn((new columnDb("ibge","INT"))->setComment("id do IBJE da cidade"))
        ->execute($recreate);

$enderecoTb = new tableDb("endereco",comment:"Tabela de endereços");
$enderecoTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID do estado"))
        ->addColumn((new columnDb("id_usuario","INT"))->isForeingKey($usuarioTb)->setComment("ID da tabela usuario"))
        ->addColumn((new columnDb("id_empresa","INT"))->isForeingKey($empresaTb)->setComment("ID da tabela empresa"))
        ->addColumn((new columnDb("cep","VARCHAR",8))->isNotNull()->setComment("CEP"))
        ->addColumn((new columnDb("id_cidade","INT"))->isForeingKey($cidadeTb)->setComment("ID da tabela estado"))
        ->addColumn((new columnDb("id_estado","INT"))->isForeingKey($estadoTb)->setComment("ID da tabela cidade"))
        ->addColumn((new columnDb("bairro","VARCHAR",300))->isNotNull()->setComment("Bairro"))
        ->addColumn((new columnDb("rua","VARCHAR",300))->isNotNull()->setComment("Rua"))
        ->addColumn((new columnDb("numero","INT"))->isNotNull()->setComment("Numero"))
        ->addColumn((new columnDb("complemento","VARCHAR",300))->setComment("Complemento do endereço"))
        ->execute($recreate);

$configTb = new tableDb("config",comment:"Tabela de configurações");
$configTb->addColumn((new columnDb("id","INT"))->isPrimary()->setComment("ID agenda"))
        ->addColumn((new columnDb("id_empresa","INT"))->isNotNull()->isForeingKey($empresaTb,"id")->setComment("ID da tabela empresa"))
        ->addColumn((new columnDb("identificador","VARCHAR",30))->isNotNull()->isUnique()->setComment("Identificador da configuração"))
        ->addColumn((new columnDb("configuracao","BLOB"))->isNotNull()->setComment("Configuração"))
        ->execute($recreate);

transactionManeger::commit();

}
catch(Exception $e){
        echo $e->getMessage();
        transactionManeger::rollBack();
}

?>