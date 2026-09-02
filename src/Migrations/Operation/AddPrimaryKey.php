<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;

/**
 * Adiciona a chave primária a uma tabela que já existe.
 *
 * Nunca é emitida junto de um `CreateTable` — lá a PK vai inline, porque o MySQL
 * não aceita coluna auto incremento sem chave no mesmo statement.
 */
final readonly class AddPrimaryKey implements SchemaOperation
{
    public string $table;

    public function __construct(string $table, public PrimaryKeyDefinition $primaryKey)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
    }

    public function tableName(): string
    {
        return $this->table;
    }

    /**
     * Falha se as colunas tiverem NULL ou valores repetidos. Não apaga nada, mas
     * pode interromper a migração no meio.
     */
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
        return "define a chave primária de '{$this->table}' em ("
            . implode(', ', $this->primaryKey->columns) . ')';
    }
}
