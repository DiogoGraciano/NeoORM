<?php

declare(strict_types=1);

namespace Tests\App\ConvergenceModelsV2;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * A versão 2 do `Item`: uma coluna nova e um índice de UMA coluna sobre ela.
 *
 * O índice de coluna única é o fixture central do ciclo incremental, porque era duplamente
 * impossível no sistema antigo: `TableMysql` recusava explicitamente `count($columns) < 2`,
 * e o extrator devolvia lista onde o comparador esperava mapa — o que gravava o nome do
 * índice como `"0"` e fazia `CREATE INDEX` nunca ser emitido em `update()`.
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
                'label' => Col::varchar(60)->comment('Etiqueta'),
            ])
            ->index('zz_item_label_index', ['label']);
    }
}
