<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Registry;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * O caminho normal: `table()` devolve o builder e o registry chama `build()`.
 *
 * Este diretório não é um root de models — nenhum teste o entrega ao
 * `ModelSchemaLoader`. São as pontas do contrato que o `SchemaRegistry` aceita, uma por
 * arquivo porque o autoload é PSR-4.
 */
final class ReturnsBuilder
{
    public static function table(): Table
    {
        return Table::make('returns_builder')
            ->columns(['id' => Col::id()]);
    }
}
