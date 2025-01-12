<?php
namespace diogodg\neoorm\migrations;

use diogodg\neoorm\migrations\driver\tableMysql;
use diogodg\neoorm\migrations\driver\tablePgsql;
use diogodg\neoorm\migrations\interface\table as tableInterface;

/**
 * Classe base para criação do banco de dados.
 */
class table implements tableInterface
{
    private object $table;

    function __construct(string $table,string $engine="InnoDB",string $collate="utf8mb4_general_ci",string $comment = "")
    {
        $env = parse_ini_file('.env');

        if($env["DRIVER"] == "mysql"){
            $this->table = new tableMysql($table,$engine,$collate,$comment);
        }
        elseif($env["DRIVER"] == "pgsql"){
            $this->table = new tablePgsql($table,$engine,$collate,$comment);
        }
    }

    public function addColumn(column $column)
    {
        $this->table->addColumn($column);
        return $this;
    }

    public function isAutoIncrement():self
    {
        $this->table->isAutoIncrement();
        return $this;
    }

    public function getAutoIncrement():bool{
        return $this->table->getAutoIncrement();
    }

    public function addIndex(string $name,array $columns):self
    {
        $this->table->addIndex($name,$columns);
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

    public function hasForeignKey():bool
    {
        return $this->table->hasForeignKey();
    }
    
    public function getForeignKeyTablesClasses():array
    {
        return $this->table->getForeignKeyTablesClasses();
    }

    public function getTable():string
    {
        return $this->table->getTable();
    }

    public function getColumnsName():array
    {
        return $this->table->getColumnsName();
    }
    
    public function exists()
    {
        return $this->table->exists();
    }
}