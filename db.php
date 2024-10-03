<?php
namespace app\db;
use Exception;
use PDO;
use PDOStatement;

/**
 * Classe base para interação com o banco de dados.
 */
class db
{
    /**
     * Tabela atual.
     *
     * @var string
     */
    private string $table;

    /**
     * Classe da tabela.
     *
     * @var string
     */
    private string $class;

    /**
     * Objeto da tabela.
     *
     * @var array
    */
    private array $object = [];

    /**
     * array de colunas da tabela.
     *
     * @var array
    */
    private array $columns;

    /**
     * array com os joins informados.
     *
     * @var array
    */
    private array $joins =[];

    /**
     * debug está ativo?.
     *
     * @var array
    */
    private bool $debug = false;

    /**
     * array com os propriedades informadas.
     *
     * @var array
    */
    private array $propertys =[];

    /**
     * array com os filtros informadas.
     *
     * @var array
    */
    private array $filters =[];

    /**
     * valores do bindparam.
     *
     * @var array
    */
    private array $valuesBind = [];


    /**
     * valores do bindparam das Propertys.
     *
     * @var array
    */
    private array $valuesBindProperty = [];

    /**
     * contador de parametros do bindparam.
     *
     * @var int
    */
    private int $counterBind = 1;

    /**
     * Constante do operado AND.
     *
     * @var string
    */
    const AND = "AND";

    /**
     * Constante do operado OR.
     *
     * @var string
    */
    const OR = "OR";

    /**
     * instacia do PDO.
     *
     * @var PDO
    */
    private PDO $pdo;


    /**
     * todos retornos são como array?.
     *
     * @var bool
    */
    private bool $asArray = false;

    /**
     * Construtor da classe.
     * 
     * @param string $table Nome da tabela do banco de dados.
     */
    public function __construct(string $table,string|null $class = null)
    {
        $this->pdo = connection::getConnection();

        $this->table = $table;

        $this->class = $class;

        $this->getColumnTable();

        $this->setObjectNull();
    }

    public function __set($name,$value)
    {
        return $this->object[$name] = $value;
    }

    public function __get($name)
    {
        if (array_key_exists($name, $this->object)) {
            return $this->object[$name];
        }

        $trace = debug_backtrace();
        trigger_error(
            'Column not found: ' . $name .
            ' in ' . $trace[0]['file'] .
            ' on line ' . $trace[0]['line'],
            E_USER_ERROR);

        return null;
    }

    public function __isset($name)
    {
        return isset($this->object[$name]);
    }

    public function __unset($name)
    {
        unset($this->object[$name]);
    }

    protected function setDebug():DB
    {
        $this->debug = true;

        return $this;
    }

    protected function asArray():DB
    {
        $this->asArray = true;

        return $this;
    }

    public function getArrayData():array
    {
        return $this->object;
    }

    protected function setObjectNull():DB
    {
        $this->object = [];

        foreach ($this->columns as $column){
            $this->object[$column] = null;
        }

        return $this;
    }

    protected function getColumns():array
    {
        return $this->columns;
    }

    /**
     * Seleciona registros com base em uma instrução SQL.
     * 
     * @param string $sql_instruction A instrução SQL a ser executada.
     * @return array Retorna um array contendo os registros selecionados.
     */
    protected function selectInstruction(string $sql_instruction):array
    {
        try {
            $sql = $this->executeSql($sql_instruction);

            $rows = [];

            if ($sql->rowCount() > 0) {
                $this->asArray == false ? $rows = $sql->fetchAll(\PDO::FETCH_CLASS|\PDO::FETCH_PROPS_LATE,get_class($this),[$this->table]) : $rows = $sql->fetchAll(\PDO::FETCH_ASSOC);
            }    

            return $rows;

        } catch (\Exception $e) {
            throw new Exception('Tabela: '.$this->table.' '.$e->getMessage());
        }

        return [];
    }
    
    /**
     * Seleciona todos os registros da tabela.
     * 
     * @return array Retorna um array contendo todos os registros da tabela.
     */
    protected function count():int
    {
        try {
            $sql = 'SELECT count(*) FROM ' . $this->table;
            $sql .= implode('', $this->joins);
            if ($this->filters) {
                $sql .= " WHERE " . implode(' ', array_map(function($filter, $i) {
                    return $i === 0 ? substr($filter, 4) : $filter;
                }, $this->filters, array_keys($this->filters)));
            }
            $sql .= implode('', $this->propertys);

            $sql = $this->pdo->prepare($sql);

            if ($this->debug)
                $sql->debugDumpParams();

            $lastcount = 1;
            if($this->valuesBind){
                foreach ($this->valuesBind as $key => $data) {
                    $lastcount = $key;
                    $sql->bindParam($key,$data[0],$data[1]);
                }
                foreach ($this->valuesBindProperty as $data) {
                    $sql->bindParam($lastcount,$data[0],$data[1]);
                    $lastcount++;
                }
            }
                
            $sql->execute();

            if ($this->debug)
                $sql->debugDumpParams();

            $count = $sql->fetchAll(\PDO::FETCH_COLUMN, 0);

            return isset($count[0])?$count[0]:0;
        } catch (\Exception $e) {
            throw new Exception('Tabela: '.$this->table.' Erro ao execultar o count');
        }
    }

    /**
     * Seleciona todos os registros da tabela.
     * 
     * @return array Retorna um array contendo todos os registros da tabela.
     */
    protected function selectAll():array
    {
        $sql = "SELECT * FROM " . $this->table;
        $sql .= implode('', $this->joins);
        if ($this->filters) {
            $sql .= " WHERE " . implode(' ', array_map(function($filter, $i) {
                return $i === 0 ? substr($filter, 4) : $filter;
            }, $this->filters, array_keys($this->filters)));
        }
        $sql .= implode('', $this->propertys);

        $object = $this->selectInstruction($sql);

        return $object;
    }

    /**
     * Seleciona registros com base em colunas específicas.
     * 
     * @param string ...$columns Colunas a serem selecionadas.
     * @return array Retorna um array contendo os registros selecionados.
     */
    protected function selectColumns(...$columns):array
    {
        $sql = "SELECT ";
        $sql .= implode(",",$columns);  
        $sql .= " FROM ".$this->table;
        $sql .= implode('', $this->joins);
        if ($this->filters) {
            $sql .= " WHERE " . implode(' ', array_map(function($filter, $i) {
                return $i === 0 ? substr($filter, 4) : $filter;
            }, $this->filters, array_keys($this->filters)));
        }
        $sql .= implode('', $this->propertys);
        $object = $this->selectInstruction($sql);
        return $object;
    }


    /**
     * Salva ou Atualiza um registro na tabela.
     * 
     * @param object $values Objeto contendo os valores a serem salvos.
     * @return bool|int Retorna id do ultimo registro inserido se a operação foi bem-sucedida, caso contrário, retorna false.
    */
    protected function store():bool
    {
        try{
            foreach ($this->columns as $columns){
                $columnsDb[$columns] = true;
            }

            if ($this->object && !isset($this->object[0])) {
                $objectFilter = array_intersect_key($this->object, $columnsDb);

                if (!isset($objectFilter[$this->columns[0]]) || !$objectFilter[$this->columns[0]]) {
                    // Incrementando o ID
                    $objectFilter[$this->columns[0]] = $this->getlastIdBd() + 1;

                    // Montando a instrução SQL
                    $sql_instruction = "INSERT INTO {$this->table} (";
                    $keysBD = implode(",", array_keys($objectFilter));
                    $valuesBD = "";

                    // Preparando os valores para bind e montando a parte dos valores na instrução SQL
                    foreach ($objectFilter as $key => $data) {
                        $valuesBD .= "?,";
                        $this->setBind($data);
                    }
                    $keysBD = rtrim($keysBD, ",");
                    $sql_instruction .= $keysBD . ") VALUES (";
                    $valuesBD = rtrim($valuesBD, ",");
                    $sql_instruction .= $valuesBD . ");";
                } elseif (isset($objectFilter[$this->columns[0]]) && $objectFilter[$this->columns[0]]) {
                    $sql_instruction = "UPDATE {$this->table} SET ";
                    foreach ($objectFilter as $key => $data) {
                        if ($key === $this->columns[0]) // Ignorando a primeira coluna (chave primária)
                            continue;

                        $sql_instruction .= "{$key}=?,";
                        $this->setBind($data);
                    }
                    $sql_instruction = rtrim($sql_instruction, ",") . " WHERE ";

                    // Adicionando cláusula WHERE
                    if ($this->filters) {
                        $sql_instruction .= implode(" AND ", $this->filters);
                    } else {
                        $sql_instruction .= "{$this->columns[0]}=?";
                        $this->setBind($objectFilter[$this->columns[0]]);
                    }
                }

                $this->executeSql($sql_instruction);

                $this->object[$this->columns[0]] = $objectFilter[$this->columns[0]];

                return true;
            }
            throw new Exception('Tabela: '.$this->table." Objeto não está setado");
        } catch (\Exception $e) {
            throw new Exception('Tabela: '.$this->table.' '.$e->getMessage());
        }
    }    
    /**
     * Salva um registro na tabela com múltiplas chaves primárias.
     * 
     * @param object $values Objeto contendo os valores a serem salvos.
     * @return bool Retorna true se a operação foi bem-sucedida, caso contrário, retorna false.
    */
    protected function storeMutiPrimary():bool{
        try {

            foreach ($this->columns as $columns){
                $columnsDb[$columns] = true;
            }

            if ($this->object) {
                
                $objectFilter = array_intersect_key($this->object, $columnsDb);

                $sql_instruction = "INSERT INTO {$this->table} (";
                $keysBD = implode(",", array_keys($objectFilter));
                $valuesBD = "";

                // Preparando os valores para bind e montando a parte dos valores na instrução SQL
                foreach ($objectFilter as $data) {
                    $valuesBD .= "?,";
                    $this->setBind($data);
                }
                $keysBD = rtrim($keysBD, ",");
                $sql_instruction .= $keysBD . ") VALUES (";
                $valuesBD = rtrim($valuesBD, ",");
                $sql_instruction .= $valuesBD . ");";
               
                $this->executeSql($sql_instruction);

                return true;
            }
        } catch (\Exception $e) {
            throw new Exception('Tabela: '.$this->table.' '.$e->getMessage());
        }
        throw new Exception('Tabela: '.$this->table." Objeto não está setado");
    }

    protected function delete(string|int $id):bool
    {
        try {
            

            if ($id){
                $this->setBind($id);
                
                $this->executeSql("DELETE FROM " . $this->table . " WHERE " . $this->columns[0] . "= ?");

                return true;
            }
            throw new Exception('Tabela: '.$this->table." ID Precisa ser informado para excluir");
        } catch (\Exception $e) {
            throw new Exception('Tabela: '.$this->table.' '.$e->getMessage());
        }
    }

    /**
     * Deleta registros da tabela com base em filtros aplicados.
     * 
     * @return bool Retorna true se a operação for bem-sucedida, false caso contrário.
     */
    protected function deleteByFilter():bool
    {
        try {
            $sql = "DELETE FROM " . $this->table;
            
            if ($this->filters) {
                $sql .= " WHERE " . implode(' ', array_map(function($filter, $i) {
                    return $i === 0 ? substr($filter, 4) : $filter;
                }, $this->filters, array_keys($this->filters)));
            }
            else{
                throw new Exception('Tabela: '.$this->table.' Filtros devem ser informados');
            }

            $this->executeSql($sql);
            
            return true;
        } catch (Exception $e) {
            throw new Exception('Tabela: '.$this->table.' '.$e->getMessage());
        }
        return false;
    }

    /**
     * Adiciona um filtro à consulta SQL.
     * 
     * @param string $column Nome da coluna.
     * @param string $condition Condição da consulta.
     * @param mixed $value Valor a ser comparado.
     * @param string $operator Operador lógico (AND ou OR).
     * @return db Retorna a instância atual da classe.
     */
    protected function addFilter(string $field,string $logicalOperator,mixed $value,string $operatorCondition = db::AND):DB
    {
        $operatorCondition = strtoupper(trim($operatorCondition));
        if (!in_array($operatorCondition, [self::AND, self::OR])) {
            throw new Exception('Tabela: '.$this->table.' Filtro invalido');
        }

        if(str_contains(strtolower($logicalOperator),"in")){
            if(!is_array($value))
                throw new Exception('Tabela: '.$this->table.' Para operadores (IN) o valor precisa ser um array'); 

            $inValue = "(";
            foreach ($value as $data) {
                $this->setBind($data);
                $inValue .= "?,";
            }

            $inValue = rtrim($inValue,",");
            $inValue .= ")";

            $filter = " " . $operatorCondition . " " . $field . " " . $logicalOperator . " " .$inValue;
            $this->filters[] = $filter;
        }
        else{
            $this->setBind($value);

            $filter = " " . $operatorCondition . " " . $field . " " . $logicalOperator . " ? ";
            $this->filters[] = $filter;
        }

        return $this;
    }

     /**
     * Adiciona uma ordenação à consulta SQL.
     * 
     * @param string $column Nome da coluna para ordenação.
     * @param string $order Tipo de ordenação (ASC ou DESC).
     * @return db Retorna a instância atual da classe.
     */
    protected function addOrder(string $column,string $order="DESC"):DB
    {
        $this->propertys[] = " ORDER by ".$column." ".$order;

        return $this;
    }

     /**
     * Adiciona um limite à consulta SQL.
     * 
     * @param int $limitIni Índice inicial do limite.
     * @param int $limitFim Índice final do limite (opcional).
     * @return $this Retorna a instância atual da classe.
     */
    protected function addLimit(int $limitIni,int $limitFim=0):DB
    {
        $this->setBind($limitIni,true);

        if ($limitFim){
            $this->propertys[] = " LIMIT ?,?";
            $this->setBind($limitFim,true);
        }else{
            $this->propertys[] = " LIMIT ?";
        }

        return $this;
    }


    /**
     * Adiciona um limite à consulta SQL.
     * 
     * @param int $limitIni Índice inicial do limite.
     * @param int $limitFim Índice final do limite (opcional).
     * @return $this Retorna a instância atual da classe.
     */
    protected function addOffset(int $offset):DB
    {
        $this->propertys[] = " OFFSET ?";

        $this->setBind($offset,true);

        return $this;
    }

     /**
     * Adiciona um agrupamento à consulta SQL.
     * 
     * @param string $columns Colunas para agrupamento.
     * @return $this Retorna a instância atual da classe.
     */
    protected function addGroup(...$columns):DB
    {
        $this->propertys[] = " GROUP by ".implode(",",$columns);

        return $this;
    }

    /**
     * Adiciona um JOIN à consulta SQL.
     * 
     * @param string $typeJoin Tipo de JOIN (INNER, LEFT, RIGHT).
     * @param string $table Tabela para JOIN.
     * @param string $columTable Condição da tabela atual.
     * @param string $columRelation Condição da tabela de junção.
     * @param string $logicalOperator operador do join.
     * @param string $alias da tabeça.
     * @return $this Retorna a instância atual da classe.
     */
    protected function addJoin(string $table,string $columnTable,string $columnRelation,String $typeJoin = "INNER",string $logicalOperator = '='):DB
    {
        $typeJoin = strtoupper(trim($typeJoin));
        if (!in_array($typeJoin, ["LEFT", "RIGHT", "INNER", "OUTER", "FULL OUTER", "LEFT OUTER", "RIGHT OUTER"])) {
            throw new Exception('Tabela: '.$this->table.' Filtro invalido');
        }

        $join = " " . $typeJoin . " JOIN " . $table . " ON " . $columnTable .$logicalOperator .$columnRelation . " ";
        $this->joins[] = $join;
        return $this;
    }

    /**
     * Seta $this->columns.
     * 
     * @return void.
    */
    private function getColumnTable():void
    {
        if(!$this->class || !class_exists($this->class)){
            $this->class = $this->getClassbyTableName($this->table);
        }

        if($this->class && class_exists($this->class) && method_exists($this->class,"table")){
            $this->columns = $this->class::table()->getColumnsName();
            return;
        }

        throw new Exception("Tabela: {$this->table} Erro ao Recuperar Colunas");
    }

    /**
     * Retorna o último ID de uma tabela.
     * 
     * @return mixed Retorna o último ID inserido na tabela ou null se nenhum ID foi inserido.
     */
    private function getlastIdBd():int
    {
        try{
            $sql = $this->pdo->prepare('SELECT ' . $this->columns[0] . ' FROM ' . $this->table . ' ORDER BY ' . $this->columns[0] . ' DESC LIMIT 1');
        
            $sql->execute();

            if ($sql->rowCount() > 0) {
                $rows = $sql->fetchAll(\PDO::FETCH_COLUMN, 0);
                return $rows[0];
            }
            
            return 0;
            
        }catch(Exception $e){
            throw new Exception("Tabela: {$this->table} ".$e->getMessage());
        }
    }

    /**
     * Retorna o string da classe da tabela informada.
     * 
     * @return string Retorna o string da classe da tabela informada.
    */
    private static function getClassbyTableName(string $tableName):string
    {
        $className = 'app\\db\\models';

        $tableNameModified = strtolower(str_replace("_"," ",$tableName));

        if(class_exists($className.$tableNameModified) && property_exists($className.str_replace(" ","",$tableNameModified), "table")){
            return $className.$tableName;
        }
        if(class_exists($className.ucfirst($tableNameModified)) && property_exists($className.str_replace(" ","",ucfirst($tableNameModified)), "table")){
            return $className.ucfirst($tableName);
        }
        if(class_exists($className.ucwords($tableNameModified)) && property_exists($className.str_replace(" ","",ucwords($tableNameModified)), "table")){
            return $className.ucwords($tableName);
        }

        $tableFiles = scandir(dirname(__DIR__).DIRECTORY_SEPARATOR."tables");
        
        foreach ($tableFiles as $tableFile) {
            $className .= str_replace(".php", "", $tableFile);
        
            if (class_exists($className) && property_exists($className, "table") && $className::table == $tableName) {
                return $className;
            }
        }

        return "";
    }

    /**
     * execulta uma instrução sql.
     * 
     * @return PDOStatement $this->pdo instancia do pdo apos o prepare.
    */
    private function executeSql(string $sql_instruction):PDOStatement
    {
        $sql = $this->pdo->prepare($sql_instruction);

        if ($this->debug)
            $sql->debugDumpParams();

        $lastcount = 0;
        if($this->valuesBind || $this->valuesBindProperty){
            foreach ($this->valuesBind as $key => $data) {
                $lastcount = $key;
                $sql->bindParam($key,$data[0],$data[1]);
            }
            foreach ($this->valuesBindProperty as $data) {
                $lastcount++;
                $sql->bindParam($lastcount,$data[0],$data[1]);
            }
        }
        
        $sql->execute();

        if ($this->debug)
            $sql->debugDumpParams();

        $this->clean();

        return $sql;
    }

    /**
     * Limpa as propriedades da classe após a execução de uma operação.
     */
    private function clean():void
    {
        $this->joins = [];
        $this->propertys = [];
        $this->filters = [];
        $this->valuesBind = [];
        $this->valuesBindProperty = [];
        $this->counterBind = 1;
    }

    /**
     * retorna o parametro para fazer o bindValue no PDO.
    */
    private function setBind($value,$property = false):void
    {
        if(is_int($value))
            $param = \PDO::PARAM_INT;
        elseif(is_bool($value))
            $param = \PDO::PARAM_BOOL;
        elseif(is_null($value))
            $param = \PDO::PARAM_NULL;
        else
            $param = \PDO::PARAM_STR;

        if($property){
            $this->valuesBindProperty[] = [
                $value,
                $param
            ];
        }
        else{
            $this->valuesBind[$this->counterBind] = [
                $value,
                $param
            ];
            $this->counterBind++;
        }
    }

}
?>

