<?php
namespace diogodg\neoorm\migrations\interface;

use diogodg\neoorm\migrations\table;

/**
 * Interface de Classe base para criação do banco de dados.
 */
interface column
{
    public function __construct(string $name,string $type,string|int|null $size = null);

    public function isNotNull();

    public function isPrimary();

    public function isUnique();

    public function isForeingKey(table $foreingTable,string $foreingColumn = "id");

    public function setDefaut(string|int|float|null $value = null,bool $is_constant = false);

    public function getColumn();

    public function setComment($comment);
}