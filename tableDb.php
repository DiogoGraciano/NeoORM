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
        if (!$this->pdo)
            $this->pdo = connectionDb::getInstance()->startConnection();

        $this->engine = $engine;

        $this->collate = $collate;

        $this->comment = $comment;
        
        if(!$this->validateName($this->table = strtolower(trim($table)))){
            throw new Exception("Nome é invalido");
        }
    }

    public function addColumn(columnDb $column){
        $column = $column->getColumn();

        $column->columnSql = ["{$column->name} {$column->type} {$column->null} {$column->defaut} {$column->comment}",$column->primary,$column->unique,$column->foreingKey," "];

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
            $this->indexs[$name] = "CREATE INDEX {$name} ON {$this->table} (".implode(",",$columnsFinal).";";
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

        $sql .= ")ENGINE={$this->engine} COLLATE={$this->collate}; COMMENT='{$this->comment}'";

        if($this->isAutoIncrement){
            $sql .= "ALTER TABLE {$this->table} AUTO_INCREMENT = 1";
        }
        
        foreach ($this->indexs as $index) {
            $sql .= $index;
        }

        $sql = $this->pdo->prepare($sql);

        $sql->execute();
    }

    public function execute($recreate = false){

        if($recreate){
            $this->create();
        }

        $table = $this->getColumnsTable();

        if(!$table){
            return $this->create();
        }

        $sql = "";

        foreach ($table as $columnDb){
            if(!in_array($columnDb,array_keys($this->columns))){
                $sql .= "ALTER TABLE {$this->table} DROP COLUMN {$columnDb};";
                break;
            }  
        }
        
        foreach ($this->columns as $column) {

            $inDb = false;
            foreach ($table as $columnDb){
                if($columnDb == $column->name){
                    $inDb = true;
                    break;
                }
            }

            $columnInformation = $this->getColumnInformation($column->name);

            if(!$inDb || isset($columnInformation[0])){
                
                !$inDb?$operation = "ADD":$operation = "MODIFY";
               
                if(!$inDb || strtolower($column->type) != $columnInformation[0]->COLUMN_TYPE || 
                    ($columnInformation[0]->IS_NULLABLE == "YES" && $column->null) || 
                    $columnInformation[0]->COLUMN_DEFAULT != $column->defautValue || 
                    $columnInformation[0]->COLUMN_COMMENT != $column->commentValue){
                    $sql .= "ALTER TABLE {$this->table} {$operation} COLUMN {$column->name} {$column->type} {$column->null} {$column->defaut} {$column->comment};";
                }

                if($column->primary && $columnInformation[0]->COLUMN_KEY != "PRI"){
                    $sql .= "ALTER TABLE {$this->table} ADD PRIMARY KEY ({$column->name});";
                }

                if($column->unique && $columnInformation[0]->COLUMN_KEY != "UNI"){
                    $sql .= "ALTER TABLE {$this->table} ADD UNIQUE ({$column->name});";
                }

                if($column->foreingKey && $columnInformation[0]->COLUMN_KEY != "MUL"){
                    $sql .= "ALTER TABLE {$this->table} ADD FOREIGN KEY ({$column->foreingTable}) REFERENCES {$column->foreingColumn}({$column->foreingTable});";
                }
            }
        }

        $tableInformation = $this->getTableInformation();

        if(isset($tableInformation[0])){

            if($this->engine && $tableInformation[0]->ENGINE != $this->engine)
                $sql .= "ALTER TABLE {$this->table} ENGINE = {$this->engine};";

            if($this->collate && $tableInformation[0]->TABLE_COLLATION != $this->collate)
                $sql .= "ALTER TABLE {$this->table} COLLATE = {$this->collate};";

            if($this->comment && $tableInformation[0]->TABLE_COMMENT != $this->comment)
                $sql .= "ALTER TABLE {$this->table} COMMENT = {$this->comment};";

            if($this->isAutoIncrement && $tableInformation[0]->AUTO_INCREMENT == null){
                $sql .= "ALTER TABLE {$this->table} AUTO_INCREMENT = 1";
            }

        }

        if($this->indexs){
            $indexInformation = $this->getIndexInformation();
            if($indexInformation){
                foreach ($indexInformation as $indexDb){
                    if(!in_array($indexDb,array_keys($this->indexs))){
                        $sql .= "ALTER TABLE {$this->table} DROP INDEX {$indexDb};";
                    }
                }

                foreach (array_keys($this->indexs) as $index) {
                    if(!in_array($index,$indexInformation))
                        $sql .= $this->indexs[$index];
                }
            }
        }

        if($sql){
            $sql = $this->pdo->prepare($sql);

            $sql->execute();
        }
    }

    public function getTable(){
        return $this->table;
    }

    //Pega as colunas da tabela e tranforma em Objeto
    private function getColumnsTable()
    {
        $sql = $this->pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE TABLE_SCHEMA = "'.DBNAME.'" AND TABLE_NAME = "'.$this->table.'"');
       
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $rows;   
    }

    private function getColumnInformation($column)
    {
        $sql = $this->pdo->prepare('SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,COLUMN_KEY,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_COMMENT FROM information_schema.columns WHERE TABLE_SCHEMA = "'.DBNAME.'" AND TABLE_NAME = "'.$this->table.'" AND COLUMN_NAME = "'.$column.'" LIMIT 1');
       
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_CLASS, 'stdClass');
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