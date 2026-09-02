<?php

declare(strict_types=1);

namespace Tests\App\DropModels;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Root com APENAS `Box`: contra `ConvergenceModels`, a diferença é `zz_item` removida.
 *
 * Uma remoção pura, sem nada sendo criado no lugar — o que importa porque uma remoção
 * ACOMPANHADA de uma criação cai na guarda de ambiguidade (pode ser rename) e nunca chega
 * à guarda de descarte de dados. Para testar uma, é preciso não disparar a outra.
 */
final class Box
{
    public const table = 'zz_box';

    public static function table(): Table
    {
        return Table::make(self::table, comment: 'Caixas')
            ->columns([
                'id' => Col::id()->comment('ID da caixa'),
                'name' => Col::varchar(120)->notNull()->comment('Nome'),
                'code' => Col::varchar(40)->notNull()->unique(),
            ]);
    }
}
