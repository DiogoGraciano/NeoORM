<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;
use Diogodg\Neoorm\Schema\TableOptions;

/**
 * Engine e collation padrão da tabela — conceitos que só o MySQL tem.
 *
 * `null` em qualquer campo significa "default do servidor, não compare". É o
 * que impede o diff fantasma que o sistema antigo produzia em toda execução:
 * ele assumia `utf8mb4_general_ci` como default do builder e o comparava contra
 * um MySQL 8 cujo default é `utf8mb4_0900_ai_ci`, gerando um ALTER que nunca
 * fazia a comparação seguinte concordar.
 *
 * No PostgreSQL a compilação desta operação devolve lista vazia.
 */
final readonly class SetTableOptions implements SchemaOperation
{
    public string $table;

    public function __construct(string $table, public TableOptions $options)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
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
        $parts = [];

        if ($this->options->engine !== null) {
            $parts[] = "engine {$this->options->engine}";
        }

        if ($this->options->collation !== null) {
            $parts[] = "collation {$this->options->collation}";
        }

        if ($parts === []) {
            return "restaura as opções padrão da tabela '{$this->table}'";
        }

        return "define " . implode(' e ', $parts) . " na tabela '{$this->table}'";
    }
}
