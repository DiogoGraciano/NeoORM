<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

final readonly class DropUniqueConstraint implements SchemaOperation
{
    public string $table;

    public string $name;

    public function __construct(string $table, string $name)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
        $this->name = IdentifierValidator::normalize($name, 'Nome de restrição de unicidade');
    }

    public function tableName(): string
    {
        return $this->table;
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function discardsData(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return "remove a restrição de unicidade '{$this->name}' de '{$this->table}'";
    }
}
