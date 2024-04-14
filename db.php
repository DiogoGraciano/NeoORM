<?php
namespace app\db;
use app\db\configDB;

/**
 * Classe base para interação com o banco de dados.
 */
class Db extends ConfigDB
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
     * @var object
    */
    private $object;

    /**
     * array de colunas da tabela.
     *
     * @var array
    */
    private $columns;

    /**
     * array com os erros ocorridos.
     *
     * @var array
    */
    private $error = [];

    /**
     * array com os joins informados.
     *
     * @var array
    */
    private $joins =[];

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
     * Construtor da classe.
     * 
     * @param string $table Nome da tabela do banco de dados.
     */
    function __construct($table)
    {
        // Inicia a Conexão
        if (!$this->pdo)
            $this->getConnection();

        // Seta Tabela
        $this->table = $table;

        // Gera Objeto da tabela
        $this->object = $this->getObjectTable();

        // Transforma as colunas da tabela em uma array
        $this->columns = array_keys(get_object_vars($this->object));      
    }

    /**
     * Inicia uma transação no banco de dados.
     * 
     * @return bool Retorna true se a transação foi iniciada com sucesso, caso contrário, retorna false.
     */
    public function transaction(){
        if ($this->pdo->beginTransaction())
            return True;
       
        $this->error[] = "Erro: Não foi possível iniciar a transação";
    }

    /**
     * Confirma uma transação no banco de dados.
     * 
     * @return bool Retorna true se a transação foi confirmada com sucesso, caso contrário, retorna false.
     */
    public function commit(){
        if ($this->pdo->commit())
            return True;
         
        $this->error[] = "Erro: Não foi possível finalizar a transação";
    }

    /**
     * Desfaz uma transação no banco de dados.
     * 
     * @return bool Retorna true se a transação foi desfeita com sucesso, caso contrário, retorna false.
     */
    public function rollback(){
        if ($this->pdo->rollback())
            return True;
        
        $this->error[] = "Erro: Não foi possível desfazer a transação";
    }

    /**
     * Retorna o último ID de uma tabela.
     * 
     * @return mixed Retorna o último ID inserido na tabela ou null se nenhum ID foi inserido.
     */
    private function getlastIdBd()
    {
        $sql = $this->pdo->prepare('SELECT ' . $this->columns[0] . ' FROM ' . $this->table . ' ORDER BY ' . $this->columns[0] . ' DESC LIMIT 1');
       
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN, 0);
        }

        if ($rows) {
            return $rows[0];
        } 

        $this->error[] = "Erro: Tabela não encontrada";
    }

    /**
     * Retorna o último ID inserido ou atualizado na tabela.
     * 
     * @return mixed Retorna o último ID inserido na tabela ou null se nenhum ID foi inserido.
     */
    public function getLastID(){
        return $this->lastid;
    }

     /**
     * Retorna os erros gerados durante a execução das operações.
     * 
     * @return array Retorna um array contendo os erros.
     */
    public function getError()
    {
        return $this->error;
    }

    /**
     * Retorna o objeto da tabela.
     * 
     * @return object Retorna o objeto da tabela.
     */
    public function getObject()
    {
        return $this->object;
    }

    /**
     * Retorna o objeto da tabela.
     * 
     * @return array Retorna um array das colunas.
    */
    public function getColumns()
    {
        return $this->columns;
    }

    //Pega as colunas da tabela e tranforma em Objeto
    private function getObjectTable()
    {
        $sql = $this->pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_NAME = "' . $this->table . '" ORDER BY CASE WHEN COLUMN_KEY = "PRI" THEN 1 ELSE 2 END,COLUMN_NAME;');
       
        $sql->execute();

        $rows = [];

        if ($sql->rowCount() > 0) {
            $rows = $sql->fetchAll(\PDO::FETCH_COLUMN, 0);
        }

        if ($rows) {
            $object = new \stdClass;
            foreach ($rows as $row) {
                $object->$row = null;
            }

            return $object;
        } 

        $this->error[] = "Erro: Tabela não encontrada";
        return false;
    }

    /**
     * Seleciona registros com base em uma instrução SQL.
     * 
     * @param string $sql_instruction A instrução SQL a ser executada.
     * @return array Retorna um array contendo os registros selecionados.
     */
    public function selectInstruction(string $sql_instruction)
    {
        try {
            $sql = $this->pdo->prepare($sql_instruction);
            foreach ($this->valuesBind as $key => $data) {
                $sql->bindParam($key,$data[0],$data[1]);
            }
            
            $sql->execute();

            $rows = [];

            if ($sql->rowCount() > 0) {
                $rows = $sql->fetchAll(\PDO::FETCH_CLASS, 'stdClass');
            }
        
            return $rows;
        } catch (\Exception $e) {
            $this->error[] = 'Tabela: '.$this->table.' Erro: ' .  $e->getMessage();
        }
    }
    
    /**
     * Seleciona um único registro com base em um ID.
     * 
     * @param int $id O ID do registro a ser selecionado.
     * @return object|null Retorna o registro selecionado ou null se nenhum registro foi encontrado.
     */
    public function selectOne($id)
    {
        $this->valuesBind[1] = [$id,\PDO::PARAM_INT];
        $object = $this->selectInstruction("SELECT * FROM " . $this->table . " WHERE " . $this->columns[0] . "=?");
        
        return $object;
    }

    /**
     * Seleciona todos os registros da tabela.
     * 
     * @return array Retorna um array contendo todos os registros da tabela.
     */
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
    public function selectColumns(...$columns)
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
        
        $object = $this->selectInstruction($sql);
        $this->clean();
        return $object;
    }

    /**
     * Seleciona registros com base em colunas e valores específicos.
     * 
     * @param array $columns Colunas a serem usadas na seleção.
     * @param array $values Valores a serem usados na seleção.
     * @param bool $all Flag para selecionar todos os registros ou não.
     * @return array Retorna um array contendo os registros selecionados.
     */
    public function selectByValues(Array $columns,array $values,$all=false){
        if (count($columns) == count($values)){
            $conditions = [];
            $sql = "SELECT ";
            $i = 0;
            foreach ($columns as $column){
                if (!$all)
                    $sql .= $column.",";
                if (is_string($values[$i]) && $values[$i] != "null")
                    $conditions[] = $column." = '".$values[$i]."' and ";
                elseif (is_int($values[$i]) || is_float($values[$i]) || $values[$i] == "null")
                    $conditions[] = $column." = ".$values[$i]." and ";  
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

            $object = $this->selectInstruction($sql);
            $this->clean();
            return $object;
        } 
        
        $this->error[] = "Erro: Quantidade de colunas diferente do total de Valores";
    }

    /**
     * Salva ou Atualiza um registro na tabela.
     * 
     * @param object $values Objeto contendo os valores a serem salvos.
     * @return bool|int Retorna id do ultimo registro inserido se a operação foi bem-sucedida, caso contrário, retorna false.
    */
    public function store(\stdClass $values)
    {
        try {
            if ($values) {
                $values = (array)$values;
                if (!isset($values[$this->columns[0]]) || !$values[$this->columns[0]]) {
                    $values[$this->columns[0]] = $this->getlastIdBd() + 1;
                    $sql_instruction = "INSERT INTO " . $this->table . "(";
                    $keysBD = "";
                    $valuesBD = "";
                    foreach ($values as $key => $data) {
                        $keysBD .= $key . ",";
                        $valuesBD .= "?,";
                        if (is_string($data))
                            $this->valuesBind[$this->counterBind] = [$data,\PDO::PARAM_STR];  
                        elseif (is_int($data) || is_float($data))
                            $this->valuesBind[$this->counterBind] = [$data,\PDO::PARAM_INT]; 
                        else
                            $this->valuesBind[$this->counterBind] = [null,\PDO::PARAM_NULL]; 
                        $this->counterBind++;
                    }
                    $keysBD = substr($keysBD, 0, -1);
                    $sql_instruction .= $keysBD;
                    $sql_instruction .= ") VALUES (";
                    $valuesBD = substr($valuesBD, 0, -1);
                    $sql_instruction .= $valuesBD;
                    $sql_instruction .= ");";
                } elseif ($values[$this->columns[0]] && $values[$this->columns[0]]) {
                    $sql_instruction = "UPDATE " . $this->table . " SET ";
                    foreach ($values as $key => $data) {
                        if ($key == $this->columns[0])
                            continue;
                        $sql_instruction .= $key . '=?,';
                        if (is_string($data))
                            $this->valuesBind[$this->counterBind] = [$data,\PDO::PARAM_STR];  
                        elseif (is_int($data) || is_float($data))
                            $this->valuesBind[$this->counterBind] = [$data,\PDO::PARAM_INT]; 
                        else
                            $this->valuesBind[$this->counterBind] = [null,\PDO::PARAM_NULL]; 
                        $this->counterBind++;
                    }
                    $sql_instruction = substr($sql_instruction, 0, -1);
                    $sql_instruction .= " WHERE ";
                    $i = 1;
                    if ($this->filters){
                        foreach ($this->filters as $filter){
                            if ($i == 1){
                                $sql_instruction .= substr($filter,4);
                                $i++;
                            }else{
                                $sql_instruction .= $filter;
                            }
                        }
                    }else 
                        $sql_instruction .= $this->columns[0] . "=" . $values[$this->columns[0]];
                }
                $sql = $this->pdo->prepare($sql_instruction);
                foreach ($this->valuesBind as $key => $data) {
                    $sql->bindParam($key,$data[0],$data[1]);
                }
                $sql->execute();
                $this->lastid = $values[$this->columns[0]];
                $this->clean();
                return true;
            }
            $this->error[] = "Erro: Valores não informados";
        } catch (\Exception $e) {
            $this->error[] = 'Tabela: '.$this->table.' Erro: ' .  $e->getMessage();
        }
    }    
    /**
     * Salva um registro na tabela com múltiplas chaves primárias.
     * 
     * @param object $values Objeto contendo os valores a serem salvos.
     * @return bool Retorna true se a operação foi bem-sucedida, caso contrário, retorna false.
    */
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
                    $keysBD .= $key . ",";
                    $valuesBD .= "?,";
                    if (is_string($data))
                        $valuesBind[$i] = [$data,\PDO::PARAM_STR];  
                    elseif (is_int($data) || is_float($data))
                        $valuesBind[$i] = [$data,\PDO::PARAM_INT]; 
                    else
                        $valuesBind[$i] = [null,\PDO::PARAM_NULL]; 
                    $i++;
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
                $this->clean();
                return true;
            }
        } catch (\Exception $e) {
            $this->error[] = 'Tabela: '.$this->table.' Erro: '.$e->getMessage();
        }
    }

    /**
     * Deleta um registro da tabela com base em um ID.
     * 
     * @param int $id O ID do registro a ser deletado.
     * @return bool Retorna true se a operação foi bem-sucedida, caso contrário, retorna false.
    */
    public function delete(int $id)
    {
        try {
            if ($id){
                $sql = $this->pdo->prepare("DELETE FROM " . $this->table . " WHERE " . $this->columns[0] . "=?");
                $sql->bindParam(1,$id,\PDO::PARAM_INT);
                $sql->execute();
                return true;
            }
            $this->error[] = 'Tabela: '.$this->table.' Erro: ID Invalido';
        } catch (\Exception $e) {
            $this->error[] = 'Tabela: '.$this->table.' Erro: ' .  $e->getMessage();
        }
        return false;
    }

     /**
     * Deleta registros da tabela com base em um filtro.
     * 
     * @return bool Retorna true se a operação foi bem-sucedida, caso contrário, retorna false.
     */
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
                foreach ($this->valuesBind as $key => $data) {
                    $sql->bindParam($key,$data[0],$data[1]);
                }
                $sql->execute();
                $this->clean();
                return true;
            }
            $this->error[] = 'Tabela: '.$this->table.' Erro: Obrigatorio Uso de filtro';
            return false;

        } catch (\Exception $e) {
            $this->error[] = 'Tabela: '.$this->table.' Erro: ' .  $e->getMessage();
        }
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
    public function addFilter(string $column,string $condition,$value,string $operator=DB::AND){
        
        if (is_string($value))
            $this->valuesBind[$this->counterBind] = [$value,\PDO::PARAM_STR];
        elseif (is_int($value) || is_float($value))
            $this->valuesBind[$this->counterBind] = [$value,\PDO::PARAM_INT];
        else 
            $this->valuesBind[$this->counterBind] = [Null,\PDO::PARAM_NULL];

        $this->counterBind++;

        $this->filters[] = " ".$operator." ".$column." ".$condition."? ";
        
        return $this;
    }

     /**
     * Adiciona uma ordenação à consulta SQL.
     * 
     * @param string $column Nome da coluna para ordenação.
     * @param string $order Tipo de ordenação (ASC ou DESC).
     * @return db Retorna a instância atual da classe.
     */
    public function addOrder(string $column,string $order="DESC"){
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
    public function addLimit(int $limitIni,int $limitFim=0){
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
    public function addGroup(string $columns){
        $this->propertys[] = " GROUP by ".$columns;

        return $this;
    }

    /**
     * Adiciona um JOIN à consulta SQL.
     * 
     * @param string $type Tipo de JOIN (INNER, LEFT, RIGHT).
     * @param string $table Tabela para JOIN.
     * @param string $condition_from Condição da tabela atual.
     * @param string $condition_to Condição da tabela de junção.
     * @return $this Retorna a instância atual da classe.
     */
    public function addJoin(string $type,string $table,string $condition_from,string $condition_to){
        $this->joins[] = " ".$type." JOIN ".$table." on ".$condition_from." = ".$condition_to;

        return $this;
    }

    /**
     * Limpa as propriedades da classe após a execução de uma operação.
     */
    private function clean(){
        $this->joins = [];
        $this->propertys = [];
        $this->filters = [];
        $this->valuesBind = [];
        $this->counterBind = 1;
    }

}
?>

