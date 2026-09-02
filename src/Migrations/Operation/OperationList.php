<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use ArrayIterator;
use Countable;
use IteratorAggregate;
use Traversable;

/**
 * Uma sequência ordenada de operações.
 *
 * Imutável de propósito: o `OperationSorter` produz a ordem final uma vez, e
 * nada depois disso pode reordenar por acidente. A ordem *é* a correção — uma
 * `AddForeignKey` antes do `CreateTable` da tabela referenciada não é um detalhe
 * estético, é um erro de SQL.
 *
 * @implements IteratorAggregate<int,SchemaOperation>
 */
final readonly class OperationList implements Countable, IteratorAggregate
{
    /** @var list<SchemaOperation> */
    public array $operations;

    /**
     * @param list<SchemaOperation> $operations
     */
    public function __construct(array $operations = [])
    {
        $this->operations = array_values($operations);
    }

    public static function of(SchemaOperation ...$operations): self
    {
        return new self(array_values($operations));
    }

    public function isEmpty(): bool
    {
        return $this->operations === [];
    }

    public function count(): int
    {
        return count($this->operations);
    }

    public function getIterator(): Traversable
    {
        return new ArrayIterator($this->operations);
    }

    /**
     * @return list<SchemaOperation>
     */
    public function all(): array
    {
        return $this->operations;
    }

    public function with(SchemaOperation ...$operations): self
    {
        return new self([...$this->operations, ...array_values($operations)]);
    }

    public function merge(self $other): self
    {
        return new self([...$this->operations, ...$other->operations]);
    }

    /**
     * @param callable(SchemaOperation):bool $predicate
     */
    public function filter(callable $predicate): self
    {
        return new self(array_values(array_filter($this->operations, $predicate)));
    }

    public function hasDestructive(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->isDestructive()) {
                return true;
            }
        }

        return false;
    }

    public function destructive(): self
    {
        return $this->filter(static fn (SchemaOperation $o): bool => $o->isDestructive());
    }

    /**
     * As operações que APAGAM dados, que são um subconjunto próprio das arriscadas.
     *
     * A distinção decide quem avisa e quem bloqueia: arriscado falha e não perde nada;
     * descartar sucede e perde. Sem separar as duas, a primeira migração de qualquer projeto
     * — que cria tabelas com restrições de unicidade — exigiria `--allow-destructive`, e uma
     * confirmação que se dá sempre é uma que ninguém lê.
     */
    public function discardsData(): bool
    {
        foreach ($this->operations as $operation) {
            if ($operation->discardsData()) {
                return true;
            }
        }

        return false;
    }

    public function dataDiscarding(): self
    {
        return $this->filter(static fn (SchemaOperation $o): bool => $o->discardsData());
    }

    /**
     * @return list<string>
     */
    public function describe(): array
    {
        return array_map(static fn (SchemaOperation $o): string => $o->describe(), $this->operations);
    }

    /**
     * Tabelas tocadas, ordenadas e sem repetição.
     *
     * @return list<string>
     */
    public function tables(): array
    {
        $tables = [];

        foreach ($this->operations as $operation) {
            $name = $operation->tableName();

            if ($name !== '') {
                $tables[$name] = true;
            }
        }

        $names = array_keys($tables);
        sort($names, SORT_STRING);

        return $names;
    }
}
