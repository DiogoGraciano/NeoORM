<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Renomeia uma coluna preservando os dados.
 *
 * Como `RenameTable`, só existe por decisão explícita. Um `DropColumn` mais um
 * `AddColumn` na mesma tabela é indistinguível de um rename olhando apenas os
 * dois snapshots, e adivinhar errado apaga uma coluna de produção. Por isso a
 * forma ambígua faz `migration:generate` não escrever nada e sair com erro,
 * a menos que venha `--rename` ou `--allow-destructive`.
 */
final readonly class RenameColumn implements SchemaOperation
{
    public string $table;

    public string $from;

    public string $to;

    public function __construct(string $table, string $from, string $to)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
        $this->from = IdentifierValidator::normalize($from, 'Nome de coluna de origem');
        $this->to = IdentifierValidator::normalize($to, 'Nome de coluna de destino');
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
        return "renomeia a coluna '{$this->table}.{$this->from}' para '{$this->to}'";
    }
}
