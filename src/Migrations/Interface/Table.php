<?php
namespace Diogodg\Neoorm\Migrations\Interface;

/**
 * Classe base para criação do banco de dados.
 */
interface Table
{
    function __construct(string $table,string $engine="InnoDB",string $collate="utf8mb4_general_ci",string $comment = "");

    public function isAutoIncrement();

    public function getAutoIncrement():bool;

    public function addIndex(string $name,array $columns);

    public function addConstraint(string $name,string $type,array $columns);

    public function create();

    public function update();

    public function hasForeignKey():bool;

    public function addForeignKey(string $foreignTable,string $foreignColumn = "id",string $column = "id",string $onDelete = "RESTRICT");

    public function addForeignKeytoTable();

    public function getForeignKeyTables():array;

    public function getTable():string;

    public function getColumns():array;
    
    public function exists():bool;

}