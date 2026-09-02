<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

final readonly class DropTable implements SchemaOperation
{
    public string $table;

    public function __construct(string $table)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
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
        return "remove a tabela '{$this->table}'";
    }
}
