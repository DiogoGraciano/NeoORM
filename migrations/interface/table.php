<?php
namespace app\db\migrations\interface;

/**
 * Classe base para criação do banco de dados.
 */
interface table
{
   
    function __construct(string $table,string $engine="InnoDB",string $collate="utf8mb4_general_ci",string $comment = "");

    public function isAutoIncrement();

    public function addIndex(string $name,array $columns);

    public function create();

    public function execute($recreate = false);

    public function hasForeignKey();

    public function getForeignKeyTablesClasses();

    public function getTable();

    public function getColumnsName();
    
    public function exists();
}