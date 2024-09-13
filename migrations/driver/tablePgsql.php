<?php
namespace app\db\migrations\driver;

use app\db\connection;
use app\db\migrations\interface\table;
use app\db\migrations\column;
use Exception;

/**
 * Classe base para criação do banco de dados.
 */
class tablePgsql implements table
{
    /**
     * Nome da tabela.
     *
     * @var string
     */
    private string $table;

    /**
     * pdo.
     *
     * @var PDO
    */
    private \PDO $pdo;

    /**
     * Colunas.
     *
     * @var array
     */
    private array $columns = [];

    /**
     * index.
     *
     * @var array
    */
    private array $indexs = [];

    /**
     * primary.
     *
     * @var array
    */
    private array $primary = [];

    /**
     * isAutoIncrement.
     *
     * @var bool
    */
    private bool $isAutoIncrement = false;

   /**
     * tabela tem foreningKey
     *
     * @var bool
    */
    private bool $hasForeingKey = false;
    
    /**
     * array de classes das tabelas fk
     *
     * @var array
    */
    private array $foreningTablesClass = [];


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

        $this->dbname = DBNAME;
        
        if(!$this->validateName($this->table = strtolower(trim($table)))){
            throw new Exception("Nome é invalido");
        }
    }

    public function addColumn(column $column)
    {
        $column = $column->getColumn();

        if($column->foreingKey){
            $this->hasForeingKey = true;
            $this->foreningTablesClass[] = $column->foreingTableClass;
        }

        $column->columnSql = ["{$column->name} {$column->type} {$column->null} {$column->defaut}",$column->unique,$column->foreingKey," "];

        $this->columns[$column->name] = $column;

        if($column->primary){
            $this->primary[] = $column->name;
            $this->columns = array_reverse($this->columns,true);
        }

        return $this;
    }

    public function isAutoIncrement()
    {
        $this->isAutoIncrement = true;
        return $this;
    }

    public function addIndex(string $name,array $columns)
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

    public function create()
    {
        $sql = "SET session_replication_role = 'replica'; DROP TABLE IF EXISTS {$this->table};CREATE TABLE IF NOT EXISTS {$this->table}(";
        foreach ($this->columns as $column) {            
            $sql .= implode(",",array_filter($column->columnSql));
        }

        $sql = trim($sql);

        if($this->primary){
            $sql .= "PRIMARY KEY (".implode(",",$this->primary).")";
        }

        $sql .= ");";

        if($this->primary && $this->isAutoIncrement){
            $sql .= "ALTER TABLE {$this->table} ALTER ".implode(",",$this->primary)." ADD GENERATED ALWAYS AS IDENTITY;";
        }

        foreach ($this->indexs as $index) {
            $sql .= $index["sql"];
        }

        $sql = str_replace(",)",")",$sql)." SET session_replication_role = 'origin';";

        $instructios = explode(";",$sql);
        foreach ($instructios as $query){
            if($query)
                $this->pdo->query($query);
        }
    }

    public function execute($recreate = false)
    {

        if($recreate){
            $this->create();
        }

        $sql = "";

        $table = $this->getColumnsTable();

        if(!$table){
            return $this->create();
        }

        foreach ($table as $column){
            if(!in_array($column["column_name"],array_keys($this->columns))){
                $coluna = $column["column_name"];
                if($column["constraint_type"] == "FOREIGN KEY"){
                    $ForeingkeyName = $column["constraint_name"];
                    if($ForeingkeyName){
                        $sql = "ALTER TABLE {$this->table} DROP INDEX {$coluna};".$sql;
                        $sql = "ALTER TABLE {$this->table} DROP FOREIGN KEY {$ForeingkeyName};".$sql;
                    }
                }
                $sql .= "ALTER TABLE {$this->table} DROP COLUMN {$coluna};";
                break;
            }  
        }

        foreach ($this->columns as $column) {

            $inDb = false;
            foreach ($table as $tablecolumn){
                if($tablecolumn["column_name"] == $column->name){
                    $inDb = true;
                    break;
                }
            }

            $columnInformation = array_filter($table,fn($key) => in_array($column->name,$table[$key]),ARRAY_FILTER_USE_KEY);
            $primaryKeyDb = array_column(array_filter($table,fn($key) => in_array("PRIMARY KEY",$table[$key]),ARRAY_FILTER_USE_KEY),"column_name");
   
            if(isset($columnInformation[array_key_first($columnInformation)]))
                $columnInformation = $columnInformation[array_key_first($columnInformation)];
            else 
                $columnInformation = [];

            if(!$inDb || $columnInformation){
                
                !$inDb?$operation = "ADD":$operation = "MODIFY";

                if($inDb && $columnInformation["data_type"] == "character varying"){
                    $columnInformation["data_type"] = "varchar";
                }

                if($inDb && $columnInformation["data_type"] == "integer" && $column->type == "INT"){
                    $columnInformation["data_type"] = "int";
                }

                if($inDb && $columnInformation["data_type"] == "numeric" && explode("(",$column->type)[0] == "DECIMAL"){
                    $columnInformation["data_type"] = "decimal";
                }

                if($inDb && $columnInformation["data_type"] == "time without time zone" && $column->type == "TIME"){
                    $columnInformation["data_type"] = "time";
                }

                if($inDb && $columnInformation["data_type"] == "timestamp without time zone"  && $column->type == "TIMESTAMP"){
                    $columnInformation["data_type"] = "timestamp";
                }

                if($inDb && $columnInformation["data_type"] == "timestamp with time zone"  && $column->type == "TIMESTAMP"){
                    $columnInformation["data_type"] = "timestamp";
                }

                $removed = false;
                $changed = false;
                if(!$inDb || strtolower(explode("(",$column->type)[0]) != $columnInformation["data_type"] || 
                    ($columnInformation["is_nullable"] == "YES" && $column->null) || 
                    ($columnInformation["is_nullable"] == "NO" && !$column->null && !$column->primary) || 
                    (str_replace("'","",explode("::",$columnInformation["column_default"]??"")[0]) != $column->defautValue))
                {
                    $changed = true;
                    $sql .= "ALTER TABLE {$this->table} {$operation} COLUMN {$column->name} {$column->type} {$column->null} {$column->defaut};";
                }
                if($inDb && ($column->foreingKey && $columnInformation["constraint_type"] == "FOREIGN KEY") && $changed){
                    $ForeingkeyName = $columnInformation["constraint_name"];
                    if($ForeingkeyName){
                        $sql = "ALTER TABLE {$this->table} DROP FOREIGN KEY {$ForeingkeyName};".$sql;
                        $sql = "ALTER TABLE {$this->table} DROP INDEX {$column->name};".$sql;
                        $removed = true;
                    }else 
                        throw new Exception($this->table.": Não foi possivel remover FOREIGN KEY para atualizar a coluna ".$column->name);
                }
                if(!$inDb && $column->unique || ($column->unique && $columnInformation["constraint_type"] != "UNIQUE")){
                    $sql .= "ALTER TABLE {$this->table} ADD UNIQUE ({$column->name});";
                }
                if($inDb && !$column->unique && $columnInformation["constraint_type"] == "UNIQUE"){
                    $sql .= "ALTER TABLE {$this->table} DROP INDEX {$column->name};";
                }
                if(!$inDb && $column->foreingKey || ($column->foreingKey && $columnInformation["constraint_type"] != "FOREIGN KEY") || ($column->foreingKey && $removed)){
                    $ForeingkeyName = $columnInformation["constraint_name"];
                    if($ForeingkeyName)
                        $sql .= "ALTER TABLE {$this->table} ADD FOREIGN KEY ({$column->name}) REFERENCES {$column->foreingTable}({$column->foreingColumn});";
                }
                if($inDb && !$column->foreingKey && $columnInformation["constraint_type"] == "FOREIGN KEY"){
                    $ForeingkeyName = $columnInformation["constraint_name"];
                    if($ForeingkeyName)
                        $sql = "ALTER TABLE {$this->table} DROP FOREIGN KEY {$ForeingkeyName};".$sql;
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
                    $sql .= "SET session_replication_role = 'replica';";
                    foreach ($changed as $index){
                        $sql .= $this->indexs[$index]["sql"];
                    }
                    $sql .= "SET session_replication_role = 'origin';";
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
            $instructios = explode(";",$sql);
            foreach ($instructios as $query){
                if($query)
                    $this->pdo->query($query);
            }
        }
    }

    public function hasForeignKey()
    {
        return $this->hasForeingKey;
    }
    
    public function getForeignKeyTablesClasses()
    {
        return $this->foreningTablesClass;
    }

    public function getTable()
    {
        return $this->table;
    }

    public function getColumnsName()
    {
        return array_keys($this->columns);
    }
    
    public function exists()
    {
        $sql = $this->pdo->prepare("SELECT table_name FROM information_schema.tables WHERE table_catalog = :db AND table_name = :table LIMIT 1;");
        
        $sql->bindParam(':db', $this->dbname);
        $sql->bindParam(':table', $this->table);
        $sql->execute();

        return $sql->rowCount() > 0;   
    }

    //Pega as colunas da tabela e tranforma em Objeto
    private function getColumnsTable()
    {
        $sql = $this->pdo->prepare("SELECT 
                        ic.table_catalog,
                        ic.table_name,
                        ic.column_name,
                        ic.data_type,
                        ic.is_nullable,
                        ic.column_default, 
                        tc.constraint_name, 
                        tc.table_name AS constraint_table_name,
                        tc.constraint_type,
                        tc.constraint_name,
                        kcu.column_name AS key_column_name, 
                        ccu.table_name AS foreign_table_name,
                        ccu.column_name AS foreign_column_name 
                    FROM 
                        information_schema.columns AS ic
                    LEFT JOIN 
                        information_schema.key_column_usage AS kcu
                        ON ic.table_name = kcu.table_name
                        AND ic.column_name = kcu.column_name
                    LEFT JOIN 
                        information_schema.table_constraints AS tc 
                        ON kcu.constraint_name = tc.constraint_name
                    LEFT JOIN 
                        information_schema.constraint_column_usage AS ccu
                        ON tc.constraint_name = ccu.constraint_name 
                    WHERE ic.table_catalog = :db AND ic.table_name = :table;");
       
        $sql->bindParam(':db', $this->dbname);
        $sql->bindParam(':table', $this->table);
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_ASSOC);
        }

        return $rows;   
    }

    private function getIndexInformation()
    {
        $sql = $this->pdo->prepare("
            SELECT indexname 
            FROM pg_indexes 
            WHERE schemaname = 'public' 
            AND tablename = :table 
            GROUP BY indexname 
            HAVING COUNT(indexname) > 1
        ");
    
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
        $sql = $this->pdo->prepare("
            SELECT a.attname as column_name
            FROM pg_class t, pg_class i, pg_index ix, pg_attribute a 
            WHERE t.oid = ix.indrelid 
            AND i.oid = ix.indexrelid 
            AND a.attrelid = t.oid 
            AND a.attnum = ANY(ix.indkey) 
            AND t.relkind = 'r' 
            AND t.relname = :table 
            AND i.relname = :index_name
        ");
    
        $sql->bindParam(':table', $this->table);
        $sql->bindParam(':index_name', $indexName);
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