<?php

namespace Diogodg\Neoorm\Abstract;

use Diogodg\Neoorm\Db;

abstract class Model extends Db{

    public const table = "";

    private static array $lastCount = [];   

    private int $modalTotalRegisters = 0;

    private int $modalCurrentPage = 1;

    private int $modalLimit = 15;

    public function __construct($table,$class){
        parent::__construct($table,$class);
    }

    public function get(mixed $value="",string $column="id",int $limit = 1):array|object
    {
        $retorno = false;

        if($limit){
            $this->addLimit($limit);
        }

        if ($value && in_array($column,$this->getColumns()))
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        
        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno?:$this->setObjectNull();
    }

    public function getAll():array{
        return $this->selectAll();
    }

    protected static function setLastCount(db $db):void
    {
        $method = debug_backtrace(!DEBUG_BACKTRACE_PROVIDE_OBJECT|DEBUG_BACKTRACE_IGNORE_ARGS,2)[1]['function'];
        $class = get_called_class();
        self::$lastCount[$class."::".$method] = $db->count();
    }

    public static function getLastCount(string $method):int
    {
        $class = get_called_class();
        return isset(self::$lastCount[$class."::".$method]) ? self::$lastCount[$class."::".$method] : 0;
    }

    public function remove():bool
    {
        return $this->delete($this->getArrayData()[$this->getColumns()[0]]);
    }

    public function paginate(int $page = 1,int $limit = 15):Model
    {
        $this->modalTotalRegisters = $this->count();

        $page = $page <= 0 ? 1 : $page;
        $this->modalCurrentPage = $page;
        $limit = $limit <= 0 ? 15 : $limit;
        $this->modalLimit = $limit;

        $this->addLimit($limit);
        $this->addOffset($this->getOffset());
        
        return $this;
    }

    public function getPreviousPage():int
    {
        return $this->modalCurrentPage > 1 ? $this->modalCurrentPage - 1 : 1;
    }

    public function getNextPage():int
    {
        return $this->modalCurrentPage < $this->getLastPage() ? $this->modalCurrentPage + 1 : $this->getLastPage();
    }

    public function getLastPage():int
    {
        return ceil($this->modalTotalRegisters/$this->modalLimit);
    }

    public function getCurrentPage():int
    {
        return $this->modalCurrentPage;
    }

    public function getLimit():int
    {
        return $this->modalLimit;
    }

    public function getOffset():int
    {
        return ($this->modalCurrentPage-1)*$this->modalLimit;
    }
    
}

?>