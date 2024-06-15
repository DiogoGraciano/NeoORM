<?php
namespace app\db;
use Exception;
use stdClass;


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
    private $table;

    /**
     * Objeto da tabela.
     *
     * @var array
    */
    private $object = [];

    /**
     * array de colunas da tabela.
     *
     * @var array
    */
    private $columns;

    /**
     * array com os joins informados.
     *
     * @var array
    */
    private $joins =[];

    /**
     * debug está ativo?.
     *
     * @var array
    */
    private $debug = false;

    /**
     * array com os propriedades informadas.
     *
     * @var array
    */
    private $propertys =[];

    /**
     * array com os filtros informadas.
     *
     * @var array
    */
    private $filters =[];

    /**
     * ultimo id inserido ou atualizado na tabela.
     *
     * @var mixed
    */
    private $lastid;

    /**
     * valores do bindparam.
     *
     * @var mixed
    */
    private $valuesBind = [];

    /**
     * contador de parametros do bindparam.
     *
     * @var mixed
    */
    private $counterBind = 1;

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
    private $pdo;

    /**
     * Construtor da classe.
     * 
     * @param string $table Nome da tabela do banco de dados.
     */
    function __construct($table)
    {
        // Inicia a Conexão
        if (!$this->pdo)
            $this->pdo = ConnectionDb::getConnection();

        // Seta Tabela
        $this->table = $table;
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
            E_USER_NOTICE);
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

    /**
     * Retorna o último ID de uma tabela.
     * 
     * @return mixed Retorna o último ID inserido na tabela ou null se nenhum ID foi inserido.
     */
    private function getlastIdBd():int
    {
        $sql = $this->pdo->prepare('SELECT ' . $this->columns[0] . ' FROM ' . $this->table . ' ORDER BY ' . $this->columns[0] . ' DESC LIMIT 1');
       
        $sql->execute();

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN, 0);
            return $rows[0];
        }

        throw new Exception('Tabela: '.$this->table.' tabela não encontrada');
    }

    /**
     * Retorna o último ID inserido ou atualizado na tabela.
     * 
     * @return mixed Retorna o último ID inserido na tabela ou null se nenhum ID foi inserido.
     */
    public function getLastID():int
    {
        return $this->lastid;
    }


    /**
     * Set Debug.
     * 
     * @return void Retorna o último ID inserido na tabela ou null se nenhum ID foi inserido.
     */
    public function setDebug():DB
    {
        $this->debug = true;

        return $this;
    }

    /**
     * Retorna o objeto da tabela.
     * 
     * @return object Retorna o objeto da tabela.
     */
    public function getArrayData():array
    {
        return $this->object;
    }

    /**
     * Seta as o object com as colunas da tabela vazias.
     * 
     * @return DB Retorna a instacia da classe.
     */
    protected function setObjectNull():DB
    {
        $this->object = [];

        $this->getColumnTable();

        foreach ($this->columns as $column){
            $this->object[$column] = null;
        }

        return $this;
    }

    /**
     * Retorna o objeto da tabela.
     * 
     * @return array Retorna um array das colunas.
    */
    public function getColumns():array
    {
        $this->getColumnTable();

        return $this->columns;
    }

    //Pega as colunas da tabela e tranforma em Objeto
    private function getColumnTable():void
    {
        if(!$this->columns){
            $sql = $this->pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = "'.DBNAME.'" AND TABLE_NAME = "' . $this->table . '" ORDER BY CASE WHEN COLUMN_KEY = "PRI" THEN 1 ELSE 2 END,COLUMN_NAME;');
        
            $sql->execute();

            $rows = [];

            if ($sql->rowCount() > 0) {
                $this->columns = $sql->fetchAll(\PDO::FETCH_COLUMN, 0);
            }else{
                throw new Exception('Tabela: '.$this->table.' tabela não encontrada');
            }
        }
    }

    /**
     * Seleciona registros com base em uma instrução SQL.
     * 
     * @param string $sql_instruction A instrução SQL a ser executada.
     * @return array Retorna um array contendo os registros selecionados.
     */
    public function selectInstruction(string $sql_instruction):array
    {
        try {
            $sql = $this->pdo->prepare($sql_instruction);
            foreach ($this->valuesBind as $key => $data) {
                $sql->bindParam($key,$data[0],$data[1]);
            }
            
            $sql->execute();

            if ($this->debug)
                $sql->debugDumpParams();

            $rows = [];

            if ($sql->rowCount() > 0) {
                $rows = $sql->fetchAll(\PDO::FETCH_CLASS|\PDO::FETCH_PROPS_LATE,get_class($this));
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
    public function selectAll():array
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
        $this->clean();
        return $object;
    }

    /**
     * Seleciona registros com base em colunas específicas.
     * 
     * @param string ...$columns Colunas a serem selecionadas.
     * @return array Retorna um array contendo os registros selecionados.
     */
    public function selectColumns(...$columns):array
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
        $this->clean();
        return $object;
    }


    /**
     * Salva ou Atualiza um registro na tabela.
     * 
     * @param object $values Objeto contendo os valores a serem salvos.
     * @return bool|int Retorna id do ultimo registro inserido se a operação foi bem-sucedida, caso contrário, retorna false.
    */
    public function store():bool
    {
        try {
            // Gera Objeto da tabela
            $this->getColumnTable();

            foreach ($this->columns as $columns){
                $columnsDb[$columns] = true;
            }

            if ($this->object && !isset($this->object[0])) {
                $objectFilter = array_intersect_key($this->object, $columnsDb);

                if (!isset($values[$this->columns[0]]) || !$values[$this->columns[0]]) {
                    // Incrementando o ID
                    $objectFilter[$this->columns[0]] = $this->getlastIdBd() + 1;

                    // Montando a instrução SQL
                    $sql_instruction = "INSERT INTO {$this->table} (";
                    $keysBD = implode(",", array_keys($objectFilter));
                    $valuesBD = "";

                    // Preparando os valores para bind e montando a parte dos valores na instrução SQL
                    foreach ($objectFilter as $key => $data) {
                        $valuesBD .= "?,";
                        $this->valuesBind[$this->counterBind] = [
                            $data,
                            is_string($data) ? \PDO::PARAM_STR : (is_int($data) || is_float($data) ? \PDO::PARAM_INT : \PDO::PARAM_NULL)
                        ];
                        $this->counterBind++;
                    }
                    $keysBD = rtrim($keysBD, ",");
                    $sql_instruction .= $keysBD . ") VALUES (";
                    $valuesBD = rtrim($valuesBD, ",");
                    $sql_instruction .= $valuesBD . ");";
                } elseif (isset($objectFilter[$this->columns[0]]) && $objectFilter[$this->columns[0]]) {
                    $sql_instruction = "UPDATE {$this->table} SET ";
                    foreach ($objectFilter as $key => $data) {
                        if ($key === $this->columns[0]) // Ignorando a primeira coluna (geralmente a chave primária)
                            continue;

                        $sql_instruction .= "{$key}=?,";
                        $this->valuesBind[$this->counterBind] = [
                            $data,
                            is_string($data) ? \PDO::PARAM_STR : (is_int($data) || is_float($data) ? \PDO::PARAM_INT : \PDO::PARAM_NULL)
                        ];
                        $this->counterBind++;
                    }
                    $sql_instruction = rtrim($sql_instruction, ",") . " WHERE ";

                    // Adicionando cláusula WHERE
                    if ($this->filters) {
                        $sql_instruction .= implode(" AND ", $this->filters);
                    } else {
                        $sql_instruction .= "{$this->columns[0]}=?";
                        $this->valuesBind[$this->counterBind] = [
                            $objectFilter[$this->columns[0]],
                            \PDO::PARAM_INT
                        ];
                        $this->counterBind++;
                    }
                }

                $sql = $this->pdo->prepare($sql_instruction);
                foreach ($this->valuesBind as $key => $data) {
                    $sql->bindParam($key,$data[0],$data[1]);
                }

                $sql->execute();

                if ($this->debug)
                    $sql->debugDumpParams();

                $this->lastid = $objectFilter[$this->columns[0]];
                $this->clean();
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
    public function storeMutiPrimary():bool{
        try {
            // Gera Objeto da tabela
            $this->getColumnTable();

            foreach ($this->columns as $columns){
                $columnsDb[$columns] = true;
            }

            if ($this->object && !isset($this->object[0])) {
                $objectFilter = array_intersect_key($this->object, $columnsDb);

                $objectFilter = array_intersect_key($this->object, $this->columns);
                $sql_instruction = "INSERT INTO {$this->table} (";
                $keysBD = implode(",", array_keys($this->object));
                $valuesBD = "";

                // Preparando os valores para bind e montando a parte dos valores na instrução SQL
                foreach ($objectFilter as $key => $data) {
                    $valuesBD .= "?,";
                    $this->valuesBind[$this->counterBind] = [
                        $data,
                        is_string($data) ? \PDO::PARAM_STR : (is_int($data) || is_float($data) ? \PDO::PARAM_INT : \PDO::PARAM_NULL)
                    ];
                    $this->counterBind++;
                }
                $keysBD = rtrim($keysBD, ",");
                $sql_instruction .= $keysBD . ") VALUES (";
                $valuesBD = rtrim($valuesBD, ",");
                $sql_instruction .= $valuesBD . ");";
                $sql = $this->pdo->prepare($sql_instruction);
                foreach ($this->valuesBind as $key => $data) {
                    $sql->bindParam($key,$data[0],$data[1]);
                }
                $sql->execute();

                if ($this->debug)
                    $sql->debugDumpParams();

                $this->clean();
                return true;
            }
        } catch (\Exception $e) {
            throw new Exception('Tabela: '.$this->table.' '.$e->getMessage());
        }
        throw new Exception('Tabela: '.$this->table." Objeto não está setado");
    }

    /**
     * Deleta um registro da tabela com base em um ID.
     * 
     * @param int $id O ID do registro a ser deletado.
     * @return bool Retorna true se a operação foi bem-sucedida, caso contrário, retorna false.
    */
    public function delete(int $id):bool
    {
        try {
            $this->getColumnTable();

            if ($id){
                $sql = $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE " . $this->columns[0] . "=?");
                $sql->bindParam(1,$id,\PDO::PARAM_INT);
                $sql->execute();

                if ($this->debug)
                    $sql->debugDumpParams();

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
    public function deleteByFilter():bool
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

            $stmt = $this->pdo->prepare($sql);
            foreach ($this->valuesBind as $key => $data) {
                $stmt->bindParam($key, $data[0], $data[1]);
            }

            $stmt->execute();

            if ($this->debug)
                $stmt->debugDumpParams();

            $this->clean();
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
    public function addFilter($field,$logicalOperator,$value,$operatorCondition = Db::AND):DB
    {
        $operatorCondition = strtoupper(trim($operatorCondition));
        if (!in_array($operatorCondition, [self::AND, self::OR])) {
            $this->error[] = "Filtro inválido";
            Logger::error("Filtro inválido");
            return $this;
        }

        $this->valuesBind[$this->counterBind] = [
            $value,
            is_string($value) ? \PDO::PARAM_STR : (is_int($value) || is_float($value) ? \PDO::PARAM_INT : \PDO::PARAM_NULL)
        ];
        $this->counterBind++;

        $filter = " " . $operatorCondition . " " . $field . " " . $logicalOperator . " ? ";
        $this->filters[] = $filter;
        return $this;
    }

     /**
     * Adiciona uma ordenação à consulta SQL.
     * 
     * @param string $column Nome da coluna para ordenação.
     * @param string $order Tipo de ordenação (ASC ou DESC).
     * @return db Retorna a instância atual da classe.
     */
    public function addOrder(string $column,string $order="DESC"):DB
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
    public function addLimit(int $limitIni,int $limitFim=0):DB
    {
        if ($limitFim){
            $this->propertys[] = " LIMIT {$limitIni},{$limitFim}";
        }else
            $this->propertys[] = " LIMIT {$limitIni}";

        return $this;
    }

     /**
     * Adiciona um agrupamento à consulta SQL.
     * 
     * @param string $columns Colunas para agrupamento.
     * @return $this Retorna a instância atual da classe.
     */
    public function addGroup(string $columns):DB
    {
        $this->propertys[] = " GROUP by ".$columns;

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
    public function addJoin($typeJoin, $table, $columTable, $columRelation, $logicalOperator = '=', $alias = null):DB
    {
        $typeJoin = strtoupper(trim($typeJoin));
        if (!in_array($typeJoin, ["LEFT", "RIGHT", "INNER", "OUTER", "FULL OUTER", "LEFT OUTER", "RIGHT OUTER"])) {
            $this->error[] = "JOIN inválido";
            Logger::error("JOIN inválido");
            return $this;
        }

        $join = " " . $typeJoin . " JOIN " . $table . ($alias ? " $alias" : "") . " ON " . $columTable . $logicalOperator . $columRelation . " ";
        $this->joins[] = $join;
        return $this;
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
        $this->counterBind = 1;
    }

}
?>

