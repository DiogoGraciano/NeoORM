<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Loader;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Não estende `Model` de propósito.
 *
 * O loader só exige um `table()` estático, e herdar `Model` traria o construtor do ORM
 * para dentro de um teste que não pode tocar banco. O que se afirma aqui é o contrato do
 * loader, não a hierarquia dos models de verdade.
 */
final class Widget
{
    public const table = 'widget';

    public static function table(): Table
    {
        return Table::make(self::table, comment: 'Widgets')
            ->columns([
                'id' => Col::id(),
                'name' => Col::varchar(80)->notNull(),
            ]);
    }
}
