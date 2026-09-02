<?php

declare(strict_types=1);

namespace Tests\App\SeederRoots\Unknown;

use Diogodg\Neoorm\Migrations\Seed\Seeder;
use Diogodg\Neoorm\Query\Executor;

/**
 * Aponta para uma tabela que não existe no schema — o typo silencioso.
 *
 * Sem a conferência, isto passa despercebido para sempre: o runner percorre as tabelas
 * do schema, então um `table()` errado simplesmente nunca é alcançado, e a única
 * evidência é a linha que não apareceu no banco.
 */
final class GhostSeeder extends Seeder
{
    public static function table(): string
    {
        return 'tabela_que_nao_existe';
    }

    public function run(Executor $db): void
    {
    }
}
