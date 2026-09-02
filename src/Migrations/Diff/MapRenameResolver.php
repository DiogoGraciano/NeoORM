<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Diff;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Renomeações ditas na linha de comando: `--rename city:town`, `--rename city.name:title`.
 *
 * A presença do ponto distingue os dois casos, o que só funciona porque
 * `IdentifierValidator` recusa ponto dentro de um identificador — não há
 * ambiguidade possível entre uma tabela chamada `city.name` e a coluna `name` da
 * tabela `city`.
 *
 * Um par que não corresponde a nenhuma diferença real é erro, não silêncio: quem
 * escreveu `--rename city:town` e digitou o nome errado precisa saber, porque a
 * alternativa é a migração remover a tabela achando que foi isso que se pediu.
 */
final class MapRenameResolver implements RenameResolver
{
    /** @var array<string,string> */
    private array $tables = [];

    /** @var array<string,string> chave `tabela.coluna` */
    private array $columns = [];

    /**
     * @param list<string> $renames pares no formato `antigo:novo`
     */
    public function __construct(array $renames = [], private readonly bool $strict = true)
    {
        foreach ($renames as $rename) {
            $this->add($rename);
        }
    }

    private function add(string $rename): void
    {
        $parts = explode(':', trim($rename));

        if (count($parts) !== 2 || trim($parts[0]) === '' || trim($parts[1]) === '') {
            throw new MigrationException(
                "Renomeação inválida: '{$rename}'. Use 'antiga:nova' para tabela ou "
                . "'tabela.antiga:nova' para coluna.",
            );
        }

        [$source, $target] = [trim($parts[0]), trim($parts[1])];

        if (!str_contains($source, '.')) {
            $this->tables[IdentifierValidator::normalize($source, 'Tabela de origem')] =
                IdentifierValidator::normalize($target, 'Tabela de destino');

            return;
        }

        $sourceParts = explode('.', $source);

        if (count($sourceParts) !== 2) {
            throw new MigrationException(
                "Renomeação de coluna inválida: '{$rename}'. Use 'tabela.antiga:nova'.",
            );
        }

        $table = IdentifierValidator::normalize($sourceParts[0], 'Tabela');
        $column = IdentifierValidator::normalize($sourceParts[1], 'Coluna de origem');

        if (str_contains($target, '.')) {
            throw new MigrationException(
                "Renomeação de coluna inválida: '{$rename}'. O destino é só o nome novo da coluna, "
                . 'sem a tabela — mover coluna entre tabelas não é renomeação.',
            );
        }

        $this->columns["{$table}.{$column}"] = IdentifierValidator::normalize($target, 'Coluna de destino');
    }

    public function resolveTables(array $removed, array $added): array
    {
        return $this->matching($this->tables, $removed, $added, 'tabela', null);
    }

    public function resolveColumns(string $table, array $removed, array $added): array
    {
        $prefix = strtolower(trim($table)) . '.';
        $forThisTable = [];

        foreach ($this->columns as $key => $target) {
            if (str_starts_with($key, $prefix)) {
                $forThisTable[substr($key, strlen($prefix))] = $target;
            }
        }

        return $this->matching($forThisTable, $removed, $added, 'coluna', $table);
    }

    /**
     * @param array<string,string> $requested
     * @param list<string>         $removed
     * @param list<string>         $added
     * @return array<string,string>
     */
    private function matching(
        array $requested,
        array $removed,
        array $added,
        string $kind,
        ?string $table,
    ): array {
        $removedSet = array_fill_keys($removed, true);
        $addedSet = array_fill_keys($added, true);
        $where = $table === null ? '' : " na tabela '{$table}'";

        $confirmed = [];

        foreach ($requested as $old => $new) {
            if (!isset($removedSet[$old])) {
                if ($this->strict) {
                    throw new MigrationException(
                        "--rename pediu para renomear a {$kind} '{$old}'{$where}, que não desapareceu "
                        . 'do schema. Confira o nome de origem.',
                    );
                }

                continue;
            }

            if (!isset($addedSet[$new])) {
                if ($this->strict) {
                    throw new MigrationException(
                        "--rename pediu para renomear '{$old}' para '{$new}'{$where}, mas '{$new}' não "
                        . 'apareceu no schema novo. Confira o nome de destino.',
                    );
                }

                continue;
            }

            $confirmed[$old] = $new;
        }

        return $confirmed;
    }
}
