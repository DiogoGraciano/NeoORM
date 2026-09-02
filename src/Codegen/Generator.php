<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

use Diogodg\Neoorm\Codegen\Emitter\EnumEmitter;
use Diogodg\Neoorm\Codegen\Emitter\InsertEmitter;
use Diogodg\Neoorm\Codegen\Emitter\RegistryEmitter;
use Diogodg\Neoorm\Codegen\Emitter\RowEmitter;
use Diogodg\Neoorm\Codegen\Emitter\TableEmitter;
use Diogodg\Neoorm\Codegen\Plan\TablePlan;
use Diogodg\Neoorm\Schema\SchemaDefinition;

/**
 * Schema entra, arquivos saem — **como strings**.
 *
 * A pureza é a decisão central da camada. Sem I/O aqui, a geração inteira é testável
 * comparando texto: sem diretório temporário, sem limpeza, sem teste que deixa lixo
 * quando falha no meio. E é o que torna o `--check` de drift quase de graça, já que
 * conferir vira comparar o que sairia com o que está em disco.
 *
 * Também não escreve na saída padrão. `Migrate` e o gerador antigo faziam `echo` no meio
 * do trabalho, e é por isso que nenhum dos dois roda sob `beStrictAboutOutputDuringTests`.
 */
final class Generator
{
    public function __construct(
        private readonly string $namespace,
        private readonly TypeMapper $types = new TypeMapper(),
        private readonly RowEmitter $rows = new RowEmitter(),
        private readonly InsertEmitter $inserts = new InsertEmitter(),
        private readonly TableEmitter $tables = new TableEmitter(),
        private readonly EnumEmitter $enums = new EnumEmitter(),
        private readonly RegistryEmitter $registry = new RegistryEmitter(),
    ) {
    }

    /**
     * @return list<GeneratedFile> em ordem estável de caminho
     */
    public function generate(SchemaDefinition $schema): array
    {
        $files = [];
        $plans = [];

        foreach ($schema->tables as $table) {
            $plan = TablePlan::build($table, $this->types, $this->namespace);
            $plans[] = $plan;

            $files[] = $this->rows->emit($plan, $this->namespace);
            $files[] = $this->inserts->emit($plan, $this->namespace);
            $files[] = $this->tables->emit($plan, $this->namespace);

            foreach ($this->enums->emit($plan, $this->namespace) as $enum) {
                $files[] = $enum;
            }
        }

        if ($plans !== []) {
            $files[] = $this->registry->emit($plans, $this->namespace);
        }

        // Ordem por caminho, e não de emissão: é o que faz duas execuções produzirem a
        // mesma lista mesmo que a ordem das tabelas mude.
        usort($files, static fn (GeneratedFile $a, GeneratedFile $b): int
            => strcmp($a->relativePath, $b->relativePath));

        return $files;
    }
}
