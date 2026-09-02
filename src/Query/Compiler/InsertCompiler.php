<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Compiler;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\State\InsertState;

final class InsertCompiler
{
    public function __construct(private readonly ExpressionCompiler $expressions = new ExpressionCompiler())
    {
    }

    public function compile(InsertState $state, Dialect $dialect, ?BindCollector $binds = null): CompiledQuery
    {
        if ($state->rows === []) {
            throw new QueryException(
                "INSERT em {$state->into->name} sem nenhuma linha. `VALUES ()` não é SQL, e "
                . 'quem chega aqui quase sempre iterou uma coleção que veio vazia — caso que '
                . 'precisa ser tratado antes, não virar uma query que o banco recusa.',
            );
        }

        $binds ??= new BindCollector();

        $columns = implode(', ', array_map(
            static fn (string $column): string => $dialect->quoteIdentifier($column),
            $state->columns,
        ));

        $rows = array_map(
            fn (array $row): string => '(' . implode(', ', array_map(
                fn (Expression $value): string => $this->expressions->compile($value, $dialect, $binds),
                $row,
            )) . ')',
            $state->rows,
        );

        $sql = 'INSERT INTO ' . $dialect->quoteIdentifier($state->into->name)
            . ' (' . $columns . ') VALUES ' . implode(', ', $rows);

        if ($state->returning !== null) {
            $sql .= ' ' . $this->returning($state, $dialect);
        }

        return new CompiledQuery($sql, $binds->all());
    }

    /**
     * RETURNING onde existe; erro claro onde não existe.
     *
     * Omitir em silêncio seria pior: quem pediu as linhas de volta receberia nenhuma e
     * concluiria que o INSERT não gravou. Quem chama é que decide o contorno — no MySQL,
     * um segundo SELECT por `lastInsertId()` —, e para isso precisa saber que não dá.
     */
    private function returning(InsertState $state, Dialect $dialect): string
    {
        if (!$dialect->supportsReturning()) {
            throw new QueryException(
                "O dialeto {$dialect->name()} não tem RETURNING no INSERT. Quem precisa da "
                . 'linha gravada de volta usa returningOne(), que faz o SELECT complementar '
                . 'por lastInsertId(); em lote, só execute().',
            );
        }

        return 'RETURNING ' . implode(', ', array_map(
            static fn (string $column): string => $dialect->quoteIdentifier($column),
            $state->returning ?? [],
        ));
    }
}
