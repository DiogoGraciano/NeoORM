<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Registry;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Schema\TableDefinition;

/**
 * Devolve o IR pronto, sem builder no meio.
 *
 * É o caminho que permite trocar o builder sem trocar o registry — e o motivo de o
 * registry fazer duck typing em vez de exigir `Migrations\Table`: assim a dependência
 * continua indo de `Migrations` para `Schema`, nunca ao contrário.
 */
final class ReturnsDefinition
{
    public static function table(): TableDefinition
    {
        return Table::make('returns_definition')
            ->columns(['id' => Col::id()])
            ->build();
    }
}
