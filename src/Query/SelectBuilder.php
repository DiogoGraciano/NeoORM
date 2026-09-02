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
 * A consulta tipada: devolve linhas da tabela do `from()`.
 *
 * Imutável. Cada cláusula devolve instância nova, e é o que faz este idioma funcionar:
 *
 * ```php
 * $ativos = $db->select()->from($u)->where(eq($u->status, 'ativo'));
 * $pagina = $ativos->limit(10)->all();
 * $total  = $ativos->count();          // $ativos intacto
 * ```
 *
 * O builder antigo acumulava filtros em `$this` e precisava de `clean()` depois de cada
 * execução — e uma exceção no meio deixava a consulta seguinte com os filtros da
 * anterior. Aqui não existe estado para vazar.
 *
 * O tipo é introduzido por `from()`, não por `select()`: quando `select()` é chamado
 * ainda não se sabe qual é a tabela.
 *
 * @template TRow of object
 */
final readonly class SelectBuilder
{
    use BuildsSelectClauses;

    /**
     * @param Table<TRow>|null $table
     */
    public function __construct(
        private Executor $executor,
        private SelectState $selectState = new SelectState(),
        private ?Table $table = null,
        private Compiler $compiler = new Compiler(),
    ) {
    }

    /**
     * @template TNewRow of object
     * @param Table<TNewRow> $table
     * @return self<TNewRow>
     */
    public function from(Table $table): self
    {
        return new self($this->executor, $this->selectState->withFrom($table->toRef()), $table, $this->compiler);
    }

    /**
     * @return list<TRow>
     */
    public function all(): array
    {
        $table = $this->requireTable();
        $statement = $this->executor->run($this->toSql());

        $rows = [];

        /** @var array<string,mixed> $raw */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $rows[] = $table->hydrate($raw);
        }

        return $rows;
    }

    /**
     * @return TRow|null
     */
    public function one(): ?object
    {
        // `limit(1)` no SQL, e não `array_slice` depois: sem isso, um `one()` sobre uma
        // tabela grande traz tudo pela rede para descartar o resto.
        $rows = $this->limit(1)->all();

        return $rows[0] ?? null;
    }

    /**
     * @return TRow
     */
    public function oneOrFail(): object
    {
        $row = $this->one();

        if ($row === null) {
            throw new QueryException(
                'A consulta em ' . $this->requireTable()->tableName() . ' não devolveu linha nenhuma.',
            );
        }

        return $row;
    }

    /**
     * Percorre o resultado linha a linha, sem carregar tudo na memória.
     *
     * @return \Generator<int,TRow>
     */
    public function cursor(): \Generator
    {
        $table = $this->requireTable();
        $statement = $this->executor->run($this->toSql());

        while (true) {
            /** @var array<string,mixed>|false $raw */
            $raw = $statement->fetch(PDO::FETCH_ASSOC);

            if ($raw === false) {
                break;
            }

            yield $table->hydrate($raw);
        }
    }

    /**
     * O total, ignorando paginação.
     *
     * Contar uma consulta limitada a 10 devolveria no máximo 10, e quem pagina quer o
     * total. A ordenação também sai: não muda a contagem e custa no plano.
     */
    public function count(): int
    {
        $statement = $this->executor->run(
            $this->compiler->compileCount($this->selectState, $this->executor->dialect()),
        );

        return (int) $statement->fetchColumn();
    }

    /**
     * O SQL e os binds, sem executar. Pública para depuração e para teste.
     */
    public function toSql(): CompiledQuery
    {
        return $this->compiler->compileSelect($this->selectState, $this->executor->dialect());
    }

    /**
     * @return Table<TRow>
     */
    private function requireTable(): Table
    {
        if ($this->table === null) {
            throw new QueryException(
                'Consulta sem from(). É o from() que define a tabela e, com ela, o tipo da linha '
                . 'devolvida.',
            );
        }

        return $this->table;
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
        return new self($this->executor, $state, $this->table, $this->compiler);
    }
}
