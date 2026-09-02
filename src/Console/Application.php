<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Console;

use Diogodg\Neoorm\Codegen\FileSystemWriter;
use Diogodg\Neoorm\Codegen\Generator;
use Diogodg\Neoorm\Codegen\RefAnnotationChecker;
use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Migrations\Command\Check;
use Diogodg\Neoorm\Migrations\Command\Generate;
use Diogodg\Neoorm\Migrations\Command\MigrationContext;
use Diogodg\Neoorm\Migrations\Command\Pull;
use Diogodg\Neoorm\Migrations\Command\Push;
use Diogodg\Neoorm\Migrations\Command\Reset;
use Diogodg\Neoorm\Migrations\Command\Seed;
use Diogodg\Neoorm\Migrations\Command\Status;
use Diogodg\Neoorm\Migrations\Command\Up;
use Diogodg\Neoorm\Migrations\Diff\MapRenameResolver;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Support\Output\Output;
use Throwable;

/**
 * O despachante do `bin/neoorm`.
 *
 * Uma classe, e não um script, porque os comandos de migração existem desde a reescrita
 * e **nunca tiveram entrada de linha de comando**: quem quisesse gerar ou aplicar uma
 * migração precisava instanciar `MigrationContext` no código da aplicação. Aqui eles
 * viram comandos de verdade, e o parsing fica testável sem processo.
 *
 * Nada de formatação vive nos comandos: eles devolvem DTOs (`GenerateResult`,
 * `CheckResult`, `RunnerStatus`), e é esta classe que os transforma em texto. É a mesma
 * separação que faz um comando poder rodar num job ou numa rota administrativa sem
 * responder num terminal — que o `Migrate` antigo, com seus seis `echo`, não permitia.
 *
 * Exceção não vaza como stack trace: vira mensagem e código de saída. Um comando de
 * migração que falha precisa dizer O QUE falhou, não onde no código.
 */
final readonly class Application
{
    /**
     * Os nomes são os MESMOS do `php neof` do NeoFramework-Core, e de propósito: quem
     * usa o framework roda `neof migration:up`, quem usa a biblioteca sozinha roda
     * `vendor/bin/neoorm migration:up`, e a documentação serve para os dois.
     */
    private const COMMANDS = [
        'generate:types',
        'migration:generate',
        'migration:up',
        'migration:status',
        'db:check',
        'db:push',
        'db:pull',
        'db:seed',
        'db:reset',
    ];

    public function __construct(private Output $output)
    {
    }

    /**
     * @param list<string> $argv
     * @return int o código de saída
     */
    public function run(array $argv): int
    {
        $arguments = Arguments::parse($argv);
        $command = $arguments->command;

        if ($command === null) {
            $this->output->write($this->usage());

            return 1;
        }

        if (in_array($command, ['help', '--help', '-h'], true)) {
            $this->output->write($this->usage());

            return 0;
        }

        if (!in_array($command, self::COMMANDS, true)) {
            $this->output->error("Comando desconhecido: {$command}. Use `neoorm help`.");

            return 1;
        }

        try {
            return $this->dispatch($command, $arguments);
        } catch (Throwable $e) {
            $this->output->error($e->getMessage());

            return 1;
        }
    }

    private function dispatch(string $command, Arguments $arguments): int
    {
        return match ($command) {
            'generate:types' => $this->generateTypes($arguments),
            'migration:generate' => $this->migrationGenerate($arguments),
            'migration:up' => $this->migrationUp($arguments),
            'migration:status' => $this->migrationStatus($arguments),
            'db:check' => $this->databaseCheck($arguments),
            'db:push' => $this->databasePush($arguments),
            'db:pull' => $this->databasePull($arguments),
            'db:seed' => $this->databaseSeed($arguments),
            'db:reset' => $this->databaseReset($arguments),
            default => 1,
        };
    }

    /**
     * Gera os DTOs tipados. **Sem banco** — é o que o torna viável como hook de
     * pre-commit e como gate de CI.
     */
    private function generateTypes(Arguments $arguments): int
    {
        $this->reject($arguments, ['check', 'dry-run']);

        $loader = new ModelSchemaLoader(Config::getPathModel(), Config::getModelNamespace());
        $files = (new Generator(Config::getGeneratedNamespace()))->generate($loader->load());
        $writer = new FileSystemWriter(Config::getPathGenerated());

        if ($arguments->has('check')) {
            $drift = $writer->check($files);

            // A anotação `@extends Model<XTable>` entra no mesmo gate: ela é o que dá tipo
            // concreto a `Model::ref()`, é só um comentário, e uma errada faz a IDE
            // concordar com colunas que não existem.
            $annotations = (new RefAnnotationChecker(Config::getGeneratedNamespace()))
                ->check($loader->modelClasses());

            if ($drift->isClean() && $annotations === []) {
                $this->output->success($drift->summary());

                return 0;
            }

            if (!$drift->isClean()) {
                $this->output->error($drift->summary());
            }

            if ($annotations !== []) {
                $this->output->error(
                    "Anotação de tipo divergente nos models:\n  " . implode("\n  ", $annotations),
                );
            }

            return 1;
        }

        $dryRun = $arguments->has('dry-run');
        $report = $writer->write($files, $dryRun);

        $this->output->success(($dryRun ? '[dry-run] ' : '') . $report->summary());

        return 0;
    }

    private function migrationGenerate(Arguments $arguments): int
    {
        $this->reject($arguments, ['name', 'empty', 'dry-run', 'rename', 'allow-destructive']);

        $renames = $arguments->all('rename');

        $result = (new Generate($this->context()))->execute(
            $arguments->value('name'),
            $arguments->has('empty'),
            $arguments->has('dry-run'),
            $renames === [] ? null : new MapRenameResolver($renames),
            $arguments->has('allow-destructive'),
        );

        foreach ($result->warnings as $warning) {
            $this->output->warning($warning);
        }

        if ($result->dryRun) {
            $this->output->write($result->sql === '' ? '(sem statements)' : $result->sql);
        }

        return 0;
    }

    private function migrationUp(Arguments $arguments): int
    {
        $this->reject($arguments, ['to', 'step', 'dry-run']);

        (new Up($this->context()))->execute(
            $arguments->value('to'),
            $arguments->integer('step'),
            $arguments->has('dry-run'),
        );

        // Nada pendente não é falha: `migration:up` num deploy roda toda vez, e sair 1
        // quando já estava tudo aplicado quebraria o deploy seguinte.
        return 0;
    }

    private function migrationStatus(Arguments $arguments): int
    {
        $this->reject($arguments, []);

        $status = (new Status($this->context()))->execute();

        // Sai 1 quando há problema — migração alterada depois de aplicada, arquivo
        // ausente, aplicação interrompida no meio. `status` num pipeline serve para
        // decidir alguma coisa, e para isso o código de saída tem que significar algo.
        return $status->problems() === [] ? 0 : 1;
    }

    private function databaseCheck(Arguments $arguments): int
    {
        $this->reject($arguments, ['strict']);

        $result = (new Check($this->context()))->execute();
        $clean = $arguments->has('strict') ? $result->isCleanStrict() : $result->isClean();

        return $clean ? 0 : 1;
    }

    private function databasePush(Arguments $arguments): int
    {
        $this->reject($arguments, ['dry-run', 'allow-destructive']);

        (new Push($this->context()))->execute(
            $arguments->has('dry-run'),
            $arguments->has('allow-destructive'),
        );

        return 0;
    }

    private function databasePull(Arguments $arguments): int
    {
        $this->reject($arguments, ['write-to', 'only-declared']);

        $result = (new Pull($this->context()))->execute(
            $arguments->value('write-to'),
            $arguments->has('only-declared'),
        );

        if ($result['path'] === null) {
            $this->output->write($result['json']);
        }

        return 0;
    }

    private function databaseSeed(Arguments $arguments): int
    {
        $this->reject($arguments, ['tables']);

        $tables = $arguments->value('tables');

        (new Seed($this->context()))->execute(
            $tables === null ? null : array_values(array_filter(array_map('trim', explode(',', $tables)))),
        );

        return 0;
    }

    private function databaseReset(Arguments $arguments): int
    {
        $this->reject($arguments, ['force', 'seed', 'confirm']);

        (new Reset($this->context()))->execute(
            $arguments->has('force'),
            $arguments->has('seed'),
            $arguments->value('confirm'),
        );

        return 0;
    }

    private function context(): MigrationContext
    {
        return MigrationContext::fromConfig($this->output);
    }

    /**
     * @param list<string> $allowed
     */
    private function reject(Arguments $arguments, array $allowed): void
    {
        $unknown = $arguments->unknown($allowed);

        if ($unknown !== []) {
            throw new \InvalidArgumentException(
                'Opção desconhecida: --' . implode(', --', $unknown)
                . ($allowed === [] ? '. Este comando não aceita opções.' : '. Aceitas: --' . implode(', --', $allowed)),
            );
        }
    }

    private function usage(): string
    {
        return <<<TXT
        NeoORM

        Tipos:
          generate:types [--check] [--dry-run]
              Gera os DTOs tipados a partir dos models. Roda sem banco.
              --check não escreve e sai 1 se o gerado divergir: é o gate de CI.

        Migrações:
          migration:generate [--name=x] [--empty] [--dry-run]
                             [--rename=antiga:nova] [--allow-destructive]
              Escreve uma migração a partir da diferença entre os models e o último
              snapshot. Também roda sem banco.
              --rename pode repetir; use tabela.antiga:nova para coluna.

          migration:up [--to=tag] [--step=n] [--dry-run]
              Aplica as migrações pendentes, em ordem.

          migration:status
              O estado de cada migração. Sai 1 se houver problema.

        Banco:
          db:check [--strict]
              Confere models, snapshot e banco entre si. Sai 1 se divergirem.
              É o gate de CI. --strict também reprova tabela que nenhum model descreve.

          db:push [--dry-run] [--allow-destructive]
              Converge o banco direto com os models, sem escrever migração.
              Só desenvolvimento: recusa rodar em produção.

          db:pull [--write-to=arquivo] [--only-declared]
              Lê o schema do banco e escreve o snapshot. Sem --write-to, imprime.

          db:seed [--tables=a,b]
              Roda os seeders de PATH_SEEDS, em ordem de dependência, numa transação.

          db:reset [--force] [--seed] [--confirm=nome_do_banco]
              Derruba, recria e reaplica tudo. Recusa rodar em produção.

        Os nomes são os mesmos do `php neof` do NeoFramework.

        TXT;
    }
}
