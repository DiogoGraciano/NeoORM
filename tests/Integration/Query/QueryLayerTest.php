<?php

declare(strict_types=1);

namespace Tests\Integration\Query;

use DateTimeImmutable;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use Diogodg\Neoorm\Query\Database;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\Op;
use Diogodg\Neoorm\Query\Tx;
use PHPUnit\Framework\Attributes\Group;
use Tests\Generated\Inserts\CountryInsert;
use Tests\Generated\Rows\CityRow;
use Tests\Generated\Rows\CountryRow;
use Tests\Generated\Tables;
use Tests\Support\QueryTestCase;

use function Diogodg\Neoorm\Query\asc;
use function Diogodg\Neoorm\Query\desc;
use function Diogodg\Neoorm\Query\eq;
use function Diogodg\Neoorm\Query\inArray;
use function Diogodg\Neoorm\Query\isNull;
use function Diogodg\Neoorm\Query\like;
use function Diogodg\Neoorm\Query\ne;

/**
 * A camada de consulta contra um banco de verdade, nos dois dialetos.
 *
 * O que os testes unitários não conseguem provar: que o SQL gerado é aceito pelo
 * servidor, que os casters lidam com o que o driver realmente devolve, e que a
 * transação aninhada desfaz só o que devia.
 */
#[Group('query')]
final class QueryLayerTest extends QueryTestCase
{
    public function testPostgresRuntimeUsesTheConfiguredSchema(): void
    {
        if (!$this->isPgsql()) {
            $this->markTestSkipped('search_path existe apenas no PostgreSQL.');
        }

        $base = DatabaseConfig::fromConfig();
        $schema = 'neoorm_runtime_schema';
        $config = new DatabaseConfig(
            driver: $base->driver,
            host: $base->host,
            port: $base->port,
            database: $base->database,
            user: $base->user,
            password: $base->password,
            charset: $base->charset,
            schema: $schema,
        );

        $db = Database::fromConfig($config);
        $configured = $db->run(new CompiledQuery("SELECT current_setting('search_path')", []))->fetchColumn();

        $this->assertSame($schema, trim((string) $configured, '"'));
    }

    public function testFromConfigRestoresTheSessionContract(): void
    {
        $pdo = $this->pdo();

        if ($this->isPgsql()) {
            $pdo->exec('SET search_path TO pg_catalog');
            $db = Database::fromConfig();
            $actual = $db->run(new CompiledQuery("SELECT current_setting('search_path')", []))->fetchColumn();

            $this->assertSame(Config::getSchema(), trim((string) $actual, '"'));

            return;
        }

        $pdo->exec("SET time_zone = '-03:00'");
        $db = Database::fromConfig();
        $actual = $db->run(new CompiledQuery('SELECT @@session.time_zone', []))->fetchColumn();

        $this->assertSame('+00:00', $actual);
    }

    /**
     * Insert, select tipado, update, delete — o ciclo completo.
     */
    public function testTheFullCycleAgainstARealDatabase(): void
    {
        $db = $this->db();
        $country = Tables::country();

        $db->insert($country)->values(new CountryInsert(name: 'Chile', abbreviation: 'CL'))->execute();

        $lido = $db->select()->from($country)->where(eq($country->name, 'Chile'))->one();

        $this->assertInstanceOf(CountryRow::class, $lido);
        $this->assertSame('Chile', $lido->name);
        // O id veio do banco como inteiro de verdade, não string.
        $this->assertIsInt($lido->id);

        $afetadas = $db->update($country)
            ->set(['abbreviation' => 'CH'])
            ->where(eq($country->id, $lido->id))
            ->execute();

        $this->assertSame(1, $afetadas);
        $this->assertSame(
            'CH',
            $db->select()->from($country)->where(eq($country->id, $lido->id))->oneOrFail()->abbreviation,
        );

        $this->assertSame(1, $db->delete($country)->where(eq($country->id, $lido->id))->execute());
        $this->assertNull($db->select()->from($country)->where(eq($country->id, $lido->id))->one());
    }

    public function testUpdateCountsMatchedRowsEvenWhenTheValueDoesNotChange(): void
    {
        $db = $this->db();
        $country = Tables::country();
        $brasil = $db->select()->from($country)->where(eq($country->abbreviation, 'BR'))->oneOrFail();

        $matched = $db->update($country)
            ->set(['abbreviation' => 'BR'])
            ->where(eq($country->id, $brasil->id))
            ->execute();

        $this->assertSame(1, $matched);
    }

    /**
     * `returningOne()` funciona nos dois bancos, por caminhos diferentes: RETURNING no
     * PostgreSQL, INSERT mais SELECT por lastInsertId() no MySQL.
     */
    public function testReturningOneWorksOnBothEnginesByDifferentRoutes(): void
    {
        $linha = $this->db()->insert(Tables::country())
            ->values(new CountryInsert(name: 'Uruguai', abbreviation: 'UY'))
            ->returningOne();

        $this->assertInstanceOf(CountryRow::class, $linha);
        $this->assertSame('Uruguai', $linha->name);
        $this->assertGreaterThan(0, $linha->id);
    }

    public function testBatchInsertAllowsDifferentOptionalColumns(): void
    {
        $db = $this->db();
        $country = Tables::country();

        $inserted = $db->insert($country)->values(
            new CountryInsert(name: 'Paraguai', abbreviation: 'PY', id: 900001),
            new CountryInsert(name: 'Peru', abbreviation: 'PE'),
        )->execute();

        $this->assertSame(2, $inserted);
        $this->assertSame(
            2,
            $db->select()->from($country)->where(inArray($country->abbreviation, ['PY', 'PE']))->count(),
        );
    }

    /**
     * A falha interna desfaz até o savepoint; a externa commita. É o aninhamento de
     * verdade, que o `Connection` estático não tinha: lá `beginTransaction()` era no-op
     * se já houvesse transação, então a falha interna desfazia o trabalho externo sem
     * avisar.
     */
    public function testANestedRollbackUndoesOnlyTheInnerWork(): void
    {
        $db = $this->db();
        $country = Tables::country();

        $db->transaction(function (Tx $tx) use ($db, $country): void {
            $tx->insert($country)->values(new CountryInsert(name: 'Externo', abbreviation: 'EX'))->execute();

            try {
                $db->transaction(static function (Tx $inner) use ($country): void {
                    $inner->insert($country)
                        ->values(new CountryInsert(name: 'Interno', abbreviation: 'IN'))->execute();

                    throw new \RuntimeException('desfaz só isto');
                });
            } catch (\RuntimeException) {
                // tratada de propósito: a transação de fora segue
            }
        });

        $this->assertNotNull(
            $db->select()->from($country)->where(eq($country->name, 'Externo'))->one(),
            'a escrita da transação externa deveria ter sobrevivido',
        );

        $this->assertNull(
            $db->select()->from($country)->where(eq($country->name, 'Interno'))->one(),
            'a escrita da transação interna deveria ter sido desfeita pelo savepoint',
        );
    }

    public function testAFailingTransactionRollsBackEverything(): void
    {
        $db = $this->db();
        $country = Tables::country();
        $antes = $db->select()->from($country)->count();

        try {
            $db->transaction(static function (Tx $tx) use ($country): void {
                $tx->insert($country)->values(new CountryInsert(name: 'Fantasma', abbreviation: 'FT'))->execute();

                throw new \RuntimeException('estoura');
            });

            $this->fail('a exceção deveria ter propagado');
        } catch (\RuntimeException) {
        }

        $this->assertSame($antes, $db->select()->from($country)->count());
        $this->assertNull($db->select()->from($country)->where(eq($country->name, 'Fantasma'))->one());
    }

    /**
     * Paginação e contagem: o `count()` ignora LIMIT e OFFSET, senão devolveria no
     * máximo o tamanho da página — e uma paginação que conta a página em vez do total
     * mostra sempre uma página só.
     */
    public function testCountIgnoresPagination(): void
    {
        $city = Tables::city();
        $consulta = $this->db()->select()->from($city)->orderBy(asc($city->id));

        $this->assertSame(4, $consulta->count());
        $this->assertCount(2, $consulta->limit(2)->all());
        $this->assertCount(2, $consulta->limit(2)->offset(2)->all());
        $this->assertSame(4, $consulta->limit(2)->offset(2)->count());

        // As páginas não se sobrepõem: o OFFSET foi aplicado de verdade, e não como o
        // `LIMIT a,b` do MySQL, que inverte a leitura dos dois números.
        $primeira = array_map(static fn (CityRow $c): int => $c->id, $consulta->limit(2)->all());
        $segunda = array_map(static fn (CityRow $c): int => $c->id, $consulta->limit(2)->offset(2)->all());

        $this->assertSame([], array_intersect($primeira, $segunda));
    }

    /**
     * Um OFFSET sem LIMIT: o MySQL não expressa isso, e o dialeto emite o sentinela
     * `LIMIT 18446744073709551615` para conseguir. Se o sentinela estivesse errado, aqui
     * viria zero linha ou um erro de sintaxe.
     */
    public function testOffsetWithoutLimit(): void
    {
        $city = Tables::city();

        $restantes = $this->db()->select()->from($city)->orderBy(asc($city->id))->offset(1)->all();

        $this->assertCount(3, $restantes);
    }

    /**
     * O builder é imutável: cada cláusula devolve outra instância.
     *
     * É o que elimina o `clean()` da API antiga — lá o mesmo objeto acumulava filtros
     * entre execuções, e "filtro sobrando da consulta anterior" era a classe de bug
     * resultante.
     */
    public function testTheBuilderIsImmutableAcrossExecutions(): void
    {
        $city = Tables::city();
        $todas = $this->db()->select()->from($city);

        $filtrada = $todas->where(like($city->name, 'São%'));

        $this->assertSame(1, $filtrada->count());
        $this->assertSame(4, $todas->count(), 'a consulta original foi mutada pelo where()');
    }

    /**
     * Seleção parcial com join: as colunas da tabela juntada saem com prefixo, senão
     * colidiriam com as da base no fetch associativo — `city.id` e `state.id` escrevem a
     * mesma chave `id`, e a última vence, em silêncio.
     */
    public function testJoinedColumnsDoNotCollideInTheResult(): void
    {
        $state = Tables::state();
        $city = Tables::city();

        $linhas = $this->db()->selectFields([$state->id, $state->name, $city->id, $city->name])
            ->from($state)
            ->innerJoin($city, eq($city->state, $state->id))
            ->orderBy(asc($city->id))
            ->all();

        $this->assertCount(4, $linhas);
        $this->assertArrayHasKey('id', $linhas[0]);
        $this->assertArrayHasKey('city__id', $linhas[0]);
        $this->assertArrayHasKey('city__name', $linhas[0]);
        $this->assertSame('São Paulo', $linhas[0]['city__name']);
    }

    /**
     * LEFT JOIN preserva a linha da esquerda sem par — e é a diferença observável entre
     * ele e o INNER.
     */
    public function testLeftJoinKeepsUnmatchedRows(): void
    {
        $db = $this->db();
        $country = Tables::country();
        $state = Tables::state();

        $db->insert($country)->values(new CountryInsert(name: 'Chile', abbreviation: 'CL'))->execute();

        $comInner = $db->selectFields([$country->id])
            ->from($country)->innerJoin($state, eq($state->country, $country->id))->all();

        $comLeft = $db->selectFields([$country->id])
            ->from($country)->leftJoin($state, eq($state->country, $country->id))->all();

        $this->assertCount(3, $comInner, 'SP, RJ e BA — o Chile não tem estado');
        $this->assertCount(4, $comLeft, 'o Chile entra com as colunas do estado nulas');
    }

    /**
     * Um self-join só funciona porque `as()` requalifica as colunas: sem isso as duas
     * pontas gerariam `state.id` e a condição viraria tautologia.
     */
    public function testAnAliasedTableRequalifiesItsColumns(): void
    {
        $state = Tables::state();
        $vizinho = Tables::state('vizinho');

        $pares = $this->db()->selectFields([$state->id, $vizinho->id])
            ->from($state)
            ->innerJoin($vizinho, Op::and(eq($vizinho->country, $state->country), ne($vizinho->id, $state->id)))
            ->all();

        // SP–RJ e RJ–SP. Buenos Aires é o único estado do país dela, então não pareia.
        $this->assertCount(2, $pares);
        $this->assertArrayHasKey('vizinho__id', $pares[0]);
    }

    public function testOrderingByMoreThanOneTermAndInBothDirections(): void
    {
        $city = Tables::city();

        $nomes = array_map(
            static fn (CityRow $c): string => $c->name,
            $this->db()->select()->from($city)->orderBy(desc($city->state), asc($city->name))->all(),
        );

        $this->assertSame(['La Plata', 'Rio de Janeiro', 'Campinas', 'São Paulo'], $nomes);
    }

    public function testInArrayAndNullChecks(): void
    {
        $db = $this->db();
        $city = Tables::city();
        $appointment = Tables::appointment();

        $selecionadas = $db->select()->from($city)
            ->where(inArray($city->name, ['Campinas', 'La Plata', 'Inexistente']))
            ->orderBy(asc($city->name))
            ->all();

        $this->assertSame(
            ['Campinas', 'La Plata'],
            array_map(static fn (CityRow $c): string => $c->name, $selecionadas),
        );

        // Todo compromisso foi semeado sem cliente.
        $this->assertSame(5, $db->select()->from($appointment)->where(isNull($appointment->client_id))->count());
    }

    /**
     * `cursor()` percorre sem materializar a lista, e devolve as mesmas linhas tipadas.
     */
    public function testCursorStreamsTheSameTypedRows(): void
    {
        $city = Tables::city();

        $nomes = [];

        foreach ($this->db()->select()->from($city)->orderBy(asc($city->id))->cursor() as $linha) {
            $this->assertInstanceOf(CityRow::class, $linha);

            $nomes[] = $linha->name;
        }

        $this->assertCount(4, $nomes);
        $this->assertSame('São Paulo', $nomes[0]);
    }

    /**
     * UPDATE e DELETE sem WHERE são recusados.
     *
     * O `deleteByFilter()` antigo já protegia o DELETE; o UPDATE nunca protegeu, e um
     * `where()` esquecido reescrevia a tabela inteira sem aviso.
     */
    public function testAModifyWithoutAWhereIsRefusedUnlessAskedFor(): void
    {
        $db = $this->db();
        $city = Tables::city();

        try {
            $db->update($city)->set(['name' => 'x'])->execute();
            $this->fail('um UPDATE sem WHERE deveria ter sido recusado');
        } catch (QueryException) {
        }

        try {
            $db->delete($city)->execute();
            $this->fail('um DELETE sem WHERE deveria ter sido recusado');
        } catch (QueryException) {
        }

        // Explicitamente pedido, funciona — a proteção é contra o esquecimento, não
        // contra a intenção.
        $this->assertSame(4, $db->update($city)->set(['ibge' => null])->allowFullTableScan()->execute());
        $this->assertSame(4, $db->delete($city)->allowFullTableScan()->execute());
    }

    public function testDeleteByFilterRemovesOnlyTheMatchingRows(): void
    {
        $db = $this->db();
        $appointment = Tables::appointment();

        $removidos = $db->delete($appointment)->where(eq($appointment->status, 'confirmed'))->execute();

        $this->assertSame(3, $removidos);
        $this->assertSame(2, $db->select()->from($appointment)->count());
    }

    /**
     * O fuso da sessão é fixado pelo `connectionSetup()`, e é o que faz os casters
     * temporais darem a mesma resposta independentemente do fuso do container.
     */
    public function testTemporalValuesRoundTrip(): void
    {
        $appointment = Tables::appointment();

        $linha = $this->db()->select()->from($appointment)->orderBy(asc($appointment->id))->oneOrFail();

        $this->assertInstanceOf(DateTimeImmutable::class, $linha->start_date);
        $this->assertSame('2025-04-16 09:00:00', $linha->start_date->format('Y-m-d H:i:s'));
        $this->assertSame('2025-04-16 10:00:00', $linha->end_date->format('Y-m-d H:i:s'));
    }

    public function testTemporalWritesAreNormalizedToUtc(): void
    {
        $db = $this->db();
        $appointment = Tables::appointment();
        $first = $db->select()->from($appointment)->orderBy(asc($appointment->id))->oneOrFail();

        $db->update($appointment)
            ->set(['start_date' => new DateTimeImmutable('2026-08-08 14:30:00-03:00')])
            ->where(eq($appointment->id, $first->id))
            ->execute();

        $read = $db->select()->from($appointment)->where(eq($appointment->id, $first->id))->oneOrFail();

        $this->assertSame('UTC', $read->start_date->getTimezone()->getName());
        $this->assertSame('2026-08-08 17:30:00', $read->start_date->format('Y-m-d H:i:s'));
    }

    /**
     * TIME continua string, e é deliberado: no MySQL é DURAÇÃO, com faixa de ±838 horas.
     * `DateTimeImmutable` corromperia `838:59:59` em silêncio.
     */
    public function testTimeStaysAString(): void
    {
        $employee = Tables::employee();

        $linha = $this->db()->select()->from($employee)->orderBy(asc($employee->id))->oneOrFail();

        $this->assertIsString($linha->start_time);
        $this->assertStringStartsWith('08:00', $linha->start_time);
    }

    /**
     * O valor sempre vira bind, então uma string com sintaxe SQL é só texto que não casa
     * com nada. Vale para a comparação e para o INSERT.
     */
    public function testAValueIsNeverInterpretedAsSql(): void
    {
        $db = $this->db();
        $country = Tables::country();

        $this->assertSame(0, $db->select()->from($country)->where(eq($country->name, "' OR '1'='1"))->count());

        $db->insert($country)
            ->values(new CountryInsert(name: "'; DROP TABLE country; --", abbreviation: 'XX'))
            ->execute();

        $this->assertSame(3, $db->select()->from($country)->count());
        $this->assertNotNull(
            $db->select()->from($country)->where(eq($country->name, "'; DROP TABLE country; --"))->one(),
            'o texto deveria ter sido gravado como texto',
        );
    }
}
