<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Remove um índice.
 *
 * Carrega a tabela mesmo que o PostgreSQL não precise dela (`DROP INDEX nome`,
 * qualificado por schema): no MySQL um índice só existe dentro de uma tabela, e
 * a sintaxe é `ALTER TABLE t DROP INDEX n`.
 */
final readonly class DropIndex implements SchemaOperation
{
    public string $table;

    public string $name;

    public function __construct(string $table, string $name)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
        $this->name = IdentifierValidator::normalize($name, 'Nome de índice');
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
        return "remove o índice '{$this->name}' de '{$this->table}'";
    }
}
