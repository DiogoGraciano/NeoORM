<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Compiler;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\Expr\Aliased;
use Diogodg\Neoorm\Query\Expr\ColumnRef;
use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\NullsPlacement;
use Diogodg\Neoorm\Query\Expr\OrderDirection;
use Diogodg\Neoorm\Query\Expr\OrderTerm;
use Diogodg\Neoorm\Query\State\JoinClause;
use Diogodg\Neoorm\Query\State\SelectState;
use Diogodg\Neoorm\Query\State\TableRef;
use PDO;

/**
 * Monta o SELECT.
 *
 * As cláusulas são compiladas na ordem em que aparecem no texto, e isso importa: os
 * placeholders são alocados durante a compilação, então percorrer na ordem textual faz
 * `:p0` ser o primeiro bind que se lê no SQL. Facilita depurar e torna o golden file
 * legível.
 */
final class SelectCompiler
{
    public function __construct(private readonly ExpressionCompiler $expressions = new ExpressionCompiler())
    {
    }

    public function compile(SelectState $state, Dialect $dialect, ?BindCollector $binds = null): CompiledQuery
    {
        if ($state->from === null) {
            throw new QueryException(
                'SELECT sem FROM. A tabela entra pelo from(), e é ela que define o tipo da '
                . 'linha devolvida.',
            );
        }

        $binds ??= new BindCollector();

        $sql = 'SELECT ' . ($state->distinct ? 'DISTINCT ' : '')
            . $this->columns($state, $dialect, $binds)
            . ' FROM ' . $this->table($state->from, $dialect);

        foreach ($state->joins as $join) {
            $sql .= ' ' . $this->join($join, $dialect, $binds);
        }

        if ($state->where !== null) {
            $sql .= ' WHERE ' . $this->expressions->compile($state->where, $dialect, $binds);
        }

        if ($state->groupBy !== []) {
            $sql .= ' GROUP BY ' . $this->list($state->groupBy, $dialect, $binds);
        }

        if ($state->having !== null) {
            $sql .= ' HAVING ' . $this->expressions->compile($state->having, $dialect, $binds);
        }

        if ($state->orderBy !== []) {
            $sql .= ' ORDER BY ' . $this->orderBy($state->orderBy, $dialect, $binds);
        }

        $pagination = $dialect->limitOffsetClause(
            $state->limit === null ? null : $binds->add($state->limit, PDO::PARAM_INT),
            $state->offset === null ? null : $binds->add($state->offset, PDO::PARAM_INT),
        );

        if ($pagination !== '') {
            $sql .= ' ' . $pagination;
        }

        return new CompiledQuery($sql, $binds->all());
    }

    /**
     * Lista vazia significa "todas as colunas da tabela base", e sai qualificada:
     * `"users".*`, nunca `*`.
     *
     * A diferença aparece com join: `SELECT *` traria também as colunas da tabela
     * juntada, que colidiriam com as da base no array associativo devolvido pelo PDO —
     * e o resultado seria hidratar a linha com valores da tabela errada.
     */
    private function columns(SelectState $state, Dialect $dialect, BindCollector $binds): string
    {
        assert($state->from !== null);

        if ($state->columns === []) {
            return $dialect->quoteIdentifier($state->from->qualifier()) . '.*';
        }

        $compiled = array_map(
            fn (Expression $column): string => $this->expressions->compile(
                $this->disambiguate($column, $state->from, $state->joins !== []),
                $dialect,
                $binds,
            ),
            $state->columns,
        );

        return implode(', ', $compiled);
    }

    /**
     * Dá alias a coluna de tabela juntada, no formato `tabela__coluna`.
     *
     * O PDO devolve as linhas em array associativo, então `users.id` e `posts.id`
     * escrevem a MESMA chave `id` e a última vence — em silêncio, sem erro, com o
     * valor da tabela errada. Não há como o consumidor perceber.
     *
     * Só mexe em `ColumnRef` cru de tabela que não é a base: quem escreveu um alias
     * explícito já decidiu o nome, e expressão composta não tem nome para colidir.
     */
    private function disambiguate(Expression $column, TableRef $from, bool $hasJoins): Expression
    {
        if (!$hasJoins || !$column instanceof ColumnRef) {
            return $column;
        }

        if ($column->qualifier === $from->qualifier()) {
            return $column;
        }

        return new Aliased($column, $column->qualifier . '__' . $column->name);
    }

    private function table(TableRef $table, Dialect $dialect): string
    {
        $sql = $dialect->quoteIdentifier($table->name);

        return $table->alias === null
            ? $sql
            : $sql . ' AS ' . $dialect->quoteIdentifier($table->alias);
    }

    private function join(JoinClause $join, Dialect $dialect, BindCollector $binds): string
    {
        return $join->type->value . ' ' . $this->table($join->table, $dialect)
            . ' ON ' . $this->expressions->compile($join->on, $dialect, $binds);
    }

    /**
     * @param list<Expression> $expressions
     */
    private function list(array $expressions, Dialect $dialect, BindCollector $binds): string
    {
        return implode(', ', array_map(
            fn (Expression $expression): string => $this->expressions->compile($expression, $dialect, $binds),
            $expressions,
        ));
    }

    /**
     * @param list<OrderTerm> $terms
     */
    private function orderBy(array $terms, Dialect $dialect, BindCollector $binds): string
    {
        $compiled = [];

        foreach ($terms as $term) {
            if ($term->nulls !== null && !$dialect->supportsNullsPlacement()) {
                // Emulação para quem não tem NULLS FIRST/LAST: uma chave extra antes da
                // real. `x IS NULL` dá 0 ou 1, então ordená-la DESC traz os nulos na
                // frente e ASC os manda para o fim. A expressão é compilada de novo, e
                // não reutilizada como texto: se ela contém bind, cada ocorrência no SQL
                // precisa do seu próprio placeholder com prepared statements nativos.
                $nullKey = $this->expressions->compile($term->expression, $dialect, $binds);
                $compiled[] = $nullKey . ' IS NULL '
                    . ($term->nulls === NullsPlacement::First
                        ? OrderDirection::Desc->value
                        : OrderDirection::Asc->value);
            }

            $expression = $this->expressions->compile($term->expression, $dialect, $binds);
            $sql = $expression . ' ' . $term->direction->value;

            if ($term->nulls !== null && $dialect->supportsNullsPlacement()) {
                $sql .= ' NULLS ' . $term->nulls->value;
            }

            $compiled[] = $sql;
        }

        return implode(', ', $compiled);
    }
}
