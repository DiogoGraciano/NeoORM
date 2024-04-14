# NexusDB

Classe para gerenciar um banco de dados mysql

Capaz de atualizar, inserir, excluir e selecionar registros de uma ou mais tabelas.

Exemplos

# Exemplos Select
```php
//retornara um objeto com todas as colunas da tabela com base no $id informado
$result = (new agendamento)->get($id);

//retornara um objeto com todas as colunas da tabela com base no $nome informado
$result = (new agendamento)->get($nome,"nome");
```
```php
//retornara array de objetos com todas as colunas e registros da tabela 
$result = (new agendamento)->getAll($id);
```
```php
//retornara um array de objetos com todas as colunas da tabela com base nos filtros informados
$db = new agendamento;
$results = $db->addFilter("dt_ini",">=",$dt_inicio)
              ->addFilter("dt_fim","<=",$dt_fim)
              ->addFilter("id_agenda","=",intval($id_agenda))
              ->addFilter("status","!=",$status)
              ->selectAll();
```
```php
//retornara um array de objetos com todas as colunas informadas da tabela com base nos filtros informados e nos joins adicionados 
$db = new agendamento;
$result = $db->addJoin("LEFT","usuario","usuario.id","agendamento.id_usuario")
              ->addJoin("INNER","agenda","agenda.id","agendamento.id_agenda")
              ->addJoin("LEFT","cliente","cliente.id","agendamento.id_cliente")
              ->addJoin("INNER","funcionario","funcionario.id","agendamento.id_funcionario")
              ->addFilter("agenda.id_empresa","=",$id_empresa)
              ->selectColumns("agendamento.id","usuario.cpf_cnpj","cliente.nome as cli_nome","usuario.nome as usu_nome","usuario.email","usuario.telefone","agenda.nome as age_nome","funcionario.nome as fun_nome","dt_ini","dt_fim");
```
```php
//retornara um array de objetos com todas as colunas informadas que os valores batem com o informado no segundo parametro da tabela com base nos filtros informado e no limit informado 
$db = new cidade;
$result = $db->addFilter("nome","LIKE","%".$nome."%")->addLimit(1)->selectByValues(array("uf"),array($id_uf),true);
```

# Exemplos Insert/Update
```php
$db = new funcionario;

\\Pode tanto ser usado as o metodo getObject quanto a StdClass quanto o metodo get da tabela
\\$values = $db->getObject() || $values = new StdClass || (new table)->get()
\\obs: $db->getObject() ou (new table)->get() sempre irão retornar todas as colunas da tabela no objeto

$values = new StdClass

\\caso vazio (null) ou for uma string vazia ("") ou 0 ou não existir irá tentar realizar o comando INSERT 
$values->id = null || $values->id = "" || $values->id = 0;
\\caso exista tentara realizar o comando update

$values->id = $id \\1
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
//retorno = false/ID
$retorno = $db->store($values);
```

# Exemplos Delete
```php
$db = new funcionario;

// true|false
$retorno = $db->addFilter("nome","=","Diogo")->deleteByFilter();
```
```php
$id = 1;

$db = new funcionario;

// true|false
$retorno = $db->delete($id);
```
# Outros Exemplos
```php
//em Todos os casos acima é possivel usar a diretamente a classe DB informado a tabela desejada no __contruct
$id = 1;

$db = new db("tb_funcionario");

// true|false
$retorno = $db->delete($id);
```
