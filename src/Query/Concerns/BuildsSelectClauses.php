<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Concerns;

use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\OrderTerm;
use Diogodg\Neoorm\Query\State\JoinClause;
use Diogodg\Neoorm\Query\State\JoinType;
use Diogodg\Neoorm\Query\State\SelectState;
use Diogodg\Neoorm\Query\Table;

/**
 * As cláusulas comuns aos dois builders de SELECT.
 *
 * Existem dois builders porque um `from()` não consegue simultaneamente reescrever o
 * tipo da linha (consulta tipada) e preservá-lo como array (consulta de colunas soltas).
 * Mas as cláusulas — where, join, order, limit — são idênticas, e duplicá-las seria
 * garantir que uma correção fosse aplicada só numa das duas.
 */
trait BuildsSelectClauses
{
    abstract protected function state(): SelectState;

    /**
     * @return static
     */
    abstract protected function withState(SelectState $state): static;

    /**
     * @return static
     */
    public function where(Expression $condition): static
    {
        return $this->withState($this->state()->withWhere($condition));
    }

    /**
     * @return static
     */
    public function orderBy(OrderTerm ...$terms): static
    {
        return $this->withState($this->state()->withOrderBy(array_values($terms)));
    }

    /**
     * @return static
     */
    public function groupBy(Expression ...$columns): static
    {
        return $this->withState($this->state()->withGroupBy(array_values($columns)));
    }

    /**
     * @return static
     */
    public function having(Expression $condition): static
    {
        return $this->withState($this->state()->withHaving($condition));
    }

    /**
     * @return static
     */
    public function limit(int $limit): static
    {
        if ($limit < 0) {
            throw new \InvalidArgumentException("limit() não aceita valor negativo: {$limit}.");
        }

        return $this->withState($this->state()->withLimit($limit));
    }

    /**
     * @return static
     */
    public function offset(int $offset): static
    {
        if ($offset < 0) {
            throw new \InvalidArgumentException("offset() não aceita valor negativo: {$offset}.");
        }

        return $this->withState($this->state()->withOffset($offset));
    }

    /**
     * @return static
     */
    public function distinct(bool $distinct = true): static
    {
        return $this->withState($this->state()->withDistinct($distinct));
    }

    /**
     * @param Table<object> $table
     * @return static
     */
    public function innerJoin(Table $table, Expression $on): static
    {
        return $this->join(JoinType::Inner, $table, $on);
    }

    /**
     * @param Table<object> $table
     * @return static
     */
    public function leftJoin(Table $table, Expression $on): static
    {
        return $this->join(JoinType::Left, $table, $on);
    }

    /**
     * @param Table<object> $table
     * @return static
     */
    public function rightJoin(Table $table, Expression $on): static
    {
        return $this->join(JoinType::Right, $table, $on);
    }

    /**
     * @param Table<object> $table
     * @return static
     */
    private function join(JoinType $type, Table $table, Expression $on): static
    {
        return $this->withState(
            $this->state()->withJoin(new JoinClause($type, $table->toRef(), $on)),
        );
    }
}
