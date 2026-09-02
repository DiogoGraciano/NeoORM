<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

final readonly class DropColumn implements SchemaOperation
{
    public string $table;

    public string $column;

    public function __construct(string $table, string $column)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
        $this->column = IdentifierValidator::normalize($column, 'Nome de coluna');
    }

    public function tableName(): string
    {
        return $this->table;
    }

    public function isDestructive(): bool
    {
        return true;
    }

    public function discardsData(): bool
    {
        return true;
    }

    public function describe(): string
    {
        return "remove a coluna '{$this->table}.{$this->column}'";
    }
}
