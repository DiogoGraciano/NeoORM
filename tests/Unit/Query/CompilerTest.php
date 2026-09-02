<?php

declare(strict_types=1);

namespace Tests\Unit\Query;

use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Query\Compiler\BindCollector;
use Diogodg\Neoorm\Query\Compiler\Compiler;
use Diogodg\Neoorm\Query\Compiler\ExpressionCompiler;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\NullsPlacement;
use Diogodg\Neoorm\Query\Expr\Value;
use Diogodg\Neoorm\Query\State\DeleteState;
use Diogodg\Neoorm\Query\State\InsertState;
use Diogodg\Neoorm\Query\State\JoinClause;
use Diogodg\Neoorm\Query\State\JoinType;
use Diogodg\Neoorm\Query\State\SelectState;
use Diogodg\Neoorm\Query\State\UpdateState;
use InvalidArgumentException;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DialectProviders;
use Tests\Support\Factory\Queries;
use Tests\Support\UnitTestCase;

use function Diogodg\Neoorm\Query\eq;
use function Diogodg\Neoorm\Query\gt;
use function Diogodg\Neoorm\Query\asc;
use function Diogodg\Neoorm\Query\sql;

/**
 * O que o golden file não consegue afirmar: as recusas, a ordem dos binds e as
 * invariantes que valem para qualquer dialeto.
 *
 * Nenhum teste aqui abre conexão — o guard do `UnitTestCase` é a prova de que a
 * compilação inteira é offline.
 */
final class CompilerTest extends UnitTestCase
{
    use DialectProviders;

    private function compiler(): Compiler
    {
        return new Compiler();
    }

    // ---------------------------------------------------------------- recusas

    #[DataProvider('dialects')]
    public function testSelectWithoutFromIsRefused(string $dialect): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/SELECT sem FROM/');

        $this->compiler()->compileSelect(new SelectState(), DialectFactory::for($dialect));
    }

    /**
     * O `deleteByFilter()` antigo já exigia filtro; o UPDATE nunca exigiu. Um `set()`
     * sem `where()` reescreve todas as linhas sem que nada no código pareça errado.
     */
    #[DataProvider('dialects')]
    public function testUpdateWithoutWhereIsRefusedUnlessDeclared(string $dialect): void
    {
        $engine = DialectFactory::for($dialect);
        $state = (new UpdateState(Queries::users()))
            ->withAssignments(['name' => new Value('x')]);

        try {
            $this->compiler()->compileUpdate($state, $engine);
            $this->fail('UPDATE sem WHERE deveria ter sido recusado');
        } catch (QueryException $e) {
            $this->assertStringContainsString('allowFullTableScan', $e->getMessage());
        }

        // Declarado, passa.
        $compiled = $this->compiler()->compileUpdate($state->withFullTableScan(), $engine);
        $this->assertStringNotContainsString('WHERE', $compiled->sql);
    }

    #[DataProvider('dialects')]
    public function testDeleteWithoutWhereIsRefusedUnlessDeclared(string $dialect): void
    {
        $engine = DialectFactory::for($dialect);

        try {
            $this->compiler()->compileDelete(new DeleteState(Queries::users()), $engine);
            $this->fail('DELETE sem WHERE deveria ter sido recusado');
        } catch (QueryException $e) {
            $this->assertStringContainsString('allowFullTableScan', $e->getMessage());
        }

        $compiled = $this->compiler()->compileDelete(
            (new DeleteState(Queries::users()))->withFullTableScan(),
            $engine,
        );

        $this->assertSame('DELETE FROM ' . $engine->quoteIdentifier('users'), $compiled->sql);
    }

    #[DataProvider('dialects')]
    public function testUpdateWithoutAssignmentsIsRefused(string $dialect): void
    {
        $this->expectException(QueryException::class);

        $this->compiler()->compileUpdate(
            (new UpdateState(Queries::users()))->withWhere(eq(Queries::column('users', 'id'), 1)),
            DialectFactory::for($dialect),
        );
    }

    #[DataProvider('dialects')]
    public function testInsertWithoutRowsIsRefused(string $dialect): void
    {
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/sem nenhuma linha/');

        $this->compiler()->compileInsert(
            new InsertState(Queries::users(), ['name']),
            DialectFactory::for($dialect),
        );
    }

    /**
     * Contar a aridade na construção evita gravar valor na coluna errada — o que o
     * banco aceitaria em silêncio sempre que os tipos casassem.
     */
    public function testAnInsertRowMustMatchTheColumnCount(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/1 valor\(es\) para 2 coluna\(s\)/');

        new InsertState(Queries::users(), ['name', 'age'], [[new Value('Diogo')]]);
    }

    /**
     * Um nó sem braço no `match` lança em vez de sumir do SQL. É a diferença entre
     * um erro na hora e uma condição que simplesmente não filtra nada.
     */
    public function testAnUnknownExpressionNodeIsRefused(): void
    {
        $node = new class implements Expression {};

        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/sem tradução/');

        (new ExpressionCompiler())->compile($node, DialectFactory::for('mysql'), new BindCollector());
    }

    // ------------------------------------------------------------------ binds

    public function testMysqlNullPlacementAllocatesBindsForBothExpressionOccurrences(): void
    {
        $state = (new SelectState(from: Queries::users()))
            ->withOrderBy([asc(sql('?', 7), NullsPlacement::Last)]);

        $compiled = $this->compiler()->compileSelect($state, DialectFactory::mysql());

        $this->assertStringContainsString(':p0 IS NULL ASC, :p1 ASC', $compiled->sql);
        $this->assertSame(['p0' => 7, 'p1' => 7], $compiled->values());
    }

    /**
     * Os placeholders são alocados na ordem textual do SQL. Sem isso, um bind de LIMIT
     * alocado antes do WHERE trocaria os valores de posição — e o texto da query
     * continuaria idêntico, então nada acusaria.
     */
    #[DataProvider('dialects')]
    public function testBindsAreNumberedInTheOrderTheyAppearInTheSql(string $dialect): void
    {
        $compiled = $this->compiler()->compileSelect(
            (new SelectState())
                ->withFrom(Queries::users())
                ->withWhere(gt(Queries::column('users', 'age'), 18))
                ->withLimit(10)
                ->withOffset(20),
            DialectFactory::for($dialect),
        );

        $this->assertSame(['p0' => 18, 'p1' => 10, 'p2' => 20], $compiled->values());
        $this->assertLessThan(
            strpos($compiled->sql, ':p1'),
            strpos($compiled->sql, ':p0'),
            'O bind do WHERE tem que aparecer antes do bind da paginação.',
        );
    }

    /**
     * Inteiro precisa chegar ao driver como inteiro: com `ATTR_EMULATE_PREPARES`
     * desligado o servidor leva o tipo a sério, e `LIMIT` com string é erro no MySQL.
     */
    #[DataProvider('dialects')]
    public function testPaginationBindsCarryTheIntegerType(string $dialect): void
    {
        $compiled = $this->compiler()->compileSelect(
            (new SelectState())->withFrom(Queries::users())->withLimit(10),
            DialectFactory::for($dialect),
        );

        $this->assertSame([10, PDO::PARAM_INT], $compiled->binds['p0']);
    }

    /**
     * A invariante de segurança da camada: o valor do chamador nunca vira texto de SQL.
     */
    #[DataProvider('dialects')]
    public function testAValueNeverReachesTheSqlAsText(string $dialect): void
    {
        $injecao = "' OR '1'='1";

        $compiled = $this->compiler()->compileSelect(
            (new SelectState())
                ->withFrom(Queries::users())
                ->withWhere(eq(Queries::column('users', 'name', 'VARCHAR'), $injecao)),
            DialectFactory::for($dialect),
        );

        $this->assertStringNotContainsString($injecao, $compiled->sql);
        $this->assertContains($injecao, $compiled->values());
    }

    // ------------------------------------------------------- desambiguação

    /**
     * `users.id` e `posts.id` escrevem a mesma chave num fetch associativo, e a última
     * vence em silêncio, com o valor da tabela errada.
     */
    #[DataProvider('dialects')]
    public function testJoinedColumnsAreAliasedAndBaseColumnsAreNot(string $dialect): void
    {
        $engine = DialectFactory::for($dialect);
        $id = Queries::column('users', 'id');
        $postId = Queries::column('posts', 'id');

        $compiled = $this->compiler()->compileSelect(
            (new SelectState())
                ->withFrom(Queries::users())
                ->withColumns([$id, $postId])
                ->withJoin(new JoinClause(JoinType::Inner, Queries::posts(), eq($postId, $id))),
            $engine,
        );

        $this->assertStringContainsString($engine->quoteIdentifier('posts__id'), $compiled->sql);
        $this->assertStringNotContainsString($engine->quoteIdentifier('users__id'), $compiled->sql);
    }

    #[DataProvider('dialects')]
    public function testWithoutJoinsNothingIsRenamed(string $dialect): void
    {
        $compiled = $this->compiler()->compileSelect(
            (new SelectState())
                ->withFrom(Queries::users())
                ->withColumns([Queries::column('users', 'id')]),
            DialectFactory::for($dialect),
        );

        $this->assertStringNotContainsString('__', $compiled->sql);
    }

    // ------------------------------------------------- divergência de dialeto

    /**
     * O mesmo estado nos dois bancos. Só o que É diferente pode sair diferente — e o
     * que muda está enumerado aqui, não descoberto em produção.
     */
    public function testTheSameStateDiffersOnlyWhereTheEnginesDiffer(): void
    {
        $state = (new SelectState())
            ->withFrom(Queries::users())
            ->withWhere(gt(Queries::column('users', 'age'), 18));

        $mysql = $this->compiler()->compileSelect($state, DialectFactory::for('mysql'));
        $pgsql = $this->compiler()->compileSelect($state, DialectFactory::for('pgsql'));

        // Os binds são idênticos: a diferença entre bancos é de grafia, não de valores.
        $this->assertSame($mysql->values(), $pgsql->values());

        // E o SQL vira o mesmo texto quando se normaliza só a citação.
        $this->assertSame(
            str_replace('`', '"', $mysql->sql),
            $pgsql->sql,
        );
    }

    // ---------------------------------------------------------- imutabilidade

    /**
     * O builder antigo acumulava filtros em `$this` e precisava de `clean()` depois de
     * cada execução — uma exceção no meio deixava a query seguinte com os filtros da
     * anterior. Aqui não existe estado para vazar.
     */
    public function testAStateIsNeverMutated(): void
    {
        $base = (new SelectState())->withFrom(Queries::users());
        $comFiltro = $base->withWhere(eq(Queries::column('users', 'id'), 1));

        $this->assertNull($base->where);
        $this->assertNotNull($comFiltro->where);
        $this->assertNotSame($base, $comFiltro);
    }

    /**
     * Contar uma consulta limitada a 10 devolveria no máximo 10, e quem pagina quer
     * o total.
     */
    public function testPaginationCanBeDroppedForCounting(): void
    {
        $paginada = (new SelectState())
            ->withFrom(Queries::users())
            ->withLimit(10)
            ->withOffset(20);

        $total = $paginada->withoutPagination();

        $this->assertNull($total->limit);
        $this->assertNull($total->offset);
        $this->assertSame(10, $paginada->limit);
    }

    /**
     * `where()` duas vezes restringe, não substitui.
     */
    public function testConditionsAccumulateWithAnd(): void
    {
        $compiled = $this->compiler()->compileSelect(
            (new SelectState())
                ->withFrom(Queries::users())
                ->withWhere(eq(Queries::column('users', 'id'), 1))
                ->withWhere(gt(Queries::column('users', 'age'), 18)),
            DialectFactory::for('pgsql'),
        );

        $this->assertStringContainsString('AND', $compiled->sql);
        $this->assertCount(2, $compiled->binds);
    }
}
