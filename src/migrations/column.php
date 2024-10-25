<?php
namespace diogodg\neoorm\migrations;

use diogodg\neoorm\migrations\driver\columnMysql;
use diogodg\neoorm\migrations\driver\columnPgsql;
use diogodg\neoorm\migrations\interface\column as columnInterface;

/**
 * Classe base para criação do banco de dados.
 */
class column implements columnInterface
{
    private object $column;

    public function __construct(string $name,string $type,string|int|null $size = null)
    {
        if(DRIVER == "mysql"){
            $this->column = new columnMysql($name,$type,$size);
        }
        elseif(DRIVER == "pgsql"){
            $this->column = new columnPgsql($name,$type,$size);
        }
    }

    public function isNotNull(){
        $this->column->isNotNull();
        return $this;
    }

    public function isPrimary(){
        $this->column->isPrimary();
        return $this;
    }

    public function isUnique(){
        $this->column->isUnique();
        return $this;
    }

    public function isForeingKey(table $foreingTable,string $foreingColumn = "id"){
        $this->column->isForeingKey($foreingTable,$foreingColumn);
        return $this;
    }

    public function setDefaut(string|int|float|null $value = null,bool $is_constant = false){
        $this->column->setDefaut($value,$is_constant);
        return $this;
    }

    public function getColumn(){
        return $this->column->getColumn();
    }

    public function setComment($comment){
        $this->column->setComment($comment);
        return $this;
    }
}