<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Diff;

/**
 * Nada é renomeação.
 *
 * O padrão, e o padrão certo para CI: sem ninguém para perguntar, a resposta
 * segura é tratar remoção como remoção. O que impede isso de apagar uma coluna por
 * engano não é este resolvedor, é o comando: diante de uma forma ambígua — uma
 * coluna removida e outra adicionada na mesma tabela — `migration:generate` não
 * escreve nada, imprime a ambiguidade e sai com erro, a menos que venha `--rename`
 * ou `--allow-destructive`.
 */
final class NoRenameResolver implements RenameResolver
{
    public function resolveTables(array $removed, array $added): array
    {
        return [];
    }

    public function resolveColumns(string $table, array $removed, array $added): array
    {
        return [];
    }
}
