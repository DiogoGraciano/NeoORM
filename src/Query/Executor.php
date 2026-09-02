<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use PDOStatement;

/**
 * O que sabe executar consultas: o `Database` e a transação `Tx`.
 *
 * Uma interface só para os dois é o que faz `$tx->insert(...)` executar DENTRO da
 * transação sem que o código que monta a consulta precise saber onde está. Um
 * repositório type-hinta `Executor` e funciona dos dois jeitos — que é o contrário do
 * modelo antigo, onde a transação era estática e global e o código não tinha como
 * declarar que dependia dela.
 */
interface Executor
{
    public function dialect(): Dialect;

    public function run(CompiledQuery $query): PDOStatement;

    public function lastInsertId(): string|false;

    /**
     * @return SelectBuilder<object>
     */
    public function select(): SelectBuilder;

    /**
     * Consulta que devolve colunas soltas, não linhas tipadas.
     *
     * @param list<Expr\Expression> $columns
     */
    public function selectFields(array $columns): FieldSelectBuilder;

    /**
     * @template TRow of object
     * @param Table<TRow> $table
     * @return InsertBuilder<TRow>
     */
    public function insert(Table $table): InsertBuilder;

    /**
     * @template TRow of object
     * @param Table<TRow> $table
     * @return UpdateBuilder<TRow>
     */
    public function update(Table $table): UpdateBuilder;

    /**
     * @template TRow of object
     * @param Table<TRow> $table
     * @return DeleteBuilder<TRow>
     */
    public function delete(Table $table): DeleteBuilder;

    /**
     * @template T
     * @param callable(Tx):T $work
     * @return T
     */
    public function transaction(callable $work): mixed;
}
