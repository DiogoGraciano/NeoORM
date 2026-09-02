<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Query\Compiler\Compiler;
use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use Diogodg\Neoorm\Query\Concerns\BuildsSelectClauses;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\State\SelectState;
use PDO;

/**
 * A consulta de colunas soltas: devolve arrays, não linhas tipadas.
 *
 * É a concessão que o PHP impõe. O Drizzle infere `{id: number}` de
 * `.select({id: users.id})` porque o TypeScript tem tipo estrutural; o PHP não tem, e
 * não existe forma de declarar "array com estas chaves e estes tipos" que o runtime
 * verifique. Então seleção parcial devolve `array<string,mixed>`, e isso está dito no
 * tipo em vez de escondido.
 *
 * Classe separada, e não um modo do `SelectBuilder`, porque um `from()` não consegue ao
 * mesmo tempo reescrever o tipo da linha e preservá-lo como array.
 */
final readonly class FieldSelectBuilder
{
    use BuildsSelectClauses;

    public function __construct(
        private Executor $executor,
        private SelectState $selectState = new SelectState(),
        private Compiler $compiler = new Compiler(),
    ) {
    }

    /**
     * @param list<\Diogodg\Neoorm\Query\Expr\Expression> $columns
     */
    public function withColumns(array $columns): self
    {
        return new self($this->executor, $this->selectState->withColumns($columns), $this->compiler);
    }

    /**
     * @param Table<object> $table
     */
    public function from(Table $table): self
    {
        return new self($this->executor, $this->selectState->withFrom($table->toRef()), $this->compiler);
    }

    /**
     * @return list<array<string,mixed>>
     */
    public function all(): array
    {
        /** @var list<array<string,mixed>> */
        return $this->executor->run($this->toSql())->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * @return array<string,mixed>|null
     */
    public function one(): ?array
    {
        return $this->limit(1)->all()[0] ?? null;
    }

    /**
     * A primeira coluna de cada linha.
     *
     * @return list<mixed>
     */
    public function column(): array
    {
        /** @var list<mixed> */
        return $this->executor->run($this->toSql())->fetchAll(PDO::FETCH_COLUMN, 0);
    }

    /**
     * Um valor só — o caso de `COUNT(*)`, `MAX(...)` e afins.
     */
    public function scalar(): mixed
    {
        $value = $this->executor->run($this->limit(1)->toSql())->fetchColumn();

        return $value === false ? null : $value;
    }

    public function toSql(): CompiledQuery
    {
        if ($this->selectState->columns === []) {
            throw new QueryException(
                'selectFields() sem colunas. Para trazer a linha inteira e tipada use select()->from().',
            );
        }

        return $this->compiler->compileSelect($this->selectState, $this->executor->dialect());
    }

    protected function state(): SelectState
    {
        return $this->selectState;
    }

    /**
     * @return static
     */
    protected function withState(SelectState $state): static
    {
        return new self($this->executor, $state, $this->compiler);
    }
}
