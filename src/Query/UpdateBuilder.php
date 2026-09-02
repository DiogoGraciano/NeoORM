<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Query\Compiler\Compiler;
use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use Diogodg\Neoorm\Query\Concerns\HydratesRows;
use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\Value;
use Diogodg\Neoorm\Query\State\UpdateState;

/**
 * O UPDATE.
 *
 * Só escreve as colunas informadas — que é a diferença prática para o `store()` do
 * sistema antigo, que mandava a linha inteira. Com a linha inteira, dois requests que
 * alteram campos diferentes do mesmo registro se sobrescrevem: o segundo desfaz o
 * primeiro, sem conflito nem aviso.
 *
 * @template TRow of object
 */
final readonly class UpdateBuilder
{
    use HydratesRows;

    /**
     * @param Table<TRow> $table
     */
    public function __construct(
        private Executor $executor,
        private Table $table,
        private ?UpdateState $updateState = null,
        private Compiler $compiler = new Compiler(),
    ) {
    }

    /**
     * @param array<string,mixed> $assignments coluna => novo valor
     * @return self<TRow>
     */
    public function set(array $assignments): self
    {
        $expressions = [];

        foreach ($assignments as $column => $value) {
            $expressions[$column] = $value instanceof Expression
                ? $value
                : new Value($value, columnType: $this->table->columnRef($column)->type);
        }

        return $this->with($this->state()->withAssignments($expressions));
    }

    /**
     * @return self<TRow>
     */
    public function where(Expression $condition): self
    {
        return $this->with($this->state()->withWhere($condition));
    }

    /**
     * Autoriza o UPDATE sem WHERE, que reescreve a tabela inteira.
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
     * As linhas atualizadas. Só no PostgreSQL.
     *
     * @return list<TRow>
     */
    public function returning(): array
    {
        $state = $this->state()->withReturning($this->table->columnNames());
        $statement = $this->executor->run(
            $this->compiler->compileUpdate($state, $this->executor->dialect()),
        );

        return $this->hydrateRows($statement, $this->table);
    }

    public function toSql(): CompiledQuery
    {
        return $this->compiler->compileUpdate($this->state(), $this->executor->dialect());
    }

    /**
     * O estado nasce aqui, e não no construtor, porque default de parâmetro precisa ser
     * expressão constante — e o estado depende da tabela recebida.
     */
    private function state(): UpdateState
    {
        return $this->updateState ?? new UpdateState($this->table->toRef());
    }

    /**
     * @return self<TRow>
     */
    private function with(UpdateState $state): self
    {
        return new self($this->executor, $this->table, $state, $this->compiler);
    }
}
