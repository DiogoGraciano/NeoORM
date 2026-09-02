<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use PDOStatement;

/**
 * A transação como handle de verdade.
 *
 * `Tx implements Executor`, então `$tx->insert(...)` executa DENTRO da transação, sem
 * que quem monta a consulta precise saber onde está. É a diferença central para o
 * modelo antigo: lá a transação era estática e global, então esquecer de passá-la não
 * dava erro — a escrita simplesmente acontecia fora dela, e o rollback não a desfazia.
 *
 * O aninhamento é por savepoint, tratado pelo `Transactions` compartilhado.
 */
final class Tx implements Executor
{
    public function __construct(
        private readonly Database $database,
        public readonly int $depth,
    ) {
    }

    public function dialect(): Dialect
    {
        return $this->database->dialect();
    }

    public function run(CompiledQuery $query): PDOStatement
    {
        return $this->database->run($query);
    }

    public function lastInsertId(): string|false
    {
        return $this->database->lastInsertId();
    }

    /**
     * @return SelectBuilder<object>
     */
    public function select(): SelectBuilder
    {
        return new SelectBuilder($this);
    }

    /**
     * @param list<Expr\Expression> $columns
     */
    public function selectFields(array $columns): FieldSelectBuilder
    {
        return (new FieldSelectBuilder($this))->withColumns($columns);
    }

    /**
     * @template TRow of object
     * @param Table<TRow> $table
     * @return InsertBuilder<TRow>
     */
    public function insert(Table $table): InsertBuilder
    {
        return new InsertBuilder($this, $table);
    }

    /**
     * @template TRow of object
     * @param Table<TRow> $table
     * @return UpdateBuilder<TRow>
     */
    public function update(Table $table): UpdateBuilder
    {
        return new UpdateBuilder($this, $table);
    }

    /**
     * @template TRow of object
     * @param Table<TRow> $table
     * @return DeleteBuilder<TRow>
     */
    public function delete(Table $table): DeleteBuilder
    {
        return new DeleteBuilder($this, $table);
    }

    /**
     * Transação dentro de transação: vira savepoint.
     *
     * @template T
     * @param callable(Tx):T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        return $this->database->transaction($work);
    }
}
