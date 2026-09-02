<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Seed;

use Diogodg\Neoorm\Migrations\Diff\DependencyGraph;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Query\Database;
use Diogodg\Neoorm\Query\Executor;
use Diogodg\Neoorm\Query\Tx;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Support\Output\NullOutput;
use Diogodg\Neoorm\Support\Output\Output;
use Throwable;

/**
 * Roda os seeders depois de o schema convergir, em ordem de dependência.
 *
 * Quatro coisas diferem do comportamento antigo, e cada uma corrige um problema que só
 * não aparecia por sorte.
 *
 * Onde: os dados moravam num `seed()` estático dentro do model. Agora são classes em
 * `PATH_SEEDS`, descobertas pelo `SeederLoader` — schema e dado deixam de dividir arquivo.
 *
 * Quando: antes os seeds rodavam DENTRO do loop de criação de tabelas, antes de as
 * foreign keys existirem. Um seed que inserisse uma referência inválida passava, e o
 * banco ficava com dado que a foreign key recém-criada deveria ter proibido.
 *
 * Em que ordem: antes era a ordem alfabética dos arquivos de model. `Country` vir antes
 * de `State` funcionava por acidente do alfabeto; qualquer par cuja dependência não
 * seguisse a ordem alfabética falhava. Agora a ordem vem do grafo de foreign keys.
 *
 * Dentro de quê: uma transação de verdade, E o executor dela é o que cada seeder recebe.
 * Seed é DML pura, então a transação funciona nos dois bancos.
 */
final class SeedRunner
{
    public function __construct(
        private readonly SchemaDefinition $schema,
        private readonly SeederLoader $loader,
        private readonly Output $output = new NullOutput(),
        private readonly ?Executor $executor = null,
    ) {
    }

    /**
     * @param list<string>|null $onlyTables
     * @return list<string> tabelas semeadas, na ordem em que rodaram
     */
    public function run(?array $onlyTables = null): array
    {
        $seeders = $this->loader->seedersFor($this->schema);

        if ($seeders === []) {
            return [];
        }

        $order = DependencyGraph::fromSchema($this->schema)->sort();
        $wanted = $onlyTables === null ? null : array_fill_keys(array_map('strtolower', $onlyTables), true);

        $pending = [];

        foreach ($order as $table) {
            if ($wanted !== null && !isset($wanted[$table])) {
                continue;
            }

            if (isset($seeders[$table])) {
                $pending[$table] = $seeders[$table];
            }
        }

        if ($pending === []) {
            return [];
        }

        $db = $this->executor ?? Database::fromConfig();

        // Uma transação para todos os seeders: se um falhar no meio, nenhum dado parcial
        // fica. Sem DDL aqui dentro, o commit implícito do MySQL não tem como interferir.
        // O `Tx` é repassado a cada `run()`, então o seeder escreve necessariamente na
        // transação que decide se o trabalho dele sobrevive.
        try {
            return $db->transaction(function (Tx $tx) use ($pending): array {
                $seeded = [];

                foreach ($pending as $table => $class) {
                    (new $class())->run($tx);
                    $seeded[] = $table;
                    $this->output->write("  seed de {$table}");
                }

                return $seeded;
            });
        } catch (Throwable $e) {
            throw new MigrationException(
                'Falha ao rodar os seeders: ' . $e->getMessage() . '. Nenhum dado foi mantido.',
                previous: $e,
            );
        }
    }
}
