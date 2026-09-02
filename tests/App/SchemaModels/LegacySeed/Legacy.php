<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\LegacySeed;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Um model 1.x/2.0-beta que ainda carrega `seed()`.
 *
 * O método saiu de `Model`, e o modo de falha natural de um método removido é o pior que
 * existe: ele simplesmente deixa de ser chamado, os dados não aparecem, e nada diz por
 * quê. Este fixture prova que `db:seed` para.
 */
final class Legacy extends Model
{
    public const table = 'legacy_seed';

    public static function table(): Table
    {
        return Table::make(self::table)->columns(['id' => Col::id()]);
    }

    public static function seed(): void
    {
        // O corpo não importa: o que importa é o método existir.
    }
}
