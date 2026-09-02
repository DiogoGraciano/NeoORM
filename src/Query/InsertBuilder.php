<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Query\Compiler\Compiler;
use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use Diogodg\Neoorm\Query\Concerns\HydratesRows;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\Sql;
use Diogodg\Neoorm\Query\Expr\Value;
use Diogodg\Neoorm\Query\State\InsertState;

use function Diogodg\Neoorm\Query\eq;

/**
 * O INSERT.
 *
 * @template TRow of object
 */
final readonly class InsertBuilder
{
    use HydratesRows;

    /**
     * @param Table<TRow> $table
     * @param list<array<string,mixed>> $payloads
     */
    public function __construct(
        private Executor $executor,
        private Table $table,
        private array $payloads = [],
        private Compiler $compiler = new Compiler(),
    ) {
    }

    /**
     * Aceita o DTO gerado ou um array de coluna => valor.
     *
     * Várias chamadas acumulam, o que dá o INSERT de várias linhas.
     *
     * @param object|array<string,mixed> ...$payloads
     * @return self<TRow>
     */
    public function values(object|array ...$payloads): self
    {
        $normalized = $this->payloads;

        foreach ($payloads as $payload) {
            $normalized[] = $this->toColumns($payload);
        }

        return new self($this->executor, $this->table, $normalized, $this->compiler);
    }

    /**
     * Executa e devolve quantas linhas entraram.
     */
    public function execute(): int
    {
        return $this->executor->run($this->toSql())->rowCount();
    }

    /**
     * A linha gravada, já hidratada.
     *
     * No PostgreSQL sai num round-trip, por `RETURNING`. No MySQL, que não tem
     * `RETURNING`, custa um segundo SELECT por `lastInsertId()` — e é por isso que o
     * método existe separado de `execute()`: o custo fica visível na chamada em vez de
     * escondido num N+1.
     *
     * @return TRow
     */
    public function returningOne(): object
    {
        if (count($this->payloads) !== 1) {
            throw new QueryException(
                'returningOne() espera exatamente uma linha; recebeu ' . count($this->payloads) . '.',
            );
        }

        if ($this->executor->dialect()->supportsReturning()) {
            return $this->returning()[0];
        }

        $this->executor->run($this->toSql());

        return $this->readBackByLastInsertId();
    }

    /**
     * As linhas gravadas.
     *
     * Só onde há `RETURNING`. No MySQL, `lastInsertId()` devolve apenas o id da PRIMEIRA
     * linha, e a contiguidade dos seguintes depende de `innodb_autoinc_lock_mode` — cedo
     * ou tarde daria a linha errada. Recusar é melhor que acertar quase sempre.
     *
     * @return list<TRow>
     */
    public function returning(): array
    {
        $dialect = $this->executor->dialect();

        if (!$dialect->supportsReturning()) {
            throw new QueryException(
                "O dialeto {$dialect->name()} não tem RETURNING. Para uma linha use "
                . 'returningOne(), que faz o SELECT complementar; para várias, use execute() '
                . 'e releia com um select().',
            );
        }

        $state = $this->state()->withReturning($this->table->columnNames());
        $statement = $this->executor->run($this->compiler->compileInsert($state, $dialect));

        return $this->hydrateRows($statement, $this->table);
    }

    public function toSql(): CompiledQuery
    {
        return $this->compiler->compileInsert($this->state(), $this->executor->dialect());
    }

    private function state(): InsertState
    {
        if ($this->payloads === []) {
            throw new QueryException(
                "INSERT em {$this->table->tableName()} sem values(). Um INSERT vazio quase sempre "
                . 'é uma coleção que veio vazia — caso que precisa ser tratado antes.',
            );
        }

        // A lista é a união estável das colunas informadas. Cada ausência vira DEFAULT
        // na posição correspondente; os dois dialetos aceitam a keyword dentro de
        // VALUES. Assim DTOs que omitiram opcionais diferentes continuam num único lote
        // sem deslocar valor para a coluna errada.
        $columns = [];

        foreach ($this->payloads as $payload) {
            $missing = array_values(array_diff($this->table->requiredInsertColumns(), array_keys($payload)));

            if ($missing !== []) {
                throw new QueryException(
                    "INSERT em {$this->table->tableName()} sem coluna(s) obrigatória(s): "
                    . implode(', ', $missing) . '.',
                );
            }

            foreach (array_keys($payload) as $column) {
                $this->table->columnRef($column); // valida antes de montar qualquer SQL

                if (!in_array($column, $columns, true)) {
                    $columns[] = $column;
                }
            }
        }

        $rows = [];

        foreach ($this->payloads as $payload) {
            $values = [];

            foreach ($columns as $column) {
                $values[] = array_key_exists($column, $payload)
                    ? $this->valueFor($column, $payload[$column])
                    : new Sql('DEFAULT');
            }

            $rows[] = $values;
        }

        return new InsertState($this->table->toRef(), $columns, $rows);
    }

    /**
     * @param object|array<string,mixed> $payload
     * @return array<string,mixed>
     */
    private function toColumns(object|array $payload): array
    {
        if (is_array($payload)) {
            return $payload;
        }

        if (method_exists($payload, 'toColumns')) {
            /** @var array<string,mixed> */
            return $payload->toColumns();
        }

        throw new QueryException(
            'values() aceita o DTO de INSERT gerado ou um array coluna => valor. Recebeu '
            . $payload::class . ', que não tem toColumns().',
        );
    }

    private function valueFor(string $column, mixed $value): Expression
    {
        if ($value instanceof Expression) {
            return $value;
        }

        return new Value($value, columnType: $this->table->columnRef($column)->type);
    }

    /**
     * @return TRow
     */
    private function readBackByLastInsertId(): object
    {
        // A coluna vem da tabela gerada, não da posição. `columnNames()[0]` acertava
        // enquanto todo model começasse com `Col::id()`, e numa tabela que não começa
        // relia por uma coluna qualquer — devolvendo a linha errada, sem erro nenhum.
        $primary = $this->table->autoIncrementColumn();

        if ($primary === null) {
            throw new QueryException(
                "A tabela {$this->table->tableName()} não tem coluna auto incremento, então não "
                . 'há id gerado para reler a linha por ele. Use execute() e leia a linha pelos '
                . 'valores que você mesmo informou.',
            );
        }

        $id = $this->executor->lastInsertId();

        // O MySQL devolve a string '0' — não `false` — quando o statement não gerou id
        // nenhum. Sem tratar as duas formas, o `WHERE` sairia com zero e a releitura
        // falharia lá na frente, com uma mensagem sobre a linha não existir.
        if ($id === false || $id === '' || $id === '0') {
            throw new QueryException(
                "Não foi possível recuperar o id gerado em {$this->table->tableName()}: o banco "
                . "não reportou valor para a coluna auto incremento '{$primary}'.",
            );
        }

        $row = $this->executor->select()
            ->from($this->table)
            ->where(eq($this->table->columnRef($primary), $id))
            ->one();

        if ($row === null) {
            throw new QueryException(
                "O INSERT em {$this->table->tableName()} gravou, mas a releitura por id não "
                . 'encontrou a linha.',
            );
        }

        return $row;
    }
}
