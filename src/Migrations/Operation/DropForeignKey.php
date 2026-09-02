<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Remove uma foreign key.
 *
 * Sai na fase P0, antes de tudo — inclusive as FKs que não estão sendo removidas
 * de fato, mas que tocam uma tabela ou coluna que fases posteriores vão mexer. O
 * differ as recoloca em P8. Tirar a referência da frente e devolvê-la no fim é o
 * que permite renomear, trocar tipo e remover coluna sem esbarrar em restrição.
 */
final readonly class DropForeignKey implements SchemaOperation
{
    public string $table;

    public string $name;

    public function __construct(string $table, string $name)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
        $this->name = IdentifierValidator::normalize($name, 'Nome de foreign key');
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
        return "remove a foreign key '{$this->name}' de '{$this->table}'";
    }
}
