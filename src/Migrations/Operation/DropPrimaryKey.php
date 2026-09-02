<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

final readonly class DropPrimaryKey implements SchemaOperation
{
    public string $table;

    public string $name;

    public function __construct(
        string $table,
        string $name,
        /**
         * A coluna auto incremento da PK que está saindo, se houver, na forma em
         * que ela existe HOJE no banco.
         *
         * O MySQL recusa `DROP PRIMARY KEY` enquanto a coluna for
         * `AUTO_INCREMENT` ("incorrect table definition; there can be only one
         * auto column and it must be defined as a key"), então o dialeto precisa
         * emitir um `MODIFY COLUMN` que remove o auto incremento antes. O
         * PostgreSQL não se importa e ignora este campo.
         */
        public ?ColumnDefinition $autoIncrementColumn = null,
    ) {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
        $this->name = IdentifierValidator::normalize($name, 'Nome de chave primária');
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
        return "remove a chave primária de '{$this->table}'";
    }
}
