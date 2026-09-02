<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Registry;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Conta as chamadas para provar que o cache do registry existe de verdade.
 *
 * O cache não é conveniência: `Db::__construct` chama `Model::table()` em TODA
 * instanciação de model, então sem ele montar o IR de uma tabela aconteceria uma vez por
 * objeto criado, no caminho mais quente do ORM.
 */
final class CountsCalls
{
    public static int $calls = 0;

    public static function table(): Table
    {
        self::$calls++;

        return Table::make('counts_calls')
            ->columns(['id' => Col::id()]);
    }
}
