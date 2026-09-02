<?php

declare(strict_types=1);

namespace Tests\App\SeederRoots\Integration;

use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use Diogodg\Neoorm\Migrations\Seed\Seeder;
use Diogodg\Neoorm\Query\Executor;
use PDO;
use Tests\App\ConvergenceModels\Item;

/**
 * `zz_item` tem foreign key para `zz_box`, e o nome do arquivo vem DEPOIS no alfabeto.
 *
 * Se a ordem dos seeders viesse do diretório em vez do grafo, esta inserção violaria a
 * restrição — e o banco, ao contrário de um duplo, recusa de verdade.
 */
final class ItemSeeder extends Seeder
{
    /** Deixe `true` para provar o rollback contra o banco. */
    public static bool $explode = false;

    public static function table(): string
    {
        return Item::table;
    }

    public function run(Executor $db): void
    {
        $quoted = $db->dialect()->quoteIdentifier(self::table());

        $db->run(new CompiledQuery(
            "INSERT INTO {$quoted} (id, box, quantity) VALUES (:id, :box, :quantity)",
            [
                'id' => [1, PDO::PARAM_INT],
                'box' => [1, PDO::PARAM_INT],
                'quantity' => [7, PDO::PARAM_INT],
            ],
        ));

        if (self::$explode) {
            throw new \RuntimeException('seed quebrou depois de escrever');
        }
    }
}
