<?php
namespace Diogodg\Neoorm\Migrations\Interface;

use Diogodg\Neoorm\Migrations\Table;

/**
 * Interface de Classe base para criação do banco de dados.
 */
interface Column
{
    public function __construct(string $name,string $type,string|int|null $size = null);

    public function isNotNull();

    public function isPrimary();

    public function isUnique();

    public function setDefaut(string|int|float|null $value = null,bool $is_constant = false);

    public function getColumn();

    public function setComment($comment);
}