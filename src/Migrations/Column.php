<?php
namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Migrations\Driver\ColumnMysql;
use Diogodg\Neoorm\Migrations\Driver\ColumnPgsql;
use Diogodg\Neoorm\Migrations\Interface\Column as ColumnInterface;

/**
 * Classe base para criação do banco de dados.
 */
class Column implements ColumnInterface
{
    private object $column;

    public function __construct(string $name,string $type,string|int|null $size = null)
    {
        if($_ENV["DRIVER"] == "mysql"){
            $this->column = new columnMysql($name,$type,$size);
        }
        elseif($_ENV["DRIVER"] == "pgsql"){
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

    public function isForeingKey(string $foreingTable,string $foreingColumn = "id"){
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