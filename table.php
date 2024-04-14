<?php
namespace app\db;

/**
 * Classe para interação com a tabela 'table1' no banco de dados.
*/
class table1 extends db{
    public function __construct(){
        parent::__construct("table1");
    }

    public function get($value="",$column="id"){
        $retorno = [];
        
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
/**
 * Classe para interação com a tabela 'table2' no banco de dados.
*/
class table2 extends db{
    public function __construct(){
        parent::__construct("table2");
    }

    public function get($value="",$column="id"){
        $retorno = [];

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
