# NeoORM

NeoORM é uma biblioteca PHP para mapeamento de bancos de dados que permite criar e atualizar tabelas, além de inserir, atualizar, excluir e selecionar registros em uma ou mais tabelas.

## Instalação
```bash
composer require diogodg/neoorm
```

criar um arquivo .env na raiz de seu projeto com as seguintes variaveis

```env
# Configuração do banco de dados
DRIVER=mysql
DBHOST=localhost
DBPORT=3306
DBNAME=bd
DBCHARSET=utf8mb4
DBUSER=root
DBPASSWORD=

# Configuração do caminho de seus Models
PATH_MODEL=./app/models
MODEL_NAMESPACE=app\models
```

## Exemplos

### Selecionar Registros

#### Selecionar por ID
```php
// Retorna um objeto com todas as colunas da tabela com base no $id informado
$result = (new Agendamento)->get($id);
```

#### Selecionar por Nome
```php
// Retorna um objeto com todas as colunas da tabela com base no $nome informado
$result = (new Agendamento)->get($nome, "nome");
```

#### Selecionar Todos os Registros
```php
// Retorna um array de objetos com todas as colunas e registros da tabela
$result = (new Agendamento)->getAll();
```

#### Selecionar com Filtros
```php
// Retorna um array de objetos com todas as colunas da tabela com base nos filtros informados
$db = new Agendamento;
$results = $db->addFilter("dt_ini", ">=", $dt_inicio)
              ->addFilter("dt_fim", "<=", $dt_fim)
              ->addFilter("id_agenda", "=", intval($id_agenda))
              ->addFilter("status", "!=", $status)
              ->selectAll();
```

#### Selecionar com Joins e Filtros
```php
// Retorna um array de objetos com as colunas informadas, com base nos filtros e joins adicionados
$db = new Agendamento;
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
$db = new Cidade;
$result = $db->addFilter("nome", "LIKE", "%" . $nome . "%")
             ->addLimit(1)
             ->selectByValues(["uf"], [$id_uf], true);
```

### Inserir/Atualizar Registros

```php

$values = new Funcionario;

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
$retorno = $values->store();
```

### Excluir Registros

#### Excluir por Filtro
```php
$db = new Funcionario;

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

### Usando Transações

```php
    try{   
        connection::beginTransaction();

        if ($agenda->set()){ 

            $agendaUsuario = new agendaUsuario;
            $agendaUsuario->id_usuario = $user->id;
            $agendaUsuario->id_agenda = $agenda->id;
            $agendaUsuario->set();

            if($agenda->id_funcionario){
                $agendaFuncionario = new agendaFuncionario;
                $agendaFuncionario->id_funcionario = $agenda->id_funcionario;
                $agendaFuncionario->id_agenda = $agenda->id;
                $agendaFuncionario->set();
            }
            connection::commit();
        }
    }catch (\exception $e){
        connection::rollBack();
    }
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

Dentro da pasta app/models deverá ser criada uma classe que irá representar sua tabela no banco de dados como o exemplo abaixo:

```php
<?php
namespace App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class Estado extends Model {
    //parametro obrigatorio que irá definir o nome da tabela no banco
    public const table = "estado";

    //obrigatorio ser dessa forma
    public function __construct() {
        parent::__construct(self::table);
    }

    //metodo responsavel por criar a tabela
    public static function table(){
        return (new Table(self::table,comment:"Tabela de estados"))
                ->addColumn((new Column("id","INT"))->isPrimary()->setComment("ID da cidade"))
                ->addColumn((new Column("nome","VARCHAR",120))->isNotNull()->setComment("Nome do estado"))
                ->addColumn((new Column("uf","VARCHAR",2))->isNotNull()->setComment("nome da Uf"))
                ->addColumn((new Column("pais","INT"))->isNotNull()->setComment("id da pais do estado"))
                ->addForeignKey(Pais::table,column:"pais")
                ->addColumn((new Column("ibge","INT"))->isUnique()->setComment("id do IBJE do estado"))
                ->addColumn((new Column("ddd","VARCHAR",50))->setComment("DDDs separado por , da Uf"));
    }

    //metodo responsavel por inserir dados iniciais na tabela 
    public static function seed(){
        $object = new self;
        if(!$object->addLimit(1)->selectColumns("id")){
            $object->nome = "Acre";
            $object->uf = "AC";
            $object->pais = 1;
            $object->ibge = 12;
            $object->ddd = "68";
            $object->store();

            $object->nome = "Alagoas";
            $object->uf = "AL";
            $object->pais = 1;
            $object->ibge = 27;
            $object->ddd = "82";
            $object->store();

            $object->nome = "Amapá";
            $object->uf = "AP";
            $object->pais = 1;
            $object->ibge = 16;
            $object->ddd = "96";
            $object->store();

            $object->nome = "Amazonas";
            $object->uf = "AM";
            $object->pais = 1;
            $object->ibge = 13;
            $object->ddd = "92,97";
            $object->store();
      }
  }
```

Após criado todas as classes

basta chamar a seguinte classe como no exemplo abaixo

```php

<?php

use Diogodg\Neoorm\Migrations\Migrate;

//caso o parametro recreate seja verdadeiro irá ser removido todas as tabelas e depois recriadas novamente
(new Migrate)->execute($recrete = false);

```
