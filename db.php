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

    function __construct($table)
    {
        if ($this->validInjection($table)){
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
        else 
           return false;
    }

    //Retorna o ultimo ID da tabela
    private function getlastId()
    {
        $rows = $this->selectInstruction('SELECT ' . $this->columns[0] . ' FROM ' . $this->table . ' ORDER BY ' . $this->columns[0] . ' DESC LIMIT 1');
        if ($rows) {
            $column = $this->columns[0];
            return $rows->$column;
        } else {
            $this->error[] = "Erro: Tabela não encontrada";
        }
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

        $rows = (array)$this->selectInstruction('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = "' . $this->table . '" ORDER BY CASE WHEN COLUMN_KEY = "PRI" THEN 1 ELSE 2 END;');
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
        if ($this->validInjection($id))
            $object = $this->selectInstruction("select * from " . $this->table . " where " . $this->columns[0] . "=" . $id);
        else 
            return false;

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
        $object = $this->selectInstruction("select * from " . $this->table,true);

        return $object;
    }

    //retorna um array com registros referentes a essas colunas
    public function selectColumns(Array $columns)
    {
        $sql = "select ";
        foreach ($columns as $column){
            $sql .= $column.",";  
        }
        $sql = substr($sql, 0, -1);
        $sql .= " from ".$this->table;    
        $object = $this->selectInstruction($sql,true);

        return $object;
    }

    //faz um select com as colunas e os valores passados
    public function selectByValues(Array $columns,array $values,$all=false){
        if (count($columns) == count($values)){
            $conditions = [];
            $sql = "select ";
            $i = 0;
            foreach ($columns as $column){
                if (!$all)
                    $sql .= $column.",";
                  
                $value = $values[$i];

                if ($this->validInjection($value)){  
                    if (is_string($value) && $value != "null")
                        $conditions[] = $column." = '".$value."' and ";
                    elseif (is_int($value) || is_float($value) || $value == "null")
                        $conditions[] = $column." = ".$value." and ";  
                    $i++;
                }
                else 
                    return False;
            }
            $sql = substr($sql, 0, -1);
            if ($all == true){
                $sql .= " *";
            }
            $sql .= " from ".$this->table;
            $sql .= " where ";
            foreach ($conditions as $condition){
                $sql .= $condition;
            }
            $sql = substr($sql, 0, -4);

            $object = $this->selectInstruction($sql,true);

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
                if (!$values[$this->columns[0]]) {
                    $values[$this->columns[0]] = $this->getlastId() + 1;
                    $sql_instruction = "INSERT INTO " . $this->table . "(";
                    foreach ($values as $key => $data) {
                        $sql_instruction .= $key . ",";
                    }
                    $sql_instruction = substr($sql_instruction, 0, -1);
                    $sql_instruction .= ") VALUES (";
                    foreach ($values as $data) {
                        if ($this->validInjection($data)){
                            if (is_string($data) && $data != "null")
                                $sql_instruction .= "'" . $data . "',";
                            elseif (is_int($data) || is_float($data) || $data == "null")
                                $sql_instruction .= $data . ",";
                        }
                        else 
                            return false;
                    }
                    $sql_instruction = substr($sql_instruction, 0, -1);
                    $sql_instruction .= ");";
                } elseif ($values[$this->columns[0]]) {

                    $sql_instruction = "UPDATE " . $this->table . " SET ";
                    foreach ($values as $key => $data) {
                        if ($this->validInjection($data)){
                            if (is_string($data))
                                $sql_instruction .= $key . '="' . $data . '",';
                            elseif (is_int($data) || is_float($data))
                                $sql_instruction .= $key . "=" . $data . ",";
                        }
                        else 
                            return false;
                    }
                    $sql_instruction = substr($sql_instruction, 0, -1);
                    $sql_instruction .= "WHERE " . $this->columns[0] . "=" . $values[$this->columns[0]];
                }
                $sql = $this->pdo->prepare($sql_instruction);
                $sql->execute();
                return true;
            }
            $this->error[] = "Erro: Valores não informados";
        } catch (\Exception $e) {
            $this->error[] = 'Erro: ' .  $e->getMessage();
        }
    }

    // Deleta um registro da tabela
    public function delete($id)
    {
        try {
            if ($this->validInjection($id)){
                $sql = $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE " . $this->columns[0] . "=" . $id);
                $sql->execute();
                return true;
            }
            else 
                return false;
        } catch (\Exception $e) {
            $this->error[] = 'Erro: ' .  $e->getMessage();
        }
    }

    //valida se foi feito tentatiava de sql injection
    function validInjection($value) {
        $inject=0;
        $badword = array(" select","select "," insert"," update","update "," delete","delete "," drop","drop "," destroy","destroy ");
        $charvalidos = "abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789ÁÀÃÂÇÉÈÊÍÌÓÒÔÕÚÙÜÑáàãâçéèêíìóòôõúùüñ!?@#$%&(){}[]:;,.-_ ";

        for ($i=0;$i<sizeof($badword);$i++){
            if (substr_count($value,$badword[$i])!=0){
                $inject=1;
            }
        }

        for ($i=0;$i<strlen($value);$i++){
            $char = substr($value,$i,1);
            if (substr_count($charvalidos,$char)==0) {
                $inject=1;
            }
        }

        if ($inject > 0)
            $this->error[] = 'Erro: Indentificado tentativa de SQL Injection';

        return($inject == 0);
    }
}
?>

