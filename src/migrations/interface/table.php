<?php
namespace diogodg\neoorm\migrations\interface;

/**
 * Classe base para criação do banco de dados.
 */
interface table
{
   
    function __construct(string $table,string $engine="InnoDB",string $collate="utf8mb4_general_ci",string $comment = "");

    public function isAutoIncrement():self;

    public function getAutoIncrement():bool;

    public function addIndex(string $name,array $columns):self;

    public function create();

    public function execute($recreate = false);

    public function hasForeignKey():bool;

    public function getForeignKeyTablesClasses():array;

    public function getTable():string;

    public function getColumnsName():array;
    
    public function exists():bool;
}