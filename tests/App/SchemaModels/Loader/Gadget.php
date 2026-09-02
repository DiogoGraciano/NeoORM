<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Loader;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Depende de `Widget`, e o nome do arquivo vem ANTES dele em ordem alfabética.
 *
 * É o que prova que a ordem de criação sai do grafo de dependência, e não da ordem em que
 * o diretório foi lido — a suposição que fazia o sistema antigo funcionar por sorte.
 */
final class Gadget
{
    public const table = 'gadget';

    public static function table(): Table
    {
        return Table::make(self::table)
            ->columns([
                'id' => Col::id(),
                'widget' => Col::int()->notNull()->references(Widget::class),
            ]);
    }
}
