<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use Diogodg\Neoorm\Query\Expr\ColumnRef;
use Diogodg\Neoorm\Query\Expr\NullsPlacement;
use Diogodg\Neoorm\Query\Expr\Value;
use Diogodg\Neoorm\Query\Func;
use Diogodg\Neoorm\Query\Op;
use Diogodg\Neoorm\Query\State\DeleteState;
use Diogodg\Neoorm\Query\State\InsertState;
use Diogodg\Neoorm\Query\State\JoinClause;
use Diogodg\Neoorm\Query\State\JoinType;
use Diogodg\Neoorm\Query\State\SelectState;
use Diogodg\Neoorm\Query\State\TableRef;
use Diogodg\Neoorm\Query\State\UpdateState;
use Diogodg\Neoorm\Schema\Type\TypeSpec;

use function Diogodg\Neoorm\Query\asc;
use function Diogodg\Neoorm\Query\between;
use function Diogodg\Neoorm\Query\desc;
use function Diogodg\Neoorm\Query\eq;
use function Diogodg\Neoorm\Query\gt;
use function Diogodg\Neoorm\Query\ilike;
use function Diogodg\Neoorm\Query\inArray;
use function Diogodg\Neoorm\Query\isNull;
use function Diogodg\Neoorm\Query\like;
use function Diogodg\Neoorm\Query\sql;

/**
 * Estados de consulta prontos, com rótulo.
 *
 * Mesma ideia de `Factory\Ops` para as operações de schema: o catálogo é a lista do
 * que a camada precisa saber compilar, e um caso novo entra automaticamente no golden
 * file dos dois dialetos — sem depender de alguém lembrar de acrescentá-lo em cada
 * arquivo de teste.
 *
 * Nenhuma regra de negócio aqui: só formas de SQL.
 */
final class Queries
{
    private function __construct()
    {
    }

    public static function users(?string $alias = null): TableRef
    {
        return new TableRef('users', $alias);
    }

    public static function posts(?string $alias = null): TableRef
    {
        return new TableRef('posts', $alias);
    }

    public static function column(string $table, string $name, string $type = 'INT'): ColumnRef
    {
        return new ColumnRef($table, $name, TypeSpec::parse($type), notNull: true);
    }

    /**
     * O catálogo compilado pelo golden file, em ordem estável.
     *
     * @return array<string,SelectState|InsertState|UpdateState|DeleteState>
     */
    public static function catalog(): array
    {
        $id = self::column('users', 'id');
        $name = self::column('users', 'name', 'VARCHAR');
        $age = self::column('users', 'age');
        $deleted = self::column('users', 'deleted_at', 'TIMESTAMP');
        $postId = self::column('posts', 'id');
        $author = self::column('posts', 'author_id');
        $title = self::column('posts', 'title', 'VARCHAR');

        return [
            'select_all' => (new SelectState())->withFrom(self::users()),

            'select_columns' => (new SelectState())
                ->withFrom(self::users())
                ->withColumns([$id, $name]),

            'select_where' => (new SelectState())
                ->withFrom(self::users())
                ->withWhere(Op::and(gt($age, 18), eq($name, 'Diogo'))),

            'select_or_and_not' => (new SelectState())
                ->withFrom(self::users())
                ->withWhere(Op::and(
                    Op::or(eq($age, 18), eq($age, 21)),
                    Op::not(isNull($deleted)),
                )),

            'select_in_between_like' => (new SelectState())
                ->withFrom(self::users())
                ->withWhere(Op::and(
                    inArray($id, [1, 2, 3]),
                    between($age, 18, 65),
                    like($name, 'D%'),
                )),

            'select_case_insensitive' => (new SelectState())
                ->withFrom(self::users())
                ->withWhere(ilike($name, 'd%')),

            'select_join_disambiguates' => (new SelectState())
                ->withFrom(self::users())
                ->withColumns([$id, $postId, $title])
                ->withJoin(new JoinClause(JoinType::Left, self::posts(), eq($author, $id))),

            'select_alias' => (new SelectState())
                ->withFrom(self::users('autor'))
                ->withColumns([self::column('autor', 'id'), self::column('autor', 'name', 'VARCHAR')]),

            'select_group_having' => (new SelectState())
                ->withFrom(self::users())
                ->withColumns([$age, Func::count()])
                ->withGroupBy([$age])
                ->withHaving(gt(Func::count(), 1)),

            'select_aggregates' => (new SelectState())
                ->withFrom(self::users())
                ->withColumns([
                    Func::count($id, distinct: true),
                    Func::sum($age),
                    Func::coalesce($name, 'sem nome'),
                ]),

            'select_order_nulls' => (new SelectState())
                ->withFrom(self::users())
                ->withOrderBy([desc($age, NullsPlacement::Last), asc($name)]),

            'select_pagination' => (new SelectState())
                ->withFrom(self::users())
                ->withWhere(eq($name, 'Diogo'))
                ->withLimit(10)
                ->withOffset(20),

            'select_offset_only' => (new SelectState())
                ->withFrom(self::users())
                ->withOffset(20),

            'select_distinct' => (new SelectState())
                ->withFrom(self::users())
                ->withColumns([$age])
                ->withDistinct(),

            'select_subquery_in' => (new SelectState())
                ->withFrom(self::users())
                ->withWhere(inArray($id, sql('SELECT author_id FROM posts WHERE published = ?', true))),

            'select_raw_fragment' => (new SelectState())
                ->withFrom(self::users())
                ->withWhere(sql('EXTRACT(YEAR FROM ?) = ?', $deleted, 2026)),

            'insert_single' => new InsertState(
                self::users(),
                ['name', 'age'],
                [[new Value('Diogo'), new Value(38)]],
            ),

            'insert_multi' => new InsertState(
                self::users(),
                ['name', 'age'],
                [
                    [new Value('Diogo'), new Value(38)],
                    [new Value('Ana'), new Value(29)],
                ],
            ),

            'update_where' => (new UpdateState(self::users()))
                ->withAssignments(['name' => new Value('Novo'), 'age' => new Value(40)])
                ->withWhere(eq($id, 1)),

            'update_full_table' => (new UpdateState(self::users()))
                ->withAssignments(['age' => new Value(0)])
                ->withFullTableScan(),

            'delete_where' => (new DeleteState(self::users()))->withWhere(eq($id, 1)),

            'delete_full_table' => (new DeleteState(self::users()))->withFullTableScan(),
        ];
    }

    /**
     * Casos que só um dos dialetos aceita, compilados à parte pelo golden.
     *
     * @return array<string,InsertState|UpdateState|DeleteState>
     */
    public static function returningVariants(): array
    {
        return [
            'insert_returning' => new InsertState(
                self::users(),
                ['name'],
                [[new Value('Diogo')]],
                ['id', 'name'],
            ),

            'update_returning' => (new UpdateState(self::users()))
                ->withAssignments(['name' => new Value('Novo')])
                ->withWhere(eq(self::column('users', 'id'), 1))
                ->withReturning(['id', 'name']),

            'delete_returning' => (new DeleteState(self::users()))
                ->withWhere(eq(self::column('users', 'id'), 1))
                ->withReturning(['id']),
        ];
    }
}
