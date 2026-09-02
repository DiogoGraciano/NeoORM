<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Query\Compiler\Compiler;
use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use Diogodg\Neoorm\Query\Concerns\HydratesRows;
use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\State\DeleteState;

/**
 * O DELETE.
 *
 * @template TRow of object
 */
final readonly class DeleteBuilder
{
    use HydratesRows;

    /**
     * @param Table<TRow> $table
     */
    public function __construct(
        private Executor $executor,
        private Table $table,
        private ?DeleteState $deleteState = null,
        private Compiler $compiler = new Compiler(),
    ) {
    }

    /**
     * @return self<TRow>
     */
    public function where(Expression $condition): self
    {
        return $this->with($this->state()->withWhere($condition));
    }

    /**
     * Autoriza o DELETE sem WHERE, que apaga a tabela inteira.
     *
     * @return self<TRow>
     */
    public function allowFullTableScan(): self
    {
        return $this->with($this->state()->withFullTableScan());
    }

    public function execute(): int
    {
        return $this->executor->run($this->toSql())->rowCount();
    }

    /**
     * As linhas apagadas. Só no PostgreSQL.
     *
     * @return list<TRow>
     */
    public function returning(): array
    {
        $state = $this->state()->withReturning($this->table->columnNames());
        $statement = $this->executor->run(
            $this->compiler->compileDelete($state, $this->executor->dialect()),
        );

        return $this->hydrateRows($statement, $this->table);
    }

    public function toSql(): CompiledQuery
    {
        return $this->compiler->compileDelete($this->state(), $this->executor->dialect());
    }

    private function state(): DeleteState
    {
        return $this->deleteState ?? new DeleteState($this->table->toRef());
    }

    /**
     * @return self<TRow>
     */
    private function with(DeleteState $state): self
    {
        return new self($this->executor, $this->table, $state, $this->compiler);
    }
}
