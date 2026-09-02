<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Recursive\Generated;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Uma classe com `table()` dentro de `Generated/`. O loader tem que NÃO a ver.
 *
 * `PATH_GENERATED` fica sob `PATH_MODEL` por padrão, então a varredura recursiva passaria
 * por ali. Hoje as classes geradas não têm `table()` e o filtro por capacidade as
 * descartaria de qualquer forma — este fixture existe justamente para o dia em que
 * tiverem: a exclusão precisa ser por diretório, não por sorte na forma do código gerado.
 */
final class Decoy
{
    public const table = 'rec_decoy';

    public static function table(): Table
    {
        return Table::make(self::table)->columns(['id' => Col::id()]);
    }
}
