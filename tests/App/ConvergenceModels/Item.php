<?php

declare(strict_types=1);

namespace Tests\App\ConvergenceModels;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Depende de `Box`, e vem DEPOIS dele no alfabeto — de propósito o contrário do que seria
 * conveniente, para a ordem de criação vir do grafo e não do nome do arquivo.
 */
final class Item
{
    public const table = 'zz_item';

    public static function table(): Table
    {
        return Table::make(self::table, comment: 'Itens')
            ->columns([
                'id' => Col::id(),
                'box' => Col::int()->notNull()->references(Box::class, onDelete: 'CASCADE')->comment('Caixa do item'),
                'quantity' => Col::int()->notNull()->default(0),
            ]);
    }
}
