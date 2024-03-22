<?php
namespace app\db;

//exemplo de uso com herança
class table1 extends db{
    public function __construct(){
        parent::__construct("table1");
    }

    public function get($value="",$column="id"){
        if ($value)
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        else
            $retorno = $this->getObject();

        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno;
    }

    public function getAll(){
        return $this->selectAll();
    }

    public function delete($value,$column="id"){
        return $this->addFilter($column,"=",$value)->deleteByFilter();
    }
}
class table2 extends db{
    public function __construct(){
        parent::__construct("table2");
    }

    public function get($value="",$column="id"){
        if ($value)
            $retorno = $this->addFilter($column,"=",$value)->selectAll();
        else
            $retorno = $this->getObject();

        if (is_array($retorno) && count($retorno) == 1)
            return $retorno[0];

        return $retorno;
    }

    public function getAll(){
        return $this->selectAll();
    }

    public function delete($value,$column="id"){
        return $this->addFilter($column,"=",$value)->deleteByFilter();
    }
}
