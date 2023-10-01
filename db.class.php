<?php
include_once("configDB.class.php");

class db
{
    private $pdo;
    private $config;
    private $table;
    private $object;
    private $columns;
    private $error = [];

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

    //Retorna o ultimo ID da tabela
    private function getlastId()
    {
        $rows = (array)$this->selectInstruction('SELECT ' . $this->columns[0] . ' FROM ' . $this->table . ' ORDER BY ' . $this->columns[0] . ' DESC');
        if ($rows) {
            $column = $this->columns[0];
            return $rows[$column];
        } else {
            $this->error = "Tabela não encontrada";
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

    //Pega as colunas da tabela e tranforma em Objeto
    private function getObjectTable()
    {

        $rows = (array)$this->selectInstruction('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = "' . $this->table . '" ORDER BY CASE WHEN COLUMN_KEY = "PRI" THEN 1 ELSE 2 END;');
        if ($rows) {
            $object = new stdClass;
            foreach ($rows as $row) {
                foreach ($row as $columns) {
                    $object->$columns = "";
                }
            }
            return $object;
        } else {
            $this->error = "Tabela não encontrada";
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

                $rows = $sql->fetchAll(PDO::FETCH_ASSOC);

                if ($rows) {
                    foreach ($rows as $row) {
                        $object = new stdClass();
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
        } catch (Exception $e) {
            $this->error = 'Erro: ' .  $e->getMessage();
        }
    }
    
    //Faz um select em um registro da tabela
    public function selectOne($id)
    {
        $object = $this->selectInstruction("select * from " . $this->table . " where " . $this->columns[0] . "=" . $id);

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
            $this->error = 'Erro: Objeto vazio';
        }
    }

    //Retorna um array com todos os registro da tabela
    public function selectAll()
    {
        $object = $this->selectInstruction("select * from " . $this->table,true);

        return $object;
    }

    //Salva ou atualiza um registro da tabela
    public function store(stdClass $values)
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
                        if (is_string($data) && $data != "null")
                            $sql_instruction .= "'" . $data . "',";
                        elseif (is_int($data) || is_float($data) || $data == "null")
                            $sql_instruction .= $data . ",";
                    }
                    $sql_instruction = substr($sql_instruction, 0, -1);
                    $sql_instruction .= ");";
                } elseif ($values[$this->columns[0]]) {

                    $sql_instruction = "UPDATE " . $this->table . " SET ";
                    foreach ($values as $key => $data) {
                        if (is_string($data))
                            $sql_instruction .= $key . '="' . $data . '",';
                        elseif (is_int($data) || is_float($data))
                            $sql_instruction .= $key . "=" . $data . ",";
                    }
                    $sql_instruction = substr($sql_instruction, 0, -1);
                    $sql_instruction .= "WHERE " . $this->columns[0] . "=" . $values[$this->columns[0]];
                }
                $sql = $this->pdo->prepare($sql_instruction);
                $sql->execute();
                return true;
            }
            $this->error = "Erro: Valores não informados";
        } catch (Exception $e) {
            $this->error = 'Erro: ' .  $e->getMessage();
        }
    }

    // Deleta um registro da tabela
    public function delete($id)
    {
        try {
            $sql = $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE " . $this->columns[0] . "=" . $id);
            $sql->execute();
            return true;
        } catch (Exception $e) {
            $this->error = 'Erro: ' .  $e->getMessage();
        }
    }
}
