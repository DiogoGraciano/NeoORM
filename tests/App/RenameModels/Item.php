<?php

declare(strict_types=1);

namespace Tests\App\RenameModels;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * O mesmo `Item` de `ConvergenceModels`, com `quantity` chamada `amount`.
 *
 * É a forma AMBÍGUA: contra o schema anterior isto produz um `DropColumn` e um `AddColumn` na
 * mesma tabela, e não há como o differ saber, olhando só os dois estados, se a intenção foi
 * renomear ou remover e criar. A diferença é invisível no diff e total no banco — renomear
 * preserva os dados, remover e criar deixa a coluna nova vazia.
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
                'amount' => Col::int()->notNull()->default(0),
            ]);
    }
}
