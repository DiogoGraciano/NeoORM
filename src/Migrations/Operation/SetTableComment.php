<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

final readonly class SetTableComment implements SchemaOperation
{
    public string $table;

    public ?string $comment;

    public function __construct(string $table, ?string $comment)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
        $this->comment = ($comment === null || $comment === '') ? null : $comment;
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
        if ($this->comment === null) {
            return "remove o comentário da tabela '{$this->table}'";
        }

        return "define o comentário da tabela '{$this->table}'";
    }
}
