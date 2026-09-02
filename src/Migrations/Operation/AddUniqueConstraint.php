<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;
use Diogodg\Neoorm\Schema\UniqueConstraintDefinition;

final readonly class AddUniqueConstraint implements SchemaOperation
{
    public string $table;

    public function __construct(string $table, public UniqueConstraintDefinition $constraint)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
    }

    public function tableName(): string
    {
        return $this->table;
    }

    /** Falha se já houver duplicatas. */
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
        return "adiciona a restrição de unicidade '{$this->constraint->name}' em '{$this->table}' ("
            . implode(', ', $this->constraint->columns) . ')';
    }
}
