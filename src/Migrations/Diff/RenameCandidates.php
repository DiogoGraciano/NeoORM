<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Diff;

/**
 * As ambiguidades que o differ encontrou e não resolveu.
 *
 * Uma tabela (ou coluna) que desapareceu ao lado de outra que apareceu tem duas
 * leituras possíveis, e as duas são plausíveis: renomeação, ou remoção mais
 * criação. A segunda apaga dados.
 *
 * `migration:generate` usa isto para decidir entre perguntar (terminal), abortar
 * sem escrever nada (CI) ou seguir com a leitura destrutiva (`--allow-destructive`).
 * Abortar é o padrão porque escrever a migração e avisar depois já teria criado o
 * arquivo que alguém vai aplicar.
 */
final readonly class RenameCandidates
{
    /**
     * @param list<string>                                                  $removedTables
     * @param list<string>                                                  $addedTables
     * @param array<string,array{removed:list<string>,added:list<string>}>   $columns por tabela
     */
    public function __construct(
        public array $removedTables = [],
        public array $addedTables = [],
        public array $columns = [],
    ) {
    }

    public function isEmpty(): bool
    {
        return !$this->hasTableAmbiguity() && $this->ambiguousColumnTables() === [];
    }

    /**
     * Ambiguidade de verdade exige os dois lados: só remoções é uma remoção, e só
     * criações é uma criação. Nenhuma das duas precisa de pergunta.
     */
    public function hasTableAmbiguity(): bool
    {
        return $this->removedTables !== [] && $this->addedTables !== [];
    }

    /**
     * @return list<string>
     */
    public function ambiguousColumnTables(): array
    {
        $tables = [];

        foreach ($this->columns as $table => $sides) {
            if ($sides['removed'] !== [] && $sides['added'] !== []) {
                $tables[] = (string) $table;
            }
        }

        sort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * Uma linha por ambiguidade, para a mensagem que o comando imprime antes de
     * sair com erro.
     *
     * @return list<string>
     */
    public function describe(): array
    {
        $lines = [];

        if ($this->hasTableAmbiguity()) {
            $lines[] = 'Tabelas removidas (' . implode(', ', $this->removedTables) . ') e adicionadas ('
                . implode(', ', $this->addedTables) . '): pode ser renomeação. '
                . 'Use --rename antiga:nova para cada par, ou --allow-destructive para remover mesmo.';
        }

        foreach ($this->ambiguousColumnTables() as $table) {
            $sides = $this->columns[$table];
            $lines[] = "Na tabela '{$table}', colunas removidas (" . implode(', ', $sides['removed'])
                . ') e adicionadas (' . implode(', ', $sides['added']) . '): pode ser renomeação. '
                . "Use --rename {$table}.antiga:nova para cada par, ou --allow-destructive.";
        }

        return $lines;
    }
}
