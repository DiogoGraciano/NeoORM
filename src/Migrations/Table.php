<?php
namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Migrations\Driver\TableMysql;
use Diogodg\Neoorm\Migrations\Driver\TablePgsql;
use Diogodg\Neoorm\Migrations\Interface\Table as TableInterface;

/**
 * Classe base para criação do banco de dados.
 */
class Table implements TableInterface
{
    private object $table;

    function __construct(string $table,string $engine="InnoDB",string $collate="utf8mb4_general_ci",string $comment = "")
    {
        if($_ENV["DRIVER"] == "mysql"){
            $this->table = new tableMysql($table,$engine,$collate,$comment);
        }
        elseif($_ENV["DRIVER"] == "pgsql"){
            $this->table = new tablePgsql($table,$engine,$collate,$comment);
        }
    }

    public function addColumn(column $column)
    {
        $this->table->addColumn($column);
        return $this;
    }

    public function isAutoIncrement()
    {
        $this->table->isAutoIncrement();
        return $this;
    }

    public function getAutoIncrement():bool{
        return $this->table->getAutoIncrement();
    }

    public function addIndex(string $name,array $columns)
    {
        $this->table->addIndex($name,$columns);
        return $this;
    }

    public function create()
    {
        $this->table->create();
    }

    public function update()
    {
        $this->table->update();
    }

    public function hasForeignKey():bool
    {
        return $this->table->hasForeignKey();
    }
    
    public function getForeignKeyTables():array
    {
        return $this->table->getForeignKeyTables();
    }

    public function addForeingKey(string $foreingTable,string $column = "id",string $foreingColumn = "id",string $onDelete = "RESTRICT")
    {
        return $this->table->addForeingKey($foreingTable,$column,$foreingColumn,$onDelete);
    }

    public function addForeingKeytoTable(){
        return $this->table->addForeingKeytoTable();
    }

    public function getTable():string
    {
        return $this->table->getTable();
    }

    public function getColumnsName():array
    {
        return $this->table->getColumnsName();
    }
    
    public function exists():bool
    {
        return $this->table->exists();
    }
}