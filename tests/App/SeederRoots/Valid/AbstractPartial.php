<?php

declare(strict_types=1);

namespace Tests\App\SeederRoots\Valid;

use Diogodg\Neoorm\Migrations\Seed\Seeder;

/**
 * Uma base abstrata entre `Seeder` e os seeders de verdade.
 *
 * Um projeto real tem dessas — um lugar para o helper compartilhado de "já tem linha?".
 * O loader não pode tratá-la como seeder: ela não nomeia tabela nenhuma.
 */
abstract class AbstractPartial extends Seeder
{
    public static function table(): string
    {
        return 'nunca_deveria_aparecer';
    }
}
