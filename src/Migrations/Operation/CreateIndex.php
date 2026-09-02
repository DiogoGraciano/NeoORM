<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\IndexDefinition;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Cria um índice — inclusive de uma única coluna.
 *
 * O sistema antigo recusava índice com menos de duas colunas, e além disso nunca
 * chegava a emitir `CREATE INDEX` num schema já existente, porque o extrator
 * devolvia uma lista onde o comparador esperava um mapa por nome: os índices
 * eram gravados com `index_name` igual a "0", "1", e a comparação seguinte nunca
 * reconhecia nenhum deles.
 */
final readonly class CreateIndex implements SchemaOperation
{
    public string $table;

    public function __construct(string $table, public IndexDefinition $index)
    {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');
    }

    public function tableName(): string
    {
        return $this->table;
    }

    public function isDestructive(): bool
    {
        // Índice único falha se já houver duplicatas; o comum não falha nunca.
        return $this->index->unique;
    }

    public function discardsData(): bool
    {
        return false;
    }

    public function describe(): string
    {
        $kind = $this->index->unique ? 'índice único' : 'índice';

        return "cria o {$kind} '{$this->index->name}' em '{$this->table}' ("
            . implode(', ', $this->index->columns) . ')';
    }
}
