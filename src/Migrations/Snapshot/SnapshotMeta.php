<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Snapshot;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * As renomeações que o differ não teria como adivinhar.
 *
 * Olhando dois snapshots, uma coluna renomeada é indistinguível de uma coluna
 * removida mais uma adicionada. Quem decide é uma pessoa, e a decisão fica
 * gravada aqui, no snapshot novo, versionada no repositório junto com o `.sql`.
 *
 * Isso é o que torna o diff reproduzível a partir de dado commitado: em CI, sem
 * terminal e sem ninguém para perguntar, `diff(anterior, novo)` chega à mesma
 * resposta que a máquina de quem gerou a migração. Um resolvedor interativo
 * responde apenas pelo que ainda não está registrado.
 *
 * Colunas usam chave plana `tabela.coluna` — um mapa aninhado exigiria ordenar em
 * dois níveis para o JSON ficar estável, e um `ksort` só resolve o plano.
 */
final readonly class SnapshotMeta
{
    /** @var array<string,string> nome antigo => nome novo */
    public array $renamedTables;

    /** @var array<string,string> `tabela.coluna` antiga => nome novo da coluna */
    public array $renamedColumns;

    /**
     * @param array<string,string> $renamedTables
     * @param array<string,string> $renamedColumns
     */
    public function __construct(array $renamedTables = [], array $renamedColumns = [])
    {
        $tables = [];

        foreach ($renamedTables as $old => $new) {
            $tables[IdentifierValidator::normalize((string) $old, 'Nome de tabela renomeada')] =
                IdentifierValidator::normalize($new, 'Novo nome de tabela');
        }

        $columns = [];

        foreach ($renamedColumns as $old => $new) {
            $columns[self::normalizeColumnKey((string) $old)] =
                IdentifierValidator::normalize($new, 'Novo nome de coluna');
        }

        ksort($tables, SORT_STRING);
        ksort($columns, SORT_STRING);

        $this->renamedTables = $tables;
        $this->renamedColumns = $columns;
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->renamedTables === [] && $this->renamedColumns === [];
    }

    public function renamedTable(string $from): ?string
    {
        return $this->renamedTables[strtolower(trim($from))] ?? null;
    }

    public function renamedColumn(string $table, string $from): ?string
    {
        return $this->renamedColumns[strtolower(trim($table)) . '.' . strtolower(trim($from))] ?? null;
    }

    /**
     * As renomeações de coluna de uma tabela só, já sem o prefixo.
     *
     * @return array<string,string>
     */
    public function columnRenamesFor(string $table): array
    {
        $prefix = strtolower(trim($table)) . '.';
        $renames = [];

        foreach ($this->renamedColumns as $key => $new) {
            if (str_starts_with($key, $prefix)) {
                $renames[substr($key, strlen($prefix))] = $new;
            }
        }

        return $renames;
    }

    public function withTableRename(string $from, string $to): self
    {
        return new self([...$this->renamedTables, $from => $to], $this->renamedColumns);
    }

    public function withColumnRename(string $table, string $from, string $to): self
    {
        return new self(
            $this->renamedTables,
            [...$this->renamedColumns, "{$table}.{$from}" => $to],
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return ['renamedColumns' => $this->renamedColumns, 'renamedTables' => $this->renamedTables];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string,string> $tables */
        $tables = (array) ($data['renamedTables'] ?? []);
        /** @var array<string,string> $columns */
        $columns = (array) ($data['renamedColumns'] ?? []);

        return new self($tables, $columns);
    }

    private static function normalizeColumnKey(string $key): string
    {
        $parts = explode('.', trim($key));

        if (count($parts) !== 2) {
            throw new \Diogodg\Neoorm\Migrations\Exception\MigrationException(
                "Chave de coluna renomeada precisa ser 'tabela.coluna'; recebeu '{$key}'.",
            );
        }

        return IdentifierValidator::normalize($parts[0], 'Tabela de coluna renomeada')
            . '.' . IdentifierValidator::normalize($parts[1], 'Coluna renomeada');
    }
}
