<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Uma tabela inteira, imutável e sem SQL.
 *
 * Colunas ficam em ORDEM DE DECLARAÇÃO, uma ordem só. O sistema antigo mantinha
 * a convenção implícita de que `columns[0]` era a chave primária, sustentada por
 * um `array_reverse` de um elemento (que é no-op) e pelo fato de todo model
 * declarar `id` primeiro. Declarar a PK em outra posição embaralhava a lista
 * silenciosamente. Aqui a PK é um campo próprio; posição não significa nada.
 */
final readonly class TableDefinition
{
    public string $name;

    /** @var array<string,ColumnDefinition> ordem de declaração */
    public array $columns;

    /** @var array<string,UniqueConstraintDefinition> */
    public array $uniqueConstraints;

    /** @var array<string,IndexDefinition> */
    public array $indexes;

    /** @var array<string,ForeignKeyDefinition> */
    public array $foreignKeys;

    /** @var array<string,CheckConstraintDefinition> */
    public array $checks;

    public ?string $comment;

    /**
     * @param list<ColumnDefinition>            $columns
     * @param list<UniqueConstraintDefinition>  $uniqueConstraints
     * @param list<IndexDefinition>             $indexes
     * @param list<ForeignKeyDefinition>        $foreignKeys
     * @param list<CheckConstraintDefinition>   $checks
     */
    public function __construct(
        string $name,
        array $columns,
        public ?PrimaryKeyDefinition $primaryKey = null,
        array $uniqueConstraints = [],
        array $indexes = [],
        array $foreignKeys = [],
        array $checks = [],
        ?string $comment = null,
        public TableOptions $options = new TableOptions(),
    ) {
        $this->name = IdentifierValidator::normalize($name, 'Nome de tabela');
        $this->comment = ($comment === null || $comment === '') ? null : $comment;

        $byName = [];

        foreach ($columns as $column) {
            if (isset($byName[$column->name])) {
                throw new SchemaException("Tabela '{$this->name}': coluna duplicada '{$column->name}'.");
            }

            $byName[$column->name] = $column;
        }

        if ($byName === []) {
            throw new SchemaException("Tabela '{$this->name}' não tem nenhuma coluna.");
        }

        $this->columns = $byName;
        $this->uniqueConstraints = self::indexByName($uniqueConstraints, $this->name, 'restrição de unicidade');
        $this->indexes = self::indexByName($indexes, $this->name, 'índice');
        $this->foreignKeys = self::indexByName($foreignKeys, $this->name, 'foreign key');
        $this->checks = self::indexByName($checks, $this->name, 'restrição CHECK');
    }

    /**
     * @template T of NamedDefinition
     * @param list<T> $items
     * @return array<string,T>
     */
    private static function indexByName(array $items, string $table, string $kind): array
    {
        $byName = [];

        foreach ($items as $item) {
            $name = $item->name;

            if (isset($byName[$name])) {
                throw new SchemaException("Tabela '{$table}': {$kind} duplicada '{$name}'.");
            }

            $byName[$name] = $item;
        }

        return $byName;
    }

    /**
     * @return array<string,ColumnDefinition>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * @return list<string>
     */
    public function getColumnNames(): array
    {
        return array_keys($this->columns);
    }

    public function column(string $name): ?ColumnDefinition
    {
        return $this->columns[strtolower($name)] ?? null;
    }

    public function hasColumn(string $name): bool
    {
        return isset($this->columns[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    public function getPrimaryKeyColumns(): array
    {
        return $this->primaryKey === null ? [] : $this->primaryKey->columns;
    }

    public function hasAutoIncrement(): bool
    {
        return $this->autoIncrementColumn() !== null;
    }

    public function autoIncrementColumn(): ?ColumnDefinition
    {
        foreach ($this->columns as $column) {
            if ($column->autoIncrement) {
                return $column;
            }
        }

        return null;
    }

    /**
     * Tabelas de que esta depende, ordenadas e sem repetição.
     *
     * A auto-referência é excluída: uma tabela não precisa existir antes de si
     * mesma, e mantê-la no grafo criaria um ciclo trivial em todo model com FK
     * para a própria tabela.
     *
     * @return list<string>
     */
    public function referencedTables(): array
    {
        $tables = [];

        foreach ($this->foreignKeys as $foreignKey) {
            if ($foreignKey->referencedTable !== $this->name) {
                $tables[$foreignKey->referencedTable] = true;
            }
        }

        $names = array_keys($tables);
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'checks' => self::mapToArray($this->checks),
            'columnOrder' => $this->getColumnNames(),
            'columns' => self::mapToArray($this->columns),
            'comment' => $this->comment,
            'foreignKeys' => self::mapToArray($this->foreignKeys),
            'indexes' => self::mapToArray($this->indexes),
            'name' => $this->name,
            'options' => $this->options->toArray(),
            'primaryKey' => $this->primaryKey?->toArray(),
            'uniqueConstraints' => self::mapToArray($this->uniqueConstraints),
        ];
    }

    /**
     * A tabela na forma em que o sistema a COMPARA.
     *
     * Difere de `toArray()` em dois pontos: a expressão de cada CHECK vem normalizada, e
     * literal numérico de default vem numa forma canônica. Os dois existem pelo mesmo
     * motivo — é o que permite afirmar "este banco tem este schema" sem tropeçar na
     * reescrita que o PostgreSQL faz nas expressões, nem na diferença entre um zero
     * inteiro e um zero real que os catálogos devolvem.
     *
     * @return array<string,mixed>
     */
    public function toComparableArray(): array
    {
        $data = $this->toArray();

        $checks = [];

        foreach ($this->checks as $name => $check) {
            $checks[$name] = $check->toComparableArray();
        }

        ksort($checks, SORT_STRING);
        $data['checks'] = $checks;

        $columns = [];

        foreach ($this->columns as $name => $column) {
            $columns[$name] = $column->toComparableArray();
        }

        ksort($columns, SORT_STRING);
        $data['columns'] = $columns;

        return $data;
    }

    /**
     * Serializa um mapa com as chaves ordenadas.
     *
     * A ordenação é o que garante que o JSON do snapshot seja idêntico byte a
     * byte independentemente da ordem em que as coisas foram declaradas — sem
     * isso, cada `generate` produziria diff onde não houve mudança.
     *
     * @param array<string,NamedDefinition> $items
     * @return array<string,mixed>
     */
    private static function mapToArray(array $items): array
    {
        $data = [];

        foreach ($items as $key => $item) {
            $data[$key] = $item->toArray();
        }

        ksort($data, SORT_STRING);

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string,array<string,mixed>> $rawColumns */
        $rawColumns = (array) ($data['columns'] ?? []);
        /** @var list<string> $order */
        $order = array_values((array) ($data['columnOrder'] ?? array_keys($rawColumns)));

        $columns = [];

        // columnOrder é a única lista ordenada do snapshot; `columns` vem
        // ordenado alfabeticamente por determinismo, então a ordem de
        // declaração precisa ser restaurada a partir dela.
        foreach ($order as $name) {
            if (isset($rawColumns[$name])) {
                $columns[] = ColumnDefinition::fromArray($rawColumns[$name]);
                unset($rawColumns[$name]);
            }
        }

        foreach ($rawColumns as $rawColumn) {
            $columns[] = ColumnDefinition::fromArray($rawColumn);
        }

        return new self(
            name: (string) $data['name'],
            columns: $columns,
            primaryKey: isset($data['primaryKey']) && is_array($data['primaryKey'])
                ? PrimaryKeyDefinition::fromArray($data['primaryKey'])
                : null,
            uniqueConstraints: array_values(array_map(
                static fn (array $raw): UniqueConstraintDefinition => UniqueConstraintDefinition::fromArray($raw),
                (array) ($data['uniqueConstraints'] ?? []),
            )),
            indexes: array_values(array_map(
                static fn (array $raw): IndexDefinition => IndexDefinition::fromArray($raw),
                (array) ($data['indexes'] ?? []),
            )),
            foreignKeys: array_values(array_map(
                static fn (array $raw): ForeignKeyDefinition => ForeignKeyDefinition::fromArray($raw),
                (array) ($data['foreignKeys'] ?? []),
            )),
            checks: array_values(array_map(
                static fn (array $raw): CheckConstraintDefinition => CheckConstraintDefinition::fromArray($raw),
                (array) ($data['checks'] ?? []),
            )),
            comment: isset($data['comment']) ? (string) $data['comment'] : null,
            options: TableOptions::fromArray((array) ($data['options'] ?? [])),
        );
    }
}
