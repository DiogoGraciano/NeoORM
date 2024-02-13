<?php
namespace app\db;
use app\db\configDB;

class Db
{
    private $pdo;
    private $config;
    private $table;
    private $object;
    private $columns;
    private $error = [];
    private $joins =[];
    private $propertys =[];
    private $filters =[];
    private $lastid;
    private $valuesBind = [];
    private $counterBind = 1;

    function __construct($table)
    {
        //Pega configuração do PDO
        $this->config = new configDB;
        $this->pdo = $this->config->getPDO();

        //Seta Tabela
        $this->table = $table;

        //Gera Objeto da tabela
        $this->object = $this->getObjectTable();

        //Transforma as colunas da tabela em uma array
        $this->columns = (array)$this->object;
        $this->columns = array_keys($this->columns);
      
    }

    public function transaction(){
        if ($this->pdo->beginTransaction())
            return True;
        else 
            $this->error[] = "Erro: Não foi possivel iniciar a transação";
    }

    public function commit(){
        if ($this->pdo->commit())
            return True;
        else 
            $this->error[] = "Erro: Não foi possivel finalizar a transação";
    }

    public function rollback(){
        if ($this->pdo->rollback())
            return True;
        else 
            $this->error[] = "Erro: Não foi possivel desafazer a transação";
    }

    //Retorna o ultimo ID da tabela
    private function getlastIdBd()
    {
        $rows = $this->selectInstruction('SELECT ' . $this->columns[0] . ' FROM ' . $this->table . ' ORDER BY ' . $this->columns[0] . ' DESC LIMIT 1');
        if ($rows) {
            $column = $this->columns[0];
            return $rows->$column;
        } else {
            $this->error[] = "Erro: Tabela não encontrada";
        }
    }

    public function getLastID(){
        return $this->lastid;
    }

    //Retorna o retorna os erros
    public function getError()
    {
        return $this->error;
    }

    //Retorna o retorna os objetos da tabela
    public function getObject()
    {
        return $this->object;
    }

    public function getColumns()
    {
        return $this->columns;
    }

    //Pega as colunas da tabela e tranforma em Objeto
    private function getObjectTable()
    {
        $rows = (array)$this->selectInstruction('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = "' . $this->table . '" ORDER BY CASE WHEN COLUMN_KEY = "PRI" THEN 1 ELSE 2 END,COLUMN_NAME;');
        if ($rows) {
            $object = new \stdClass;
            foreach ($rows as $row) {
                foreach ($row as $columns) {
                    $object->$columns = "";
                }
            }
            return $object;
        } else {
            $this->error[] = "Erro: Tabela não encontrada";
        }
    }

    //Faz um select com base me uma instrução e retorna um objeto
    public function selectInstruction($sql_instruction,$asArray=false)
    {
        try {
            $sql = $this->pdo->prepare($sql_instruction);
            foreach ($this->valuesBind as $key => $data) {
                $sql->bindParam($key,$data[0],$data[1]);
            }
            $sql->execute();

            $array = [];

            if ($sql->rowCount() > 0) {

                $rows = $sql->fetchAll(\PDO::FETCH_ASSOC);
                
                if ($rows) {
                    foreach ($rows as $row) {
                        $object = new \stdClass();
                        foreach ($row as $key => $data) {
                            $object->$key = $data;
                        }
                        $array[] = $object;
                    }
                }
            }
            if ($asArray == false)
                $array =  $this->isOneObject($array);

            return $array;
        } catch (\Exception $e) {
            $this->error[] = 'Erro: ' .  $e->getMessage();
        }
    }
    
    //Faz um select em um registro da tabela
    public function selectOne($id)
    {
        $this->valuesBind[1] = [$id,\PDO::PARAM_INT];
        $object = $this->selectInstruction("SELECT * FROM " . $this->table . " WHERE " . $this->columns[0] . "=?");
        
        return $object;
    }

    //Converte para objeto caso o retorno seja de apenas um registro
    private function isOneObject($object)
    {
        if ($object) {

            $object = (array)$object;

            if (array_key_exists(1, $object)) {
                return (object)$object;
            } elseif (array_key_exists(0, $object)) {
                $object = $object[0];
                return (object)$object;
            }
        } else {
            $this->error[] = 'Erro: Objeto vazio';
        }
    }

    //Retorna um array com todos os registro da tabela
    public function selectAll()
    {
        $sql = "SELECT * FROM " . $this->table;
        foreach ($this->joins as $join){
            $sql .= $join;
        }
        if ($this->filters){
            $sql .= " WHERE ";
            $i = 1;
            foreach ($this->filters as $filter){
                if ($i == 1){
                    $sql .= substr($filter,4);
                    $i++;
                }else{
                    $sql .= $filter;
                }
            }    
        }
        foreach ($this->propertys as $property){
            $sql .= $property;
        }
        
        $object = $this->selectInstruction($sql,true);
        $this->clean();
        return $object;
    }

    //retorna um array com registros referentes a essas colunas
    public function selectColumns(Array $columns)
    {
        $sql = "SELECT ";
        $sql .= implode(",",$columns);  
        $sql .= " FROM ".$this->table;
        foreach ($this->joins as $join){
            $sql .= $join;
        }
        if ($this->filters){
            $sql .= " WHERE ";
            $i = 1;
            foreach ($this->filters as $filter){
                if ($i == 1){
                    $sql .= substr($filter,4);
                    $i++;
                }else{
                    $sql .= $filter;
                }
            }    
        }
        foreach ($this->propertys as $property){
            $sql .= $property;
        }
        $object = $this->selectInstruction($sql,true);
        $this->clean();
        return $object;
    }

    //faz um select com as colunas e os valores passados
    public function selectByValues(Array $columns,array $values,$all=false){
        if (count($columns) == count($values)){
            $conditions = [];
            $sql = "SELECT ";
            $i = 0;
            foreach ($columns as $column){
                if (!$all)
                    $sql .= $column.",";
                  
                $value = trim($values[$i]);

                if (is_string($value) && $value != "null")
                    $conditions[] = $column." = '".$value."' and ";
                elseif (is_int($value) || is_float($value) || $value == "null")
                    $conditions[] = $column." = ".$value." and ";  
                $i++;
            }
            $sql = substr($sql, 0, -1);
            if ($all == true){
                $sql .= " *";
            }
            $sql .= " FROM ".$this->table;
            foreach ($this->joins as $join){
                $sql .= $join;
            }
            $sql .= " WHERE ";
            foreach ($conditions as $condition){
                $sql .= $condition;
            }
            $sql = substr($sql, 0, -4);
            foreach ($this->filters as $filter){
                if ($i == 1){
                    $sql .= substr($filter,4);
                    $i++;
                }else{
                    $sql .= $filter;
                }
            }
            foreach ($this->propertys as $property){
                $sql .= $property;
            }

            $object = $this->selectInstruction($sql,true);
            $this->clean();
            return $object;
        }else 
           $this->error[] = "Erro: Quantidade de colunas diferente do total de Valores";
    }

    //Salva ou atualiza um registro da tabela
    public function store(\stdClass $values)
    {
        try {
            if ($values) {
                $values = (array)$values;
                $valuesBind = [];
                if (!$values[$this->columns[0]]) {
                    $values[$this->columns[0]] = $this->getlastIdBd() + 1;
                    $sql_instruction = "INSERT INTO " . $this->table . "(";
                    $keysBD = "";
                    $valuesBD = "";
                    $i = 1;
                    foreach ($values as $key => $data) {
                        if ($data){
                            $keysBD .= $key . ",";
                            if (is_string($data) && $data != "null"){
                                $data = trim($data);
                                $valuesBind[$i] = [$data,\PDO::PARAM_STR]; 
                                $valuesBD .= "?,";
                            }elseif (is_int($data) || is_float($data) && $data != "null"){
                                $valuesBD .= "?,";
                                $valuesBind[$i] = [$data,\PDO::PARAM_INT]; 
                            }
                            else    
                                $valuesBD .= "null,";
                            $i++;
                        }
                    }
                    $keysBD = substr($keysBD, 0, -1);
                    $sql_instruction .= $keysBD;
                    $sql_instruction .= ") VALUES (";
                    $valuesBD = substr($valuesBD, 0, -1);
                    $sql_instruction .= $valuesBD;
                    $sql_instruction .= ");";
                } elseif ($values[$this->columns[0]]) {
                    $sql_instruction = "UPDATE " . $this->table . " SET ";
                    $i = 1;
                    foreach ($values as $key => $data) {
                        if ($data){
                            if (is_string($data) && $data != "null"){
                                $data = trim($data);
                                $valuesBind[$i] = [$data,\PDO::PARAM_STR]; 
                                $sql_instruction .= $key . '=?,';
                            }elseif (is_int($data) || is_float($data) && $data != "null"){
                                $valuesBind[$i] = [$data,\PDO::PARAM_INT]; 
                                $sql_instruction .= $key . "=?,";
                            }else 
                                $sql_instruction .= $key . "=null,";
                            $i++;
                        }
                    }
                    $sql_instruction = substr($sql_instruction, 0, -1);
                    $sql_instruction .= "WHERE " . $this->columns[0] . "=" . $values[$this->columns[0]];
                }
                $sql = $this->pdo->prepare($sql_instruction);
                foreach ($valuesBind as $key => $data) {
                    $sql->bindParam($key,$data[0],$data[1]);
                }
                $sql->execute();
                $this->lastid = $values[$this->columns[0]];
                return true;
            }
            $this->error[] = "Erro: Valores não informados";
        } catch (\Exception $e) {
            $this->error[] = 'Erro: ' .  $e->getMessage();
        }
    }

    public function storeMutiPrimary(\stdClass $values){
        try {
            if ($values) {
                $values = (array)$values;
                $sql_instruction = "INSERT INTO " . $this->table . "(";
                $keysBD = "";
                $valuesBD = "";
                $valuesBind = [];
                $i = 1;
                foreach ($values as $key => $data) {
                    if ($data){
                        $keysBD .= $key . ",";
                        if (is_string($data) && $data != "null"){
                            $data = trim($data);
                            $valuesBind[$i] = [$data,\PDO::PARAM_STR];  
                            $valuesBD .= "?,";
                        }elseif (is_int($data) || is_float($data) && $data != "null"){
                            $valuesBD .= "?,";
                            $valuesBind[$i] = [$data,\PDO::PARAM_INT]; 
                        }
                        else    
                            $valuesBD .= "null,";
                        $i++;
                    }
                }
                $keysBD = substr($keysBD, 0, -1);
                $sql_instruction .= $keysBD;
                $sql_instruction .= ") VALUES (";
                $valuesBD = substr($valuesBD, 0, -1);
                $sql_instruction .= $valuesBD;
                $sql_instruction .= ");";
                $sql = $this->pdo->prepare($sql_instruction);
                foreach ($valuesBind as $key => $data) {
                    $sql->bindParam($key,$data[0],$data[1]);
                }
                $sql->execute();
                return true;
            }
        } catch (\Exception $e) {
            $this->error[] = 'Erro: ' .  $e->getMessage();
        }
    }

    // Deleta um registro da tabela
    public function delete($id)
    {
        try {
            if ($id){
                $sql = $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE " . $this->columns[0] . "=?");
                $sql->bindParam(1,$id,\PDO::PARAM_INT);
                $sql->execute();
                return true;
            }
            else 
                $this->error[] = 'Erro: ID Invalido';
            return false;
        } catch (\Exception $e) {
            $this->error[] = 'Erro: ' .  $e->getMessage();
        }
    }

    public function deleteByFilter()
    {
        try {
            if ($this->filters){
                $sql_instruction = "DELETE FROM " . $this->table . " WHERE ";
                $i = 1;
                foreach ($this->filters as $filter){
                    if ($i == 1){
                        $sql_instruction .= substr($filter,4);
                        $i++;
                    }else{
                        $sql_instruction .= $filter;
                    }
                }
                $sql = $this->pdo->prepare($sql_instruction); 
                $sql->execute();
                $this->clean();
                return true;
            }
            else 
                $this->error[] = 'Erro: Obrigatorio Uso de filtro';
            return false;
        } catch (\Exception $e) {
            $this->error[] = 'Erro: ' .  $e->getMessage();
        }
    }

    public function addFilter($column,$condition,$value,$operator="AND"){
        
        if (is_string($value) && $value != "null")
            $this->valuesBind[$this->counterBind] = [$value,\PDO::PARAM_STR];
        elseif (is_int($value) || is_float($value) || $value == "null")
            $this->valuesBind[$this->counterBind] = [$value,\PDO::PARAM_INT];

        $this->counterBind++;

        $this->filters[] = " ".$operator." ".$column." ".$condition."? ";
        
        return $this;
    }

    public function addOrder($column,$order="DESC"){
        $this->propertys[] = " ORDER by ".$column." ".$order;

        return $this;
    }

    public function addLimit($limitIni,$limitFim=""){
        if ($limitFim)
            $this->propertys[] = " LIMIT ?,?";
        else 
            $this->propertys[] = " LIMIT ?";

        $this->valuesBind[$this->counterBind] = [$limitIni,\PDO::PARAM_INT];
        $this->counterBind++;
        $this->valuesBind[$this->counterBind] = [$limitFim,\PDO::PARAM_INT];
        $this->counterBind++;

        return $this;
    }

    public function addGroup($columns){
        $this->propertys[] = " GROUP by ?";

        $this->valuesBind[$this->counterBind] = [$columns,\PDO::PARAM_INT];
        $this->counterBind++;

        return $this;
    }

    public function addJoin($type,$table,$condition_from,$condition_to){
        $this->joins[] = " ".$type." JOIN ".$table." on ".$condition_from." = ".$condition_to;

        return $this;
    }

    private function clean(){
        $this->joins = [];
        $this->propertys = [];
        $this->filters = [];
        $this->valuesBind = [];
        $this->counterBind = 1;
    }

}
?>

