<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\CheckConstraintDefinition;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

final readonly class AddCheckConstraint implements SchemaOperation
{
    public string $table;

    public function __construct(string $table, public CheckConstraintDefinition $check)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
    }

    public function tableName(): string
    {
        return $this->table;
    }

    /** Falha se alguma linha existente não satisfizer a expressão. */
    public function isDestructive(): bool
    {
        return true;
    }

    public function discardsData(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return "adiciona a restrição CHECK '{$this->check->name}' em '{$this->table}'";
    }
}
