<?php
namespace Diogodg\Neoorm\Migrations\Driver;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\Migrations\Interface\Table;
use Diogodg\Neoorm\Migrations\Column;
use Exception;

/**
 * Classe base para criação do banco de dados.
 */
class TableMysql implements Table
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
     * @var bool
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
     * tabela tem foreningKey
     *
     * @var bool
    */
    private $hasForeingKey = false;
    
    /**
     * array de classes das tabelas fk
     *
     * @var array
    */
    private $foreningTables = [];

    /**
     * array de com os comandos sql
     *
     * @var array
    */
    private array $foreningKeySql = [];

    /**
     * array de colunas da tabela que tem fk
     *
     * @var array
    */
    private array $foreningColumns = [];

    /**
     * constraints customizadas
     *
     * @var array
     */
    private array $constraints = [];

    /**
     * outros comandos.
     *
     * @var string
    */
    private $comment = "";

    /**
     * Nome da tabela.
     *
     * @var string
     */
    private string $dbname = "";

    function __construct(string $table,string $engine="InnoDB",string $collate="utf8mb4_general_ci",string $comment = "")
    {
        // Inicia a Conexão
        $this->pdo = connection::getConnection();
        
        $this->engine = $engine;

        $this->collate = $collate;

        $this->comment = $comment;

        $this->dbname = Config::getDbName();
        
        if(!$this->validateName($this->table = strtolower(trim($table)))){
            throw new Exception("Nome da tabela é invalido");
        }
    }

    public function addColumn(column $column)
    {
        $column = $column->getColumn();

        $column->columnSql = ["{$column->name} {$column->type} {$column->null} {$column->default} {$column->comment}",$column->unique," "];

        $this->columns[$column->name] = $column;

        if($column->primary){
            $this->primary[] = $column->name;
            $this->columns = array_reverse($this->columns,true);
        }

        return $this;
    }

    public function addForeignKey(string $foreignTable,string $column = "id",string $foreignColumn = "id",string $onDelete = "RESTRICT"):self
    {
        $onDeleteOptions = [
            'CASCADE',
            'SET NULL',
            'SET DEFAULT',
            'RESTRICT',
            'NO ACTION'
        ];

        if(!in_array(strtoupper($onDelete),$onDeleteOptions)){
            throw new Exception("onDelete na ForeingKey {$foreignTable}.{$foreignColumn} invalido para tabela: ".$this->table);
        }

        $this->hasForeingKey = true;
        $this->foreningTables[$foreignColumn] = $foreignTable;
        $this->foreningColumns[$foreignColumn] = $column;
        $this->foreningKeySql[] = " ALTER TABLE {$this->table} ADD CONSTRAINT 
                                    ".$this->table."_".$column."_".$foreignTable."_".$foreignColumn." 
                                    FOREIGN KEY ({$column}) REFERENCES {$foreignTable} 
                                    ({$foreignColumn}) ON DELETE {$onDelete};";
        
        return $this;
    }

    public function isAutoIncrement():self
    {
        $this->isAutoIncrement = true;
        return $this;
    }

    public function getAutoIncrement():bool
    {
        return $this->isAutoIncrement;
    }

    public function addIndex(string $name,array $columns):self
    {
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

    public function addConstraint(string $name, string $type, array $columns): self
    {
        $name = strtolower(trim($name));
        $type = strtoupper(trim($type));
        
        if (!$this->validateName($name)) {
            throw new Exception("Nome da constraint é inválido: " . $name);
        }

        if (empty($columns)) {
            throw new Exception("É necessário especificar pelo menos uma coluna para a constraint");
        }

        // Valida se as colunas existem na tabela
        if ($this->columns) {
            $tableColumns = array_keys($this->columns);
            foreach ($columns as $column) {
                $column = strtolower(trim($column));
                if (!in_array($column, $tableColumns)) {
                    throw new Exception("Coluna não encontrada na tabela: " . $column);
                }
            }
        }

        $columnsList = implode(",", array_map('strtolower', array_map('trim', $columns)));

        switch ($type) {
            case 'CHECK':
                throw new Exception("Para constraints CHECK, use addColumn() com validação personalizada");
                
            case 'UNIQUE':
                $constraintSql = "ALTER TABLE {$this->table} ADD CONSTRAINT {$name} UNIQUE ({$columnsList});";
                break;
                
            case 'PRIMARY KEY':
                $constraintSql = "ALTER TABLE {$this->table} ADD CONSTRAINT {$name} PRIMARY KEY ({$columnsList});";
                break;
                
            case 'FOREIGN KEY':
                throw new Exception("Para constraints FOREIGN KEY, use addForeignKey()");
                
            default:
                throw new Exception("Tipo de constraint não suportado: " . $type);
        }

        $this->constraints[$name] = [
            'type' => $type,
            'columns' => $columns,
            'sql' => $constraintSql
        ];

        return $this;
    }

    public function create()
    {
        $sql = "SET FOREIGN_KEY_CHECKS = 0; DROP TABLE IF EXISTS {$this->table};CREATE TABLE IF NOT EXISTS {$this->table}(";
        foreach ($this->columns as $column) {            
            $sql .= implode(",",array_filter($column->columnSql));
        }

        if($this->primary)
            $sql .= "PRIMARY KEY (".implode(",",$this->primary).")";

        $sql .= ")ENGINE={$this->engine} COLLATE={$this->collate} COMMENT='{$this->comment}';";

        if($this->isAutoIncrement && $this->primary){
            foreach ($this->primary as $name){
                $sql .= "ALTER TABLE {$this->table} MODIFY COLUMN {$name} INT AUTO_INCREMENT; ";
            }
        }
        
        foreach ($this->indexs as $index) {
            $sql .= $index["sql"];
        }

        // Adiciona constraints customizadas
        foreach ($this->constraints as $constraint) {
            $sql .= $constraint["sql"];
        }

        $sql = str_replace(", )",")",$sql);
        $sql = str_replace(",)",")",$sql)." SET FOREIGN_KEY_CHECKS = 1;";

        if($this->table == "usuario"){
            echo $sql;
        }

        $this->pdo->exec($sql);
    }

    public function addForeignKeytoTable(){
        foreach ($this->foreningKeySql as $sql) {
            $this->pdo->exec($sql);
        }
    }

    public function update()
    {
        $sql = "";

        $table = $this->getColumnsTable();

        foreach ($table as $column){
            if(!in_array($column["COLUMN_NAME"],array_keys($this->columns))){
                $coluna = $column["COLUMN_NAME"];
                if($column["COLUMN_KEY"] == "MUL"){
                    $ForeingkeyName = $this->getForeingKeyName($coluna);
                    if(isset($ForeingkeyName[0])){
                        $sql = "ALTER TABLE {$this->table} DROP INDEX {$coluna};".$sql;
                        $sql = "ALTER TABLE {$this->table} DROP FOREIGN KEY {$ForeingkeyName[0]};".$sql;
                    }
                }
                $sql .= "ALTER TABLE {$this->table} DROP COLUMN {$coluna};";
            }  
        }

        $primaryKeyDb = [];
        foreach ($this->columns as $column) {

            $inDb = false;
            foreach ($table as $tablecolumn){
                if($tablecolumn["COLUMN_NAME"] == $column->name){
                    $inDb = true;
                    break;
                }
            }

            $columnInformation = array_filter($table,fn($key) => in_array($column->name,$table[$key]),ARRAY_FILTER_USE_KEY);
            $primaryKeyDb = array_column(array_filter($table,fn($key) => in_array("PRI",$table[$key]),ARRAY_FILTER_USE_KEY),"COLUMN_NAME");

            if(isset($columnInformation[array_key_first($columnInformation)]))
                $columnInformation = $columnInformation[array_key_first($columnInformation)];
            else 
                $columnInformation = [];

            if(!$inDb || $columnInformation){
                
                !$inDb?$operation = "ADD":$operation = "MODIFY";
               
                $changed = false;
                $removed = false;
                if(!$inDb || strtolower(explode("(",$column->type)[0]) != $columnInformation["COLUMN_TYPE"] || 
                    ($columnInformation["IS_NULLABLE"] == "YES" && $column->null) || 
                    ($columnInformation["IS_NULLABLE"] == "NO" && !$column->null) || 
                    $columnInformation["COLUMN_DEFAULT"] != $column->defaultValue || 
                    $columnInformation["COLUMN_COMMENT"] != $column->commentValue){
                    $changed = true;
                    $sql .= "ALTER TABLE {$this->table} {$operation} COLUMN {$column->name} {$column->type} {$column->null} {$column->default} {$column->comment};";
                }
                if($inDb && (in_array($column->name,$this->foreningColumns) && $columnInformation["COLUMN_KEY"] == "MUL") && $changed){
                    $ForeingkeyName = $this->getForeingKeyName($column->name);
                    if(isset($ForeingkeyName[0])){
                        $sql = "ALTER TABLE {$this->table} DROP INDEX {$column->name};".$sql;
                        $sql = "ALTER TABLE {$this->table} DROP FOREIGN KEY {$ForeingkeyName[0]};".$sql;
                        $removed = true;
                    }else 
                        throw new Exception($this->table.": Não foi possivel remover FOREIGN KEY para atualizar a coluna ".$column->name);
                }
                if(!$inDb && $column->unique || ($column->unique && $columnInformation["COLUMN_KEY"] != "UNI")){
                    $sql .= "ALTER TABLE {$this->table} ADD UNIQUE ({$column->name});";
                }
                if($inDb && !$column->unique && $columnInformation["COLUMN_KEY"] == "UNI"){
                    $sql .= "ALTER TABLE {$this->table} DROP INDEX {$column->name};";
                }
                if(!$inDb && in_array($column->name,$this->foreningColumns) || (in_array($column->name,$this->foreningColumns) && $columnInformation["COLUMN_KEY"] != "MUL") || (in_array($column->name,$this->foreningColumns) && $removed)){
                    $ForeingkeyName = $this->getForeingKeyName($column->name);
                    if(!isset($ForeingkeyName[0])){
                        $key = array_search($column->name, $this->foreningColumns);
                        $sql .= "ALTER TABLE {$this->table} ADD FOREIGN KEY ({$column->name}) REFERENCES {$this->foreningTables[$key]}({$key});";
                    }
                }
                if($inDb && !in_array($column->name,$this->foreningColumns) && $columnInformation["COLUMN_KEY"] == "MUL"){
                    $ForeingkeyName = $this->getForeingKeyName($column->name);
                    if(isset($ForeingkeyName[0])){
                        $sql = "ALTER TABLE {$this->table} DROP INDEX {$column->name};".$sql;
                        $sql = "ALTER TABLE {$this->table} DROP FOREIGN KEY {$ForeingkeyName[0]};".$sql;
                    }
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

        // Gerenciar constraints customizadas
        if ($this->constraints) {
            $currentConstraints = $this->getConstraintInformation();
            
            // Remove constraints que não existem mais
            foreach ($currentConstraints as $constraintName) {
                if (!array_key_exists($constraintName, $this->constraints)) {
                    $sql .= "ALTER TABLE {$this->table} DROP CONSTRAINT {$constraintName};";
                }
            }
            
            // Adiciona ou atualiza constraints
            foreach ($this->constraints as $constraintName => $constraint) {
                if (!in_array($constraintName, $currentConstraints)) {
                    $sql .= $constraint["sql"];
                }
            }
        }

        if($sql){
            $this->pdo->exec($sql);
        }
    }

    public function hasForeignKey():bool
    {
        return $this->hasForeingKey;
    }
    
    public function getForeignKeyTables():array
    {
        return $this->foreningTables;
    }

    public function getTable():string
    {
        return $this->table;
    }

    public function getColumns():array
    {
        return $this->columns;
    }
    
    public function exists():bool
    {
        $sql = $this->pdo->prepare("SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table LIMIT 1");
        
        $sql->bindParam(':db', $this->dbname);
        $sql->bindParam(':table', $this->table);
        $sql->execute();

        return $sql->rowCount() > 0;   
    }

    private function getColumnsTable()
    {
        $sql = $this->pdo->prepare("SELECT TABLE_SCHEMA,TABLE_NAME,COLUMN_NAME,COLUMN_TYPE,COLUMN_KEY,IS_NULLABLE,COLUMN_DEFAULT,COLUMN_COMMENT FROM information_schema.columns WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table");
       
        $sql->bindParam(':db', $this->dbname);
        $sql->bindParam(':table', $this->table);
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $rows;   
    }

    private function getTableInformation()
    {
        $sql = $this->pdo->prepare("SELECT ENGINE,TABLE_COLLATION,AUTO_INCREMENT,TABLE_COMMENT FROM information_schema.tables WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table LIMIT 1");
       
        $sql->bindParam(':db', $this->dbname);
        $sql->bindParam(':table', $this->table);
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_CLASS, 'stdClass');
        }

        return $rows;   
    }

    private function getIndexInformation()
    {
        $sql = $this->pdo->prepare("SELECT INDEX_NAME FROM information_schema.statistics  WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table GROUP BY INDEX_NAME HAVING COUNT(INDEX_NAME) > 1");
       
        $sql->bindParam(':db', $this->dbname);
        $sql->bindParam(':table', $this->table);
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $rows;   
    }

    private function getIndexColumns($indexName)
    {
        $sql = $this->pdo->prepare("SELECT COLUMN_NAME FROM information_schema.statistics  WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND INDEX_NAME = :indexName;");
       
        $sql->bindParam(':db', $this->dbname);
        $sql->bindParam(':table', $this->table);
        $sql->bindParam(':indexName', $indexName);
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $rows;   
    }

    private function getForeingKeyName($column){
        $sql = $this->pdo->prepare("SELECT CONSTRAINT_NAME FROM information_schema.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = :db AND TABLE_NAME = :table AND COLUMN_NAME = :column AND REFERENCED_TABLE_NAME IS NOT NULL LIMIT 1");
       
        $sql->bindParam(':db', $this->dbname);
        $sql->bindParam(':table', $this->table);
        $sql->bindParam(':column', $column);
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $rows;   
    }
    
    private function getConstraintInformation()
    {
        $sql = $this->pdo->prepare("
            SELECT CONSTRAINT_NAME 
            FROM information_schema.TABLE_CONSTRAINTS 
            WHERE TABLE_SCHEMA = :db 
            AND TABLE_NAME = :table 
            AND CONSTRAINT_TYPE IN ('UNIQUE', 'CHECK')
            AND CONSTRAINT_NAME != 'PRIMARY'
        ");
       
        $sql->bindParam(':db', $this->dbname);
        $sql->bindParam(':table', $this->table);
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN);
        }

        return $rows;   
    }

    private function validateName($name) {

        $regex = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';
        
        if (preg_match($regex, $name)) {
            return true;
        } else {
            return false;
        }
    }
}