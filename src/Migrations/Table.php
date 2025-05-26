<?php
namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Config;
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
        if(Config::getDriver() == "mysql"){
            $this->table = new tableMysql($table,$engine,$collate,$comment);
        }
        elseif(Config::getDriver() == "pgsql"){
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

    public function addConstraint(string $name, string $type, array $columns)
    {
        $this->table->addConstraint($name, $type, $columns);
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

    public function addForeignKey(string $foreignTable,string $column = "id",string $foreignColumn = "id",string $onDelete = "RESTRICT")
    {
        return $this->table->addForeignKey($foreignTable,$column,$foreignColumn,$onDelete);
    }

    public function addForeignKeytoTable(){
        return $this->table->addForeignKeytoTable();
    }

    public function getTable():string
    {
        return $this->table->getTable();
    }

    public function getColumns():array
    {
        return $this->table->getColumns();
    }
    
    public function exists():bool
    {
        return $this->table->exists();
    }

    public function getEngine():?string
    {
        return $this->table->getEngine();
    }

    public function getCollation():?string      
    {   
        return $this->table->getCollation();
    }

    public function getComment():?string
    {
        return $this->table->getComment();
    }

    public function getColumnsSql():array
    {
        return $this->table->getColumnsSql();
    }

    public function getIndexes():array
    {
        return $this->table->getIndexes();
    }

    public function getConstraints():array
    {
        return $this->table->getConstraints();
    }
}