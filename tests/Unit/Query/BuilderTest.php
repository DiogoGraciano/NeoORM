<?php

declare(strict_types=1);

namespace Tests\Unit\Query;

use DateTimeImmutable;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Query\Database;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\Func;
use Diogodg\Neoorm\Query\Tx;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Golden\Codegen\Enums\UsersStatus;
use Tests\Golden\Codegen\Inserts\UsersInsert;
use Tests\Golden\Codegen\Rows\UsersRow;
use Tests\Golden\Codegen\Tables;
use Tests\Support\Concerns\DialectProviders;
use Tests\Support\Doubles\FakePdo;
use Tests\Support\UnitTestCase;

use function Diogodg\Neoorm\Query\eq;
use function Diogodg\Neoorm\Query\gt;

/**
 * Os builders, contra um PDO falso.
 *
 * O que se testa aqui é o que só o caminho de EXECUÇÃO prova: hidratação, tipo de bind
 * efetivamente aplicado, número e ordem dos statements. O compilador já foi testado
 * separadamente e só devolve texto.
 *
 * As classes geradas vêm de `tests/Golden/Codegen` — as mesmas commitadas pelo teste do
 * gerador. Usá-las aqui faz este teste também provar que o código gerado funciona de
 * verdade, e não só que parseia.
 */
final class BuilderTest extends UnitTestCase
{
    use DialectProviders;

    /**
     * @param list<list<array<string,mixed>>> $resultSets
     */
    private function db(string $dialect, array $resultSets = [], string|false $lastId = '7'): array
    {
        $pdo = new FakePdo($resultSets, $lastId);

        return [new Database($pdo, DialectFactory::for($dialect)), $pdo];
    }

    /**
     * @return array<string,mixed>
     */
    private static function rawRow(int $id = 7): array
    {
        return [
            'id' => $id,
            'name' => 'Diogo',
            'phone' => null,
            'balance' => '10.50',
            'active' => 1,
            'created_at' => '2026-08-08 14:30:00',
            'status' => 'ativo',
            'nickname' => null,
            'metadata' => '{"a":1}',
            'avatar' => null,
            'code' => 'ab        ',
        ];
    }

    // ------------------------------------------------------------- hidratação

    #[DataProvider('dialects')]
    public function testRowsComeBackFullyTyped(string $dialect): void
    {
        [$db] = $this->db($dialect, [[self::rawRow()]]);

        $rows = $db->select()->from(Tables::users())->all();

        $this->assertCount(1, $rows);
        $row = $rows[0];

        $this->assertInstanceOf(UsersRow::class, $row);
        $this->assertSame(7, $row->id);
        $this->assertSame('10.50', $row->balance);
        $this->assertTrue($row->active);
        $this->assertInstanceOf(DateTimeImmutable::class, $row->created_at);
        $this->assertSame(UsersStatus::Ativo, $row->status);
        $this->assertSame(['a' => 1], $row->metadata);
        // CHAR: o espaço de preenchimento sai, para os dois bancos concordarem.
        $this->assertSame('ab', $row->code);
    }

    public function testOneAppliesLimitInTheSqlInsteadOfSlicingInPhp(): void
    {
        [$db, $pdo] = $this->db('pgsql', [[self::rawRow()]]);

        $db->select()->from(Tables::users())->one();

        $this->assertStringContainsString('LIMIT', $pdo->queries()[0]);
    }

    public function testOneOrFailNamesTheTableWhenThereIsNothing(): void
    {
        [$db] = $this->db('pgsql', [[]]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/users/');

        $db->select()->from(Tables::users())->oneOrFail();
    }

    public function testAQueryWithoutFromIsRefused(): void
    {
        [$db] = $this->db('pgsql');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/sem from\(\)/');

        $db->select()->all();
    }

    // ------------------------------------------------------------ imutabilidade

    public function testAddingAClauseDoesNotTouchTheOriginalBuilder(): void
    {
        [$db] = $this->db('pgsql');
        $u = Tables::users();

        $base = $db->select()->from($u)->where(eq($u->active, true));
        $paginada = $base->limit(10);

        $this->assertStringNotContainsString('LIMIT', $base->toSql()->sql);
        $this->assertStringContainsString('LIMIT', $paginada->toSql()->sql);
    }

    /**
     * Contar uma consulta limitada a 10 devolveria no máximo 10, e quem pagina quer o
     * total.
     */
    public function testCountIgnoresPagination(): void
    {
        [$db, $pdo] = $this->db('pgsql', [[['count' => 42]]]);
        $u = Tables::users();

        $total = $db->select()->from($u)->limit(10)->offset(20)->count();

        $this->assertSame(42, $total);
        $this->assertStringNotContainsString('LIMIT', $pdo->queries()[0]);
        $this->assertStringContainsString('COUNT(*)', $pdo->queries()[0]);
    }

    public function testCountPreservesDistinctThroughASubquery(): void
    {
        [$db, $pdo] = $this->db('pgsql', [[['count' => 3]]]);

        $total = $db->select()->from(Tables::users())->distinct()->count();

        $this->assertSame(3, $total);
        $this->assertSame(
            'SELECT COUNT(*) FROM (SELECT DISTINCT "users".* FROM "users") AS "neoorm_count"',
            $pdo->queries()[0],
        );
    }

    public function testCountOverGroupByCountsGroups(): void
    {
        [$db, $pdo] = $this->db('mysql', [[['count' => 2]]]);
        $users = Tables::users();

        $total = $db->select()->from($users)->groupBy($users->active)->count();

        $this->assertSame(2, $total);
        $this->assertSame(
            'SELECT COUNT(*) FROM (SELECT `users`.`active` AS `c0` FROM `users` '
            . 'GROUP BY `users`.`active`) AS `neoorm_count`',
            $pdo->queries()[0],
        );
    }

    public function testCountWithHavingAndNoGroupByUsesAnAggregateProjection(): void
    {
        [$db, $pdo] = $this->db('pgsql', [[['count' => 1]]]);

        $total = $db->select()
            ->from(Tables::users())
            ->having(gt(Func::count(), 1))
            ->count();

        $this->assertSame(1, $total);
        $this->assertSame(
            'SELECT COUNT(*) FROM (SELECT COUNT(*) AS "c0" FROM "users" '
            . 'HAVING COUNT(*) > :p0) AS "neoorm_count"',
            $pdo->queries()[0],
        );
    }

    #[DataProvider('negativePagination')]
    public function testNegativePaginationIsRefused(string $method): void
    {
        [$db] = $this->db('pgsql');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/negativo/');

        $db->select()->from(Tables::users())->{$method}(-1);
    }

    /** @return iterable<string,array{string}> */
    public static function negativePagination(): iterable
    {
        yield 'limit' => ['limit'];
        yield 'offset' => ['offset'];
    }

    // ------------------------------------------------------------------ binds

    /**
     * A razão de o valor passar pelo `Binder`: mandar PARAM_BOOL ao MySQL com a emulação
     * desligada grava string vazia para `false` — a linha entra, sem erro, errada.
     */
    public function testBooleansAreBoundTheWayEachEngineNeeds(): void
    {
        $u = Tables::users();

        [$mysql, $mysqlPdo] = $this->db('mysql', [[]]);
        $mysql->select()->from($u)->where(eq($u->active, true))->all();
        $this->assertSame([1, PDO::PARAM_INT], $mysqlPdo->binds[0]['p0']);

        [$pgsql, $pgsqlPdo] = $this->db('pgsql', [[]]);
        $pgsql->select()->from($u)->where(eq($u->active, true))->all();
        $this->assertSame([true, PDO::PARAM_BOOL], $pgsqlPdo->binds[0]['p0']);
    }

    /**
     * O formato sai do tipo da coluna, que o `ColumnRef` carrega.
     */
    #[DataProvider('dialects')]
    public function testDatesAreBoundInTheColumnFormat(string $dialect): void
    {
        [$db, $pdo] = $this->db($dialect, [[]]);
        $u = Tables::users();

        $db->select()->from($u)
            ->where(gt($u->created_at, new DateTimeImmutable('2026-08-08 14:30:00')))
            ->all();

        $this->assertSame(['2026-08-08 14:30:00', PDO::PARAM_STR], $pdo->binds[0]['p0']);
    }

    // ----------------------------------------------------------------- INSERT

    public function testInsertUsesTheGeneratedPayloadAndOmitsWhatWasNotGiven(): void
    {
        [$db, $pdo] = $this->db('pgsql', [[]]);

        $db->insert(Tables::users())
            ->values(new UsersInsert(name: 'Diogo'))
            ->execute();

        $sql = $pdo->queries()[0];

        $this->assertStringContainsString('"name"', $sql);
        // `nickname` ficou em Unspecified, então nem aparece: o banco aplica o DEFAULT.
        $this->assertStringNotContainsString('"nickname"', $sql);
        $this->assertStringNotContainsString('"phone"', $sql);
    }

    public function testBatchInsertUsesDefaultForColumnsMissingFromOnlySomeRows(): void
    {
        [$db, $pdo] = $this->db('pgsql', [[]]);

        $db->insert(Tables::users())->values(
            new UsersInsert(name: 'Ana', phone: '1111'),
            new UsersInsert(name: 'Bia'),
        )->execute();

        $this->assertSame(
            'INSERT INTO "users" ("name", "phone") VALUES (:p0, :p1), (:p2, DEFAULT)',
            $pdo->queries()[0],
        );
        $this->assertSame(['Ana', '1111', 'Bia'], array_column($pdo->binds[0], 0));
    }

    public function testInsertRefusesAMissingRequiredColumnBeforeTheDatabase(): void
    {
        [$db] = $this->db('pgsql');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/obrigatória.*name/');

        $db->insert(Tables::users())->values([])->execute();
    }

    /**
     * No PostgreSQL a linha volta no mesmo round-trip.
     */
    public function testReturningOneUsesASingleStatementWhereReturningExists(): void
    {
        [$db, $pdo] = $this->db('pgsql', [[self::rawRow()]]);

        $row = $db->insert(Tables::users())->values(new UsersInsert(name: 'Diogo'))->returningOne();

        $this->assertInstanceOf(UsersRow::class, $row);
        $this->assertCount(1, $pdo->queries());
        $this->assertStringContainsString('RETURNING', $pdo->queries()[0]);
    }

    /**
     * No MySQL custa um segundo SELECT por `lastInsertId()` — e o custo fica visível na
     * chamada, em vez de escondido.
     */
    public function testReturningOneCostsASecondSelectOnMysql(): void
    {
        [$db, $pdo] = $this->db('mysql', [[], [self::rawRow()]], '7');

        $row = $db->insert(Tables::users())->values(new UsersInsert(name: 'Diogo'))->returningOne();

        $this->assertInstanceOf(UsersRow::class, $row);

        $queries = $pdo->queries();
        $this->assertCount(2, $queries);
        $this->assertStringStartsWith('INSERT', $queries[0]);
        $this->assertStringStartsWith('SELECT', $queries[1]);
        $this->assertStringNotContainsString('RETURNING', $queries[0]);
    }

    public function testReturningOneDoesNotNarrowTheGeneratedIdToPhpInt(): void
    {
        $id = '9223372036854775808';
        [$db, $pdo] = $this->db('mysql', [[], [self::rawRow()]], $id);

        $db->insert(Tables::users())->values(new UsersInsert(name: 'Diogo'))->returningOne();

        $this->assertSame([$id, PDO::PARAM_STR], $pdo->binds[1]['p0']);
    }

    /**
     * `lastInsertId()` devolve só o id da PRIMEIRA linha, e a contiguidade das seguintes
     * depende de `innodb_autoinc_lock_mode`. Recusar é melhor que acertar quase sempre.
     */
    public function testReturningManyIsRefusedOnMysql(): void
    {
        [$db] = $this->db('mysql', [[]]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/não tem RETURNING/');

        $db->insert(Tables::users())
            ->values(new UsersInsert(name: 'A'), new UsersInsert(name: 'B'))
            ->returning();
    }

    public function testAnInsertWithoutValuesIsRefused(): void
    {
        [$db] = $this->db('pgsql');

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/sem values\(\)/');

        $db->insert(Tables::users())->execute();
    }

    // ------------------------------------------------------- UPDATE e DELETE

    /**
     * Só as colunas informadas entram no SET. O `store()` antigo mandava a linha inteira,
     * e dois requests que alteram campos diferentes se sobrescreviam.
     */
    public function testUpdateWritesOnlyWhatWasSet(): void
    {
        [$db, $pdo] = $this->db('pgsql', [[]]);
        $u = Tables::users();

        $db->update($u)->set(['phone' => '4899'])->where(eq($u->id, 1))->execute();

        $sql = $pdo->queries()[0];

        $this->assertStringContainsString('SET "phone"', $sql);
        $this->assertStringNotContainsString('"balance"', $sql);
    }

    #[DataProvider('dialects')]
    public function testUpdateAndDeleteWithoutWhereAreRefusedUnlessDeclared(string $dialect): void
    {
        [$db] = $this->db($dialect, [[], [], [], []]);
        $u = Tables::users();

        try {
            $db->update($u)->set(['phone' => 'x'])->execute();
            $this->fail('UPDATE sem WHERE deveria ter sido recusado');
        } catch (QueryException $e) {
            $this->assertStringContainsString('allowFullTableScan', $e->getMessage());
        }

        try {
            $db->delete($u)->execute();
            $this->fail('DELETE sem WHERE deveria ter sido recusado');
        } catch (QueryException $e) {
            $this->assertStringContainsString('allowFullTableScan', $e->getMessage());
        }

        // Declarado, passa.
        $db->update($u)->set(['phone' => 'x'])->allowFullTableScan()->execute();
        $db->delete($u)->allowFullTableScan()->execute();
        $this->addToAssertionCount(2);
    }

    // ------------------------------------------------------------- transações

    public function testATransactionCommitsAndTheBuilderRunsInsideIt(): void
    {
        [$db, $pdo] = $this->db('pgsql', [[]]);

        $result = $db->transaction(function (Tx $tx): string {
            $tx->insert(Tables::users())->values(new UsersInsert(name: 'Diogo'))->execute();

            return 'pronto';
        });

        $this->assertSame('pronto', $result);
        $this->assertSame('BEGIN', $pdo->executed[0]);
        $this->assertStringStartsWith('INSERT', $pdo->executed[1]);
        $this->assertSame('COMMIT', $pdo->executed[2]);
    }

    public function testAFailureRollsBackAndPropagates(): void
    {
        [$db, $pdo] = $this->db('pgsql');

        try {
            $db->transaction(static function (): void {
                throw new \RuntimeException('falhou');
            });
            $this->fail('a exceção deveria ter propagado');
        } catch (\RuntimeException $e) {
            $this->assertSame('falhou', $e->getMessage());
        }

        $this->assertSame(['BEGIN', 'ROLLBACK'], $pdo->executed);
    }

    /**
     * Transação dentro de transação vira savepoint — o buraco que o `Connection`
     * estático tinha, onde `beginTransaction()` era no-op se já houvesse uma.
     */
    #[DataProvider('dialects')]
    public function testNestingUsesSavepoints(string $dialect): void
    {
        [$db, $pdo] = $this->db($dialect);

        $db->transaction(function (Tx $tx) use ($db): void {
            $db->transaction(static function (Tx $inner): void {
            });
        });

        $this->assertSame('BEGIN', $pdo->executed[0]);
        $this->assertStringContainsString('SAVEPOINT', $pdo->executed[1]);
        $this->assertStringContainsString('neoorm_sp1', $pdo->executed[1]);
        $this->assertStringContainsString('RELEASE SAVEPOINT', $pdo->executed[2]);
        $this->assertSame('COMMIT', $pdo->executed[3]);
    }

    public function testTwoDatabasesOnTheSameConnectionShareTransactionDepth(): void
    {
        $pdo = new FakePdo();
        $dialect = DialectFactory::pgsql();
        $outer = new Database($pdo, $dialect);
        $inner = new Database($pdo, $dialect);

        $outer->transaction(function () use ($inner): void {
            $inner->transaction(static function (): void {
            });
        });

        $this->assertSame('BEGIN', $pdo->executed[0]);
        $this->assertStringContainsString('SAVEPOINT', $pdo->executed[1]);
        $this->assertStringContainsString('RELEASE SAVEPOINT', $pdo->executed[2]);
        $this->assertSame('COMMIT', $pdo->executed[3]);
    }

    /**
     * A falha interna desfaz só até o savepoint; a transação de fora segue viva e
     * commita. É a diferença entre aninhamento de verdade e o no-op antigo.
     */
    public function testAnInnerFailureRollsBackOnlyToTheSavepoint(): void
    {
        [$db, $pdo] = $this->db('pgsql');

        $db->transaction(function (Tx $tx) use ($db): void {
            try {
                $db->transaction(static function (): void {
                    throw new \RuntimeException('interna');
                });
            } catch (\RuntimeException) {
                // tratada: a transação de fora continua
            }
        });

        $this->assertStringContainsString('ROLLBACK TO SAVEPOINT', $pdo->executed[2]);
        $this->assertSame('COMMIT', $pdo->executed[3]);
    }
}
