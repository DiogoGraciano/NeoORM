<?php
namespace app\db;
use Exception;

/**
 * Classe base para criação do banco de dados.
 */
class tableDb extends connectionDb
{
    /**
     * Nome da tabela.
     *
     * @var string
     */
    private $table;

    /**
     * pdo.
     *
     * @var PDO
    */
    private $pdo;

    /**
     * Colunas.
     *
     * @var array
     */
    private $columns = [];

    /**
     * index.
     *
     * @var array
    */
    private $indexs = [];

    /**
     * primary.
     *
     * @var array
    */
    private $primary = [];

    /**
     * index.
     *
     * @var array
    */
    private $isAutoIncrement = false;

    /**
     * outros comandos.
     *
     * @var string
    */
    private $engine = "";

    /**
     * outros comandos.
     *
     * @var string
    */
    private $collate = "";


    /**
     * outros comandos.
     *
     * @var string
    */
    private $comment = "";

    function __construct(string $table,string $engine="InnoDB",string $collate="utf8mb4_general_ci",string $comment = "")
    {
        // Inicia a Conexão
        $this->pdo = ConnectionDb::getConnection();
        
        $this->engine = $engine;

        $this->collate = $collate;

        $this->comment = $comment;
        
        if(!$this->validateName($this->table = strtolower(trim($table)))){
            throw new Exception("Nome é invalido");
        }
    }

    public function addColumn(columnDb $column){
        $column = $column->getColumn();

        if($column->primary)
            $this->primary[] = $column->name;

        $column->columnSql = ["{$column->name} {$column->type} {$column->null} {$column->defaut} {$column->comment}",$column->unique,$column->foreingKey," "];

        $this->columns[$column->name] = $column;

        return $this;
    }

    public function isAutoIncrement(){
        $this->isAutoIncrement = true;
        return $this;
    }

    public function addIndex(string $name,array $columns){
        if(count($columns) < 2){
            throw new Exception("Numero de colunas tem que ser maior que 1"); 
        }
        if($this->columns){
            $tableColumns = array_keys($this->columns);
            $columnsFinal = [];
            foreach ($columns as $column){
                $column = strtolower(trim($column));
                if($this->validateName($column) && in_array($column,$tableColumns))
                    $columnsFinal[] = $column;
                else 
                    throw new Exception("Coluna é invalida: ".$column); 
            }
            $this->indexs[$name]["columns"] = $columnsFinal;
            $this->indexs[$name]["sql"] = "CREATE INDEX {$name} ON {$this->table} (".implode(",",$columnsFinal).");";

            return $this;
        }
        else{
            throw new Exception("É preciso ter pelo menos uma coluna para adicionar o um index");
        }
    }

    private function create(){
        $sql = "DROP TABLE IF EXISTS {$this->table};CREATE TABLE IF NOT EXISTS {$this->table}(";
        foreach ($this->columns as $column) {            
            $sql .= str_replace(" , ","",implode(",",array_filter($column->columnSql)));
        }

        $sql = trim($sql);

        $sql .= "PRIMARY KEY (".implode(",",$this->primary).")";

        $sql .= ")ENGINE={$this->engine} COLLATE={$this->collate} COMMENT='{$this->comment}';";

        if($this->isAutoIncrement){
            $sql .= "ALTER TABLE {$this->table} AUTO_INCREMENT = 1;";
        }
        
        foreach ($this->indexs as $index) {
            $sql .= $index["sql"];
        }

        $sql = $this->pdo->prepare($sql);
        if (!$sql) {
            throw new \Exception("Erro ao preparar a consulta: " . implode(", ", $this->pdo->errorInfo()));
        }
        $sql->execute();
    }

    public function execute($recreate = false){

        if($recreate){
            $this->create();
        }

        $sql = "";

        $table = $this->getColumnsTable();

        if(!$table){
            return $this->create();
        }

        foreach ($table as $columnDb){
            if(!in_array($columnDb["COLUMN_NAME"],array_keys($this->columns))){
                $sql .= "ALTER TABLE {$this->table} DROP COLUMN {$columnDb};";
                break;
            }  
        }
        
        foreach ($this->columns as $column) {

            $inDb = false;
            foreach ($table as $columnDb){
                if($columnDb["COLUMN_NAME"] == $column->name){
                    $inDb = true;
                    break;
                }
            }

            $columnInformation = array_filter($table,fn($key) => in_array($column->name,$table[$key]),ARRAY_FILTER_USE_KEY);
            $primaryKeyDb = array_column(array_filter($table,fn($key) => in_array("PRI",$table[$key]),ARRAY_FILTER_USE_KEY),"COLUMN_NAME");

            if(!$inDb || $columnInformation){

                $columnInformation = $columnInformation[array_key_first($columnInformation)];
                
                !$inDb?$operation = "ADD":$operation = "MODIFY";
               
                $changed = false;
                if(!$inDb || strtolower($column->type) != $columnInformation["COLUMN_TYPE"] || 
                    ($columnInformation["IS_NULLABLE"] == "YES" && $column->null) || 
                    $columnInformation["COLUMN_DEFAULT"] != $column->defautValue || 
                    $columnInformation["COLUMN_COMMENT"] != $column->commentValue){
                    $changed = true;
                    $sql .= "ALTER TABLE {$this->table} {$operation} COLUMN {$column->name} {$column->type} {$column->null} {$column->defaut} {$column->comment};";
                }
                if($inDb && ($column->foreingKey && $columnInformation["COLUMN_KEY"] == "MUL") && $changed){
                    $ForeingkeyName = $this->getForeingKeyName($column->name);
                    if(isset($ForeingkeyName[0]))
                        $sql = "ALTER TABLE {$this->table} DROP FOREIGN KEY {$ForeingkeyName[0]};".$sql;
                    else 
                        throw new Exception($this->table.": Não foi possivel remover FOREIGN KEY para atualizar a coluna ".$column->name);
                }
                if(!$inDb || ($column->unique && $columnInformation["COLUMN_KEY"] != "UNI")){
                    $sql .= "ALTER TABLE {$this->table} ADD UNIQUE ({$column->name});";
                }
                if(!$column->unique && $columnInformation["COLUMN_KEY"] == "UNI"){
                    $sql .= "ALTER TABLE {$this->table} DROP INDEX {$column->name};";
                }
                if(!$inDb || ($column->foreingKey && $columnInformation["COLUMN_KEY"] != "MUL")){
                    $ForeingkeyName = $this->getForeingKeyName($column->name);
                    if(!isset($ForeingkeyName[0]))
                        $sql .= "ALTER TABLE {$this->table} ADD FOREIGN KEY ({$column->name}) REFERENCES {$column->foreingTable}({$column->foreingColumn});";
                }
                if(!$column->foreingKey && $columnInformation["COLUMN_KEY"] == "MUL"){
                    $ForeingkeyName = $this->getForeingKeyName($column->name);
                    if(isset($ForeingkeyName[0]))
                        $sql = "ALTER TABLE {$this->table} DROP FOREIGN KEY ({$ForeingkeyName[0]});".$sql;
                }
            }
        }

        $primaryChanged = false;
        foreach ($this->primary as $primary){
            if(!in_array($primary,$primaryKeyDb)){
                $primaryChanged = true;
                break;
            }
        }

        foreach ($primaryKeyDb as $primary){
            if(!in_array($primary,$this->primary)){
                $primaryChanged = true;
                break;
            }
        }

        if($primaryChanged){
            $sql .= "ALTER TABLE {$this->table} DROP PRIMARY KEY,ADD PRIMARY KEY(".implode(",",$this->primary).");";
        }

        $tableInformation = $this->getTableInformation();

        if(isset($tableInformation[0])){

            if($this->engine && $tableInformation[0]->ENGINE != $this->engine)
                $sql .= "ALTER TABLE {$this->table} ENGINE = {$this->engine};";

            if($this->collate && $tableInformation[0]->TABLE_COLLATION != $this->collate)
                $sql .= "ALTER TABLE {$this->table} COLLATE = {$this->collate};";

            if($this->comment && $tableInformation[0]->TABLE_COMMENT != $this->comment)
                $sql .= "ALTER TABLE {$this->table} COMMENT = '{$this->comment}';";

            if($this->isAutoIncrement && $tableInformation[0]->AUTO_INCREMENT == null){
                $sql .= "ALTER TABLE {$this->table} AUTO_INCREMENT = 1";
            }

        }

        if($this->indexs){
            $indexInformation = $this->getIndexInformation();
            if($indexInformation){

                $changed = [];
                foreach ($indexInformation as $indexDb){
                    if(!in_array($indexDb,array_keys($this->indexs))){
                        $sql .= "ALTER TABLE {$this->table} DROP INDEX {$indexDb};";
                        continue;
                    }

                    if(in_array($indexDb,array_keys($this->indexs))){
                        $columns = $this->getIndexColumns($indexDb);
                        if(count($columns)){
                            foreach ($this->indexs[$indexDb]["columns"] as $column){
                                if(!in_array($column,$columns)){
                                    $changed[] = $indexDb;
                                    $sql .= "ALTER TABLE {$this->table} DROP INDEX {$indexDb};";
                                    break;
                                }
                            }
                        }
                    }
                }

                if($changed){
                    $sql .= "SET FOREIGN_KEY_CHECKS = 0;";
                    foreach ($changed as $index){
                        $sql .= $this->indexs[$index]["sql"];
                    }
                    $sql .= "SET FOREIGN_KEY_CHECKS = 1;";
                }
                else{
                    foreach (array_keys($this->indexs) as $index) {
                        if(!in_array($index,$indexInformation))
                            $sql .= $this->indexs[$index]["sql"];
                    }
                }

            }else{
                foreach ($this->indexs as $index) {
                    $sql .= $index["sql"];
                }
            }
        }

        if($sql){
            $sql = $this->pdo->prepare($sql);
            if (!$sql) {
                throw new \Exception("Erro ao preparar a sql: " . implode(", ", $this->pdo->errorInfo()));
            }
            if (!$sql->execute()){
                throw new \Exception("Erro ao executar o sql: " . implode(", ", $stmt->errorInfo()));
            }
        }
    }

    public function getTable(){
        return $this->table;
    }

    //Pega as colunas da tabela e tranforma em Objeto
    private function getColumnsTable()
    {
        $sql = $this->pdo->prepare('SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,COLUMN_KEY,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_COMMENT FROM information_schema.columns WHERE TABLE_SCHEMA = "'.DBNAME.'" AND TABLE_NAME = "'.$this->table.'"');
       
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $rows;   
    }

    private function getTableInformation()
    {
        $sql = $this->pdo->prepare('SELECT ENGINE,TABLE_COLLATION,AUTO_INCREMENT,TABLE_COMMENT FROM information_schema.tables WHERE TABLE_SCHEMA = "'.DBNAME.'" AND TABLE_NAME = "'.$this->table.'" LIMIT 1');
       
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_CLASS, 'stdClass');
        }

        return $rows;   
    }

    private function getIndexInformation()
    {
        $sql = $this->pdo->prepare('SELECT INDEX_NAME FROM information_schema.statistics  WHERE TABLE_SCHEMA = "'.DBNAME.'" AND TABLE_NAME = "'.$this->table.'" GROUP BY INDEX_NAME HAVING COUNT(INDEX_NAME) > 1');
       
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $rows;   
    }

    private function getIndexColumns($indexName)
    {
        $sql = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.statistics  WHERE TABLE_SCHEMA = "'.DBNAME.'" AND TABLE_NAME = "'.$this->table.'" AND INDEX_NAME = "'.$indexName.'";');
       
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $rows;   
    }

    private function getForeingKeyName($column){
        $sql = $this->pdo->prepare('SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = "'.DBNAME.'" AND TABLE_NAME = "'.$this->table.'" AND COLUMN_NAME = "'.$column.'" AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1');
       
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $rows;   
    }
    
    private function validateName($name) {
        // Expressão regular para verificar se o nome da tabela contém apenas caracteres permitidos
        $regex = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';
        
        // Verifica se o nome da tabela corresponde à expressão regular
        if (preg_match($regex, $name)) {
            return true; // Nome da tabela é válido
        } else {
            return false; // Nome da tabela é inválido
        }
    }
}