<?php

declare(strict_types=1);

namespace Tests\App\SeederRoots\Integration;

use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use Diogodg\Neoorm\Migrations\Seed\Seeder;
use Diogodg\Neoorm\Query\Executor;
use PDO;
use Tests\App\ConvergenceModels\Box;

/**
 * Escreve DE VERDADE, contra o banco.
 *
 * SQL cru em vez dos DTOs de insert porque os models de convergência não têm
 * `Generated/` — o que se afirma aqui é o caminho do comando `db:seed`, não a camada de
 * consulta, que já tem suíte própria.
 */
final class BoxSeeder extends Seeder
{
    public static function table(): string
    {
        return Box::table;
    }

    public function run(Executor $db): void
    {
        $quoted = $db->dialect()->quoteIdentifier(self::table());

        $db->run(new CompiledQuery(
            "INSERT INTO {$quoted} (id, name, code) VALUES (:id, :name, :code)",
            [
                'id' => [1, PDO::PARAM_INT],
                'name' => ['Caixa semeada', PDO::PARAM_STR],
                'code' => ['SEED-1', PDO::PARAM_STR],
            ],
        ));
    }
}
