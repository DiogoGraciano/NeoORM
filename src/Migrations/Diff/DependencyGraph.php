<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Diff;

use Diogodg\Neoorm\Schema\SchemaDefinition;

/**
 * Ordena tabelas de modo que dependências venham antes de dependentes.
 *
 * Vale dizer para que isto NÃO serve, porque é a parte que se presta a
 * mal-entendido: a correção das migrações não depende desta ordem. Toda foreign
 * key é adicionada na fase P8, depois de todas as tabelas existirem, e toda
 * foreign key de uma tabela que sai é removida na P0, antes de qualquer
 * `DROP TABLE` — inclusive as que o próprio `DROP TABLE` levaria embora. É isso
 * que torna a ordem irrelevante para o banco, e é exatamente o que o sistema
 * antigo não tinha, precisando por isso de um segundo passe de foreign keys e
 * sofrendo com a ordem alfabética dos arquivos de model.
 *
 * Essa independência tem que ser garantida em algum lugar, e o lugar é a P0, não
 * aqui: com duas tabelas de foreign key mútua sendo removidas juntas, NÃO EXISTE
 * ordem de `DROP TABLE` que funcione — cada uma é referenciada pela outra. Nenhum
 * grafo resolve isso; soltar as referências antes resolve.
 *
 * O que a ordenação dá, então, é legibilidade e estabilidade: o `.sql` gerado lê
 * de pai para filho, e lê igual em toda máquina.
 *
 * Por isso `sort()` nunca lança. Ciclo é SQL legítimo aqui, e é desempatado pelo
 * menor nome — uma escolha arbitrária, mas a mesma em toda execução. Lançar
 * transformaria um schema válido em erro.
 */
final readonly class DependencyGraph
{
    /** @var array<string,list<string>> nó => nós de que depende */
    public array $dependencies;

    /**
     * @param array<string,list<string>> $dependencies
     */
    public function __construct(array $dependencies)
    {
        $normalized = [];

        foreach ($dependencies as $node => $dependsOn) {
            $edges = array_values(array_unique(array_filter(
                $dependsOn,
                // Auto-referência e aresta para nó desconhecido não são
                // ordenáveis: não há nada para criar antes. Descartá-las mantém o
                // sort dependente apenas de nós que existem.
                static fn (string $target): bool => $target !== $node && isset($dependencies[$target]),
            )));

            sort($edges, SORT_STRING);
            $normalized[$node] = $edges;
        }

        ksort($normalized, SORT_STRING);

        $this->dependencies = $normalized;
    }

    public static function fromSchema(SchemaDefinition $schema): self
    {
        return new self($schema->dependencyGraph());
    }

    /**
     * @return list<string>
     */
    public function nodes(): array
    {
        return array_keys($this->dependencies);
    }

    /**
     * Dependências antes dos dependentes. Kahn, com o conjunto pronto ordenado.
     *
     * @return list<string>
     */
    public function sort(): array
    {
        $remaining = $this->dependencies;
        $resolved = [];
        $order = [];

        while ($remaining !== []) {
            $ready = [];

            foreach ($remaining as $node => $dependsOn) {
                foreach ($dependsOn as $dependency) {
                    if (!isset($resolved[$dependency])) {
                        continue 2;
                    }
                }

                $ready[] = $node;
            }

            if ($ready === []) {
                // Ciclo. `$remaining` está ordenado por nome e `unset` preserva a
                // ordem, então a primeira chave é o menor nome restante: um
                // desempate arbitrário, mas o mesmo em toda execução.
                $ready = [(string) array_key_first($remaining)];
            }

            sort($ready, SORT_STRING);

            foreach ($ready as $node) {
                $order[] = $node;
                $resolved[$node] = true;
                unset($remaining[$node]);
            }
        }

        return $order;
    }

    /**
     * Dependentes antes das dependências — a ordem de remoção.
     *
     * @return list<string>
     */
    public function reverse(): array
    {
        return array_reverse($this->sort());
    }

    /**
     * Existe ciclo?
     *
     * Só para diagnóstico: nada no fluxo de migração muda por causa disso, já que
     * FK mútua é válida. Serve a `db:check`, que pode querer mencionar.
     */
    public function hasCycle(): bool
    {
        $remaining = $this->dependencies;

        do {
            $progressed = false;

            foreach ($remaining as $node => $dependsOn) {
                $pending = array_filter(
                    $dependsOn,
                    static fn (string $dependency): bool => isset($remaining[$dependency]),
                );

                if ($pending === []) {
                    unset($remaining[$node]);
                    $progressed = true;
                }
            }
        } while ($progressed && $remaining !== []);

        return $remaining !== [];
    }

    /**
     * Ordena uma lista de nomes segundo o grafo, mantendo no fim os que não estão
     * no grafo.
     *
     * É o que o `OperationSorter` usa: a lista de tabelas a criar é um subconjunto
     * do schema, e os nomes fora do grafo (não deveria haver, mas) precisam de um
     * destino definido em vez de desaparecerem.
     *
     * @param list<string> $names
     * @return list<string>
     */
    public function orderOf(array $names): array
    {
        $wanted = array_fill_keys($names, true);
        $ordered = [];

        foreach ($this->sort() as $node) {
            if (isset($wanted[$node])) {
                $ordered[] = $node;
                unset($wanted[$node]);
            }
        }

        $leftovers = array_keys($wanted);
        sort($leftovers, SORT_STRING);

        return [...$ordered, ...$leftovers];
    }
}
