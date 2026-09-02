<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Diff;

/**
 * Pergunta, uma vez por objeto que desapareceu.
 *
 * Pergunta na direção "o que aconteceu com o que sumiu?" em vez de "de onde veio o
 * que apareceu?" porque é a remoção que destrói dados: se a resposta for "nenhuma
 * das opções", o resultado é um DROP, e essa é a decisão que merece uma pergunta
 * explícita.
 *
 * Uma vez respondido, cada nome de destino sai das opções seguintes — uma coluna
 * não pode ser o destino de duas renomeações, e oferecê-la de novo convidaria a um
 * par de operações contraditórias que só falharia no banco.
 */
final class InteractiveRenameResolver implements RenameResolver
{
    public function __construct(private readonly Prompter $prompter)
    {
    }

    public function resolveTables(array $removed, array $added): array
    {
        return $this->resolve(
            $removed,
            $added,
            static fn (string $name): string => "A tabela '{$name}' desapareceu do schema. Ela foi renomeada?",
        );
    }

    public function resolveColumns(string $table, array $removed, array $added): array
    {
        return $this->resolve(
            $removed,
            $added,
            static fn (string $name): string =>
                "A coluna '{$table}.{$name}' desapareceu do schema. Ela foi renomeada?",
        );
    }

    /**
     * @param list<string>            $removed
     * @param list<string>            $added
     * @param callable(string):string $question
     * @return array<string,string>
     */
    private function resolve(array $removed, array $added, callable $question): array
    {
        if ($removed === [] || $added === []) {
            return [];
        }

        $available = $added;
        $renames = [];

        foreach ($removed as $old) {
            if ($available === []) {
                break;
            }

            $chosen = $this->prompter->choose($question($old), array_values($available));

            if ($chosen === null) {
                continue;
            }

            $position = array_search($chosen, $available, true);

            if ($position === false) {
                // O prompter devolveu algo que não estava na lista. Tratar como
                // "nenhuma" é mais seguro que aceitar: aceitar geraria um
                // RenameColumn para uma coluna que não existe no schema novo.
                continue;
            }

            $renames[$old] = $chosen;
            unset($available[$position]);
        }

        return $renames;
    }
}
