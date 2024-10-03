<?php

namespace app\db\abstract;

use app\db\db;

abstract class model extends db{

    private static array $lastCount = [];

    public function __construct($table,$class){
        parent::__construct($table,$class);
    }

    public function get($value="",string $column="id",int $limit = 1):array|object{
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

    public function remove(){
        return $this->delete($this->getArrayData()[$this->getColumns()[0]]);
    }
}

?>