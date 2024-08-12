<?php

namespace app\db\abstract;

use app\db\db;

abstract class model extends db{

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
}

?>