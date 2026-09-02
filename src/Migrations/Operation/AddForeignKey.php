<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\ForeignKeyDefinition;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Adiciona uma foreign key.
 *
 * Sempre sai na fase P8, depois de toda tabela e toda coluna existirem. É o que
 * elimina de uma vez o segundo passe de foreign keys do sistema antigo, a
 * dependência da ordem alfabética dos arquivos de model, e o `ADD CONSTRAINT`
 * sem idempotência que fazia a segunda execução do migrate estourar com
 * "constraint já existe".
 */
final readonly class AddForeignKey implements SchemaOperation
{
    public string $table;

    public function __construct(string $table, public ForeignKeyDefinition $foreignKey)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
    }

    public function tableName(): string
    {
        return $this->table;
    }

    /** Falha se alguma linha existente violar a referência. */
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
        return "adiciona a foreign key '{$this->foreignKey->name}' em '{$this->table}' ("
            . implode(', ', $this->foreignKey->columns) . ') -> '
            . $this->foreignKey->referencedTable
            . ' (' . implode(', ', $this->foreignKey->referencedColumns) . ')';
    }
}
