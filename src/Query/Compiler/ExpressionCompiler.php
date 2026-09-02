<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Compiler;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\Expr\Aliased;
use Diogodg\Neoorm\Query\Expr\Between;
use Diogodg\Neoorm\Query\Expr\ColumnRef;
use Diogodg\Neoorm\Query\Expr\Comparison;
use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\FuncCall;
use Diogodg\Neoorm\Query\Expr\InList;
use Diogodg\Neoorm\Query\Expr\Logical;
use Diogodg\Neoorm\Query\Expr\NotExpr;
use Diogodg\Neoorm\Query\Expr\NullCheck;
use Diogodg\Neoorm\Query\Expr\Sql;
use Diogodg\Neoorm\Query\Expr\Value;
use Diogodg\Neoorm\Runtime\Casting\Binder;

/**
 * Traduz a árvore de expressão para SQL.
 *
 * Um arquivo só, e um `match` só: a superfície SQL inteira cabe numa tela, e um nó
 * novo sem braço no `match` lança em vez de sumir do SQL — que é a falha silenciosa
 * que um `if/else` encadeado produziria.
 *
 * O compilador não conhece dialeto: ele PEDE ao dialeto. Nenhum `if ($driver === …)`
 * aqui dentro, e é o que permite compilar a mesma árvore contra os dois bancos e
 * comparar o resultado.
 */
final class ExpressionCompiler
{
    public function compile(Expression $expression, Dialect $dialect, BindCollector $binds): string
    {
        return match (true) {
            $expression instanceof ColumnRef => $this->column($expression, $dialect),
            $expression instanceof Value => $this->value($expression, $dialect, $binds),
            $expression instanceof Sql => $this->raw($expression, $dialect, $binds),
            $expression instanceof Comparison => $this->comparison($expression, $dialect, $binds),
            $expression instanceof InList => $this->inList($expression, $dialect, $binds),
            $expression instanceof Between => $this->between($expression, $dialect, $binds),
            $expression instanceof NullCheck => $this->nullCheck($expression, $dialect, $binds),
            $expression instanceof Logical => $this->logical($expression, $dialect, $binds),
            $expression instanceof NotExpr => $this->not($expression, $dialect, $binds),
            $expression instanceof FuncCall => $this->function($expression, $dialect, $binds),
            $expression instanceof Aliased => $this->aliased($expression, $dialect, $binds),
            default => throw new QueryException(
                'Nó de expressão sem tradução: ' . $expression::class
                . '. Todo nó precisa de um braço em ExpressionCompiler::compile().',
            ),
        };
    }

    /**
     * @param ColumnRef<mixed> $column
     */
    public function column(ColumnRef $column, Dialect $dialect): string
    {
        return $dialect->quoteIdentifier($column->qualifier)
            . '.' . $dialect->quoteIdentifier($column->name);
    }

    /**
     * O valor passa pelo `Binder` antes de virar bind.
     *
     * É o único ponto do compilador em que o dialeto muda o VALOR e não só a grafia.
     * Sem isto, `eq($u->active, true)` mandaria `PARAM_BOOL` ao MySQL — que, com a
     * emulação de prepared statements desligada, grava string vazia para `false`: a
     * linha entra, sem erro, com o valor errado. O mesmo caminho faz `DateTimeImmutable`
     * sair no formato da coluna e `BackedEnum` sair como o valor de trás.
     */
    private function value(Value $value, Dialect $dialect, BindCollector $binds): string
    {
        [$bound, $type] = (new Binder($dialect))->bind($value->value, $value->columnType);

        return $binds->add($bound, $type);
    }

    /**
     * Substitui cada `?` do fragmento pelo placeholder do bind correspondente.
     *
     * O fragmento em si vai para o SQL sem passar por nada — é a via de escape, e o
     * contrato é que quem a usa responde pelo texto. Os valores, não: continuam virando
     * bind como em qualquer outro nó.
     */
    private function raw(Sql $sql, Dialect $dialect, BindCollector $binds): string
    {
        if ($sql->binds === []) {
            return $sql->fragment;
        }

        $pieces = explode('?', $sql->fragment);
        $result = $pieces[0];

        foreach ($sql->binds as $index => $bind) {
            $result .= $this->compile($bind, $dialect, $binds) . $pieces[$index + 1];
        }

        return $result;
    }

    private function comparison(Comparison $comparison, Dialect $dialect, BindCollector $binds): string
    {
        $left = $this->compile($comparison->left, $dialect, $binds);
        $right = $this->compile($comparison->right, $dialect, $binds);

        // ILIKE é o único operador cujo SQL não é o próprio valor do enum: o
        // PostgreSQL tem o operador, o MySQL precisa de LOWER() dos dois lados.
        if ($comparison->operator->isCaseInsensitive()) {
            return $dialect->caseInsensitiveLike($left, $right);
        }

        return $left . ' ' . $comparison->operator->value . ' ' . $right;
    }

    private function inList(InList $inList, Dialect $dialect, BindCollector $binds): string
    {
        $left = $this->compile($inList->left, $dialect, $binds);
        $operator = $inList->negated ? 'NOT IN' : 'IN';

        if ($inList->values instanceof Expression) {
            return $left . ' ' . $operator . ' (' . $this->compile($inList->values, $dialect, $binds) . ')';
        }

        $items = array_map(
            fn (Expression $value): string => $this->compile($value, $dialect, $binds),
            $inList->values,
        );

        return $left . ' ' . $operator . ' (' . implode(', ', $items) . ')';
    }

    private function between(Between $between, Dialect $dialect, BindCollector $binds): string
    {
        return $this->compile($between->operand, $dialect, $binds)
            . ($between->negated ? ' NOT BETWEEN ' : ' BETWEEN ')
            . $this->compile($between->low, $dialect, $binds)
            . ' AND '
            . $this->compile($between->high, $dialect, $binds);
    }

    private function nullCheck(NullCheck $check, Dialect $dialect, BindCollector $binds): string
    {
        return $this->compile($check->operand, $dialect, $binds)
            . ($check->negated ? ' IS NOT NULL' : ' IS NULL');
    }

    /**
     * Sempre entre parênteses quando há mais de um operando.
     *
     * A árvore guarda estrutura, não parênteses. Emitir o parêntese é o que faz a
     * estrutura sobreviver à tradução: sem ele, `a OR b` dentro de um `AND` mudaria de
     * significado por precedência.
     */
    private function logical(Logical $logical, Dialect $dialect, BindCollector $binds): string
    {
        $parts = array_map(
            fn (Expression $operand): string => $this->compile($operand, $dialect, $binds),
            $logical->operands,
        );

        if (count($parts) === 1) {
            return $parts[0];
        }

        return '(' . implode(' ' . $logical->connective->value . ' ', $parts) . ')';
    }

    private function not(NotExpr $not, Dialect $dialect, BindCollector $binds): string
    {
        return 'NOT (' . $this->compile($not->operand, $dialect, $binds) . ')';
    }

    private function function(FuncCall $call, Dialect $dialect, BindCollector $binds): string
    {
        if ($call->isStar()) {
            return $call->function->value . '(*)';
        }

        $arguments = array_map(
            fn (Expression $argument): string => $this->compile($argument, $dialect, $binds),
            $call->arguments,
        );

        return $call->function->value
            . '(' . ($call->distinct ? 'DISTINCT ' : '') . implode(', ', $arguments) . ')';
    }

    private function aliased(Aliased $aliased, Dialect $dialect, BindCollector $binds): string
    {
        return $this->compile($aliased->expression, $dialect, $binds)
            . ' AS ' . $dialect->quoteIdentifier($aliased->alias);
    }
}
