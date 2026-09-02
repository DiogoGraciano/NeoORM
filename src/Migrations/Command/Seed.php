<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Seed\SeedRunner;

/**
 * Popula o banco a partir de `PATH_SEEDS`, DEPOIS de o schema convergir.
 *
 * Separado de `migration:up` de propósito. No sistema antigo o seed rodava no MEIO do loop de
 * criação de tabelas — antes de as foreign keys existirem — e em ordem alfabética de nome de
 * arquivo, o que fazia `Country` ser semeado antes de `State` por acidente do alfabeto. Aqui
 * a ordem sai do grafo de dependência e o schema já está pronto.
 *
 * Seed é DML pura, então a transação funciona nos dois bancos — é a única parte do sistema em
 * que envolver tudo numa transação significa algo, e é a única coisa que está dentro de uma.
 */
final class Seed
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    /**
     * @param list<string>|null $onlyTables
     * @return list<string> as tabelas semeadas, na ordem em que foram
     */
    public function execute(?array $onlyTables = null): array
    {
        $schema = $this->context->models->loadValidated($this->context->dialect->name());

        $this->rejectModelsWithSeedMethod();

        $seeded = (new SeedRunner(
            $schema,
            $this->context->seeders,
            $this->context->output,
        ))->run($onlyTables);

        if ($seeded === []) {
            $this->context->output->write(
                'Nenhum seeder para rodar. Eles moram em ' . $this->context->seeders->path() . '.',
            );
        } else {
            $this->context->output->success('Semeadas: ' . implode(', ', $seeded));
        }

        return $seeded;
    }

    /**
     * Um model que ainda declare `seed()` para o comando, em vez de ser ignorado.
     *
     * O método saiu de `Model`, e o modo de falha natural de um método removido é o pior
     * que existe: ele simplesmente deixa de ser chamado, os dados não aparecem, e nada no
     * console diz por quê. A checagem custa um `method_exists` por model e converte isso
     * numa lista do que migrar.
     */
    private function rejectModelsWithSeedMethod(): void
    {
        $legacy = [];

        foreach ($this->context->models->modelClasses() as $class) {
            if (method_exists($class, 'seed')) {
                $legacy[] = $class;
            }
        }

        if ($legacy === []) {
            return;
        }

        $namespace = $this->context->seeders->namespace();
        $instructions = [];

        foreach ($legacy as $class) {
            $short = ($position = strrpos($class, '\\')) === false
                ? $class
                : substr($class, $position + 1);

            $instructions[] = "{$class}::seed() -> {$namespace}\\{$short}Seeder";
        }

        throw new MigrationException(
            "Model::seed() não existe mais — os dados iniciais agora são classes Seeder em "
            . $this->context->seeders->path() . ".\n"
            . "Mova o corpo de cada seed() para um arquivo próprio e apague o método:\n  - "
            . implode("\n  - ", $instructions)
            . "\n\nO Seeder estende Diogodg\\Neoorm\\Migrations\\Seed\\Seeder, declara "
            . 'static table(): string e recebe o executor por parâmetro em run(Executor $db).',
        );
    }
}
