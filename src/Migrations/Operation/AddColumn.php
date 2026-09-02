<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

final readonly class AddColumn implements SchemaOperation
{
    public string $table;

    public function __construct(string $table, public ColumnDefinition $column)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
    }

    public function tableName(): string
    {
        return $this->table;
    }

    /**
     * Adicionar coluna não apaga nada, mas `NOT NULL` sem default falha na hora
     * se a tabela já tiver linhas — e falhar no meio de uma migração é um custo
     * alto o bastante para ser anunciado antes.
     */
    public function isDestructive(): bool
    {
        return $this->column->notNull
            && $this->column->default->isNone()
            && !$this->column->autoIncrement;
    }

    public function discardsData(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return "adiciona a coluna '{$this->table}.{$this->column->name}' "
            . "({$this->column->type->signature()})";
    }
}
