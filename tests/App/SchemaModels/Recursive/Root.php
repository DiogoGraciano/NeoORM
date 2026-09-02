<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Recursive;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Root de models organizado por subpasta, para exercitar a varredura recursiva.
 *
 * O que ele prova junto com `Billing\Invoice` e `Billing\Deep\Line`: subpasta vira
 * segmento de namespace pela regra do PSR-4, em qualquer profundidade. Antes a varredura
 * era plana, e o efeito não era erro e sim silêncio — um model numa subpasta não existia
 * para o schema, então a migração seguinte propunha dropar a tabela dele.
 */
final class Root
{
    public const table = 'rec_root';

    public static function table(): Table
    {
        return Table::make(self::table)
            ->columns([
                'id' => Col::id(),
                'name' => Col::varchar(60)->notNull(),
            ]);
    }
}
