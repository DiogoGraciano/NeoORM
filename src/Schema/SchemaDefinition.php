<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;

/**
 * O schema inteiro: raiz do IR.
 *
 * É a única coisa que significa "o schema" na biblioteca — o objeto que não
 * existia antes, e cuja ausência obrigava o differ a reconstruir a definição
 * por reflexão sobre os builders, que é onde os contratos quebravam em silêncio.
 */
final readonly class SchemaDefinition
{
    /** @var array<string,TableDefinition> ordenado por nome */
    public array $tables;

    /**
     * @param list<TableDefinition> $tables
     */
    public function __construct(array $tables = [])
    {
        $byName = [];

        foreach ($tables as $table) {
            if (isset($byName[$table->name])) {
                throw new SchemaException("Tabela duplicada no schema: '{$table->name}'.");
            }

            $byName[$table->name] = $table;
        }

        ksort($byName, SORT_STRING);

        $this->tables = $byName;
    }

    public static function empty(): self
    {
        return new self();
    }

    public function table(string $name): ?TableDefinition
    {
        return $this->tables[strtolower($name)] ?? null;
    }

    public function hasTable(string $name): bool
    {
        return isset($this->tables[strtolower($name)]);
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->tables);
    }

    public function isEmpty(): bool
    {
        return $this->tables === [];
    }

    /**
     * Grafo de dependência por foreign key: tabela => tabelas de que depende.
     *
     * Referências para tabelas fora do schema são descartadas. Isso é
     * deliberado: elas não podem ser ordenadas (não há o que criar antes), e
     * mantê-las faria o sort topológico depender de nós inexistentes. Se a
     * tabela referenciada realmente não deveria faltar, quem reclama é o
     * SchemaValidator, com o nome da FK.
     *
     * @return array<string,list<string>>
     */
    public function dependencyGraph(): array
    {
        $graph = [];

        foreach ($this->tables as $name => $table) {
            $graph[$name] = array_values(array_filter(
                $table->referencedTables(),
                fn (string $referenced): bool => isset($this->tables[$referenced]),
            ));
        }

        return $graph;
    }

    /**
     * Novo schema com uma tabela adicionada ou substituída.
     */
    public function withTable(TableDefinition $table): self
    {
        $tables = $this->tables;
        $tables[$table->name] = $table;

        return new self(array_values($tables));
    }

    public function withoutTable(string $name): self
    {
        $tables = $this->tables;
        unset($tables[strtolower($name)]);

        return new self(array_values($tables));
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $tables = [];

        foreach ($this->tables as $name => $table) {
            $tables[$name] = $table->toArray();
        }

        ksort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * O schema na forma em que o sistema o COMPARA. Ver TableDefinition::toComparableArray().
     *
     * @return array<string,mixed>
     */
    public function toComparableArray(): array
    {
        $tables = [];

        foreach ($this->tables as $name => $table) {
            $tables[$name] = $table->toComparableArray();
        }

        ksort($tables, SORT_STRING);

        return $tables;
    }

    /**
     * @param array<string,array<string,mixed>> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_values(array_map(
            static fn (array $raw): TableDefinition => TableDefinition::fromArray($raw),
            $data,
        )));
    }
}
