# NeoORM

NeoORM é uma biblioteca PHP para gerenciamento de bancos de dados que permite criar e atualizar tabelas, além de inserir, atualizar, excluir e selecionar registros em uma ou mais tabelas.

## Exemplos

### Selecionar Registros

#### Selecionar por ID
```php
// Retorna um objeto com todas as colunas da tabela com base no $id informado
$result = (new agendamento)->get($id);
```

#### Selecionar por Nome
```php
// Retorna um objeto com todas as colunas da tabela com base no $nome informado
$result = (new agendamento)->get($nome, "nome");
```

#### Selecionar Todos os Registros
```php
// Retorna um array de objetos com todas as colunas e registros da tabela
$result = (new agendamento)->getAll();
```

#### Selecionar com Filtros
```php
// Retorna um array de objetos com todas as colunas da tabela com base nos filtros informados
$db = new agendamento;
$results = $db->addFilter("dt_ini", ">=", $dt_inicio)
              ->addFilter("dt_fim", "<=", $dt_fim)
              ->addFilter("id_agenda", "=", intval($id_agenda))
              ->addFilter("status", "!=", $status)
              ->selectAll();
```

#### Selecionar com Joins e Filtros
```php
// Retorna um array de objetos com as colunas informadas, com base nos filtros e joins adicionados
$db = new agendamento;
$result = $db->addJoin("LEFT", "usuario", "usuario.id", "agendamento.id_usuario")
             ->addJoin("INNER", "agenda", "agenda.id", "agendamento.id_agenda")
             ->addJoin("LEFT", "cliente", "cliente.id", "agendamento.id_cliente")
             ->addJoin("INNER", "funcionario", "funcionario.id", "agendamento.id_funcionario")
             ->addFilter("agenda.id_empresa", "=", $id_empresa)
             ->selectColumns("agendamento.id", "usuario.cpf_cnpj", "cliente.nome as cli_nome", "usuario.nome as usu_nome", "usuario.email", "usuario.telefone", "agenda.nome as age_nome", "funcionario.nome as fun_nome", "dt_ini", "dt_fim");
```

#### Selecionar com Filtros e Limite
```php
// Retorna um array de objetos com as colunas informadas que correspondem aos valores informados, com base nos filtros e limite especificados
$db = new cidade;
$result = $db->addFilter("nome", "LIKE", "%" . $nome . "%")
             ->addLimit(1)
             ->selectByValues(["uf"], [$id_uf], true);
```

### Inserir/Atualizar Registros

```php
$db = new funcionario;

$values = new StdClass;

// Se $values->id for null, vazio, ou 0, tentará realizar um comando INSERT. Caso contrário, tentará um UPDATE.
$values->id = null; // ou "" ou 0
$values->id_usuario = $id_usuario;
$values->nome = $nome;
$values->cpf_cnpj = $cpf_cnpj;
$values->email = $email;
$values->telefone = $telefone;
$values->hora_ini = $hora_ini;
$values->hora_fim = $hora_fim;
$values->hora_almoco_ini = $hora_almoco_ini;
$values->hora_almoco_fim = $hora_almoco_fim;
$values->dias = $dias;

// Retorna false ou o ID do registro
$retorno = $db->store($values);
```

### Excluir Registros

#### Excluir por Filtro
```php
$db = new funcionario;

// Retorna true ou false
$retorno = $db->addFilter("nome", "=", "Diogo")->deleteByFilter();
```

#### Excluir por ID
```php
$id = 1;
$db = new funcionario;

// Retorna true ou false
$retorno = $db->delete($id);
```

### Outros Exemplos

#### Utilizando a Classe DB Diretamente
```php
$id = 1;
$db = new db("tb_funcionario");

// Retorna true ou false
$retorno = $db->delete($id);
```

## Criação/Modificação de Banco de Dados

### Criar uma Tabela
```php
try {
    $agendamentoTb = new tableDb("agendamento", comment: "Tabela de agendamentos");
    $agendamentoTb->beginTransaction();
    $agendamentoTb->addColumn((new columnDb("id", "INT"))->isPrimary()->setComment("ID agendamento"))
                 ->addColumn((new columnDb("id_agenda", "INT"))->isNotNull()->isForeingKey($agendaTb)->setComment("ID da tabela agenda"))
                 ->addColumn((new columnDb("id_usuario", "INT"))->isForeingKey($usuarioTb)->setComment("ID da tabela usuario"))
                 ->addColumn((new columnDb("id_cliente", "INT"))->isForeingKey($clienteTb)->setComment("ID da tabela cliente"))
                 ->addColumn((new columnDb("id_funcionario", "INT"))->isForeingKey($funcionarioTb)->setComment("ID da tabela funcionario"))
                 ->addColumn((new columnDb("titulo", "VARCHAR", 150))->isUnique()->isNotNull()->setComment("Titulo do agendamento"))
                 ->addColumn((new columnDb("dt_ini", "DATETIME"))->isNotNull()->setComment("Data inicial do agendamento"))
                 ->addColumn((new columnDb("dt_fim", "DATETIME"))->isNotNull()->setComment("Data final do agendamento"))
                 ->addColumn((new columnDb("cor", "VARCHAR", 7))->isNotNull()->setComment("Cor do agendamento"))
                 ->addColumn((new columnDb("total", "DECIMAL", "10,2"))->isNotNull()->setComment("Total do agendamento"))
                 ->addColumn((new columnDb("id_status", "INT"))->isForeingKey($statusTb)->isNotNull()->setComment("ID do status do agendamento"))
                 ->addColumn((new columnDb("obs", "VARCHAR", 400))->setComment("Observações do agendamento"));
    $agendamentoTb->execute($recreate);
    $agendamentoTb->commit();
} catch (Exception $e) {
    $agendamentoTb->rollBack();
    echo $e->getMessage();
}
// Após criado, sempre que este código for executado, irá verificar se alguma informação da tabela precisa ser atualizada.
```
