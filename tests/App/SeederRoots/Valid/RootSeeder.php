<?php

declare(strict_types=1);

namespace Tests\App\SeederRoots\Valid;

use Diogodg\Neoorm\Migrations\Seed\Seeder;
use Diogodg\Neoorm\Query\Executor;
use Tests\App\SchemaModels\Recursive\Root;

/**
 * Semeia a tabela que as outras duas dependem — e, de propósito, é o ÚLTIMO em ordem
 * alfabética de arquivo. Se a ordem viesse do diretório, ele rodaria depois das
 * dependentes e a foreign key recusaria a inserção.
 */
final class RootSeeder extends Seeder
{
    /** @var list<string> tabelas semeadas, na ordem em que rodaram */
    public static array $ran = [];

    public static function table(): string
    {
        return Root::table;
    }

    public function run(Executor $db): void
    {
        self::$ran[] = self::table();
    }
}
