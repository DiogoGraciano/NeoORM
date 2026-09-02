<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Compiler;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\State\DeleteState;

final class DeleteCompiler
{
    public function __construct(private readonly ExpressionCompiler $expressions = new ExpressionCompiler())
    {
    }

    public function compile(DeleteState $state, Dialect $dialect, ?BindCollector $binds = null): CompiledQuery
    {
        if ($state->where === null && !$state->allowFullTableScan) {
            throw new QueryException(
                "DELETE em {$state->from->name} sem WHERE apagaria a tabela inteira. Se é mesmo "
                . 'isso, declare com allowFullTableScan().',
            );
        }

        $binds ??= new BindCollector();

        $sql = 'DELETE FROM ' . $dialect->quoteIdentifier($state->from->name);

        if ($state->where !== null) {
            $sql .= ' WHERE ' . $this->expressions->compile($state->where, $dialect, $binds);
        }

        if ($state->returning !== null) {
            if (!$dialect->supportsReturningOnModify()) {
                throw new QueryException(
                    "O dialeto {$dialect->name()} não tem RETURNING no DELETE. Leia as linhas "
                    . 'antes de apagar, dentro da mesma transação.',
                );
            }

            $sql .= ' RETURNING ' . implode(', ', array_map(
                static fn (string $column): string => $dialect->quoteIdentifier($column),
                $state->returning,
            ));
        }

        return new CompiledQuery($sql, $binds->all());
    }
}
