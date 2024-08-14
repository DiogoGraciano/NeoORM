<?php
namespace app\db\migrations;

use app\db\migrations\driver\tableMysql;
use app\db\migrations\driver\tablePgsql;
use app\db\migrations\interface\table as tableInterface;

/**
 * Classe base para criação do banco de dados.
 */
class table implements tableInterface
{
    private object $table;

    function __construct(string $table,string $engine="InnoDB",string $collate="utf8mb4_general_ci",string $comment = "")
    {
        if(DRIVER == "mysql"){
            $this->table = new tableMysql($table,$engine,$collate,$comment);
        }
        elseif(DRIVER == "pgsql"){
            $this->table = new tablePgsql($table,$engine,$collate,$comment);
        }
    }

    public function addColumn(column $column)
    {
        $this->table->addColumn($column);
        return $this;
    }

    public function isAutoIncrement(){
        $this->table->isAutoIncrement();
        return $this;
    }

    public function addIndex(string $name,array $columns){
        $this->table->isAutoIncrement($name,$columns);
        return $this;
    }

    public function create()
    {
        $this->table->create();
    }

    public function execute($recreate = false)
    {
        $this->table->execute($recreate);
    }

    public function hasForeignKey()
    {
        return $this->table->hasForeignKey();
    }
    
    public function getForeignKeyTablesClasses()
    {
        return $this->table->getForeignKeyTablesClasses();
    }

    public function getTable()
    {
        return $this->table->getTable();
    }

    public function getColumnsName()
    {
        return $this->table->getColumnsName();
    }
    
    public function exists()
    {
        return $this->table->exists();
    }
}