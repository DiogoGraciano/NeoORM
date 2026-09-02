<?php

declare(strict_types=1);

namespace Tests\Integration\Query;

use Diogodg\Neoorm\Query\Expr\Aliased;
use Diogodg\Neoorm\Query\Func;
use PHPUnit\Framework\Attributes\Group;
use Tests\Generated\Tables;
use Tests\Support\QueryTestCase;

use function Diogodg\Neoorm\Query\asc;
use function Diogodg\Neoorm\Query\desc;
use function Diogodg\Neoorm\Query\eq;
use function Diogodg\Neoorm\Query\gt;

/**
 * Agregação contra um banco de verdade, nos dois dialetos.
 *
 * O GROUP BY é onde os dois servidores mais discordam — o MySQL aceita, com a
 * configuração padrão de outrora, selecionar coluna fora do agrupamento; o PostgreSQL
 * nunca aceitou. Um SQL que passe aqui nos dois passa em qualquer lugar.
 */
#[Group('query')]
final class AggregationTest extends QueryTestCase
{
    public function testCountStarOverTheWholeTable(): void
    {
        $total = $this->db()->selectFields([Func::count()])->from(Tables::appointment())->scalar();

        // O COUNT vem como string em pelo menos um dos drivers. Quem consome sabe que
        // pediu um agregado; `selectFields()` não hidrata nem casta, e isso está dito no
        // tipo de retorno (`mixed`) em vez de escondido.
        $this->assertSame(5, (int) $total);
    }

    public function testCountDistinct(): void
    {
        $appointment = Tables::appointment();

        $distintos = $this->db()
            ->selectFields([Func::count($appointment->status, distinct: true)])
            ->from($appointment)
            ->scalar();

        $this->assertSame(3, (int) $distintos, 'confirmed, scheduled e cancelled');
    }

    /**
     * Um GROUP BY simples, com o agregado apelidado — que é o que dá nome à chave no
     * resultado.
     */
    public function testGroupByWithAnAliasedAggregate(): void
    {
        $appointment = Tables::appointment();

        $linhas = $this->db()->selectFields([
            $appointment->status,
            new Aliased(Func::count(), 'total'),
        ])
            ->from($appointment)
            ->groupBy($appointment->status)
            ->orderBy(asc($appointment->status))
            ->all();

        $this->assertCount(3, $linhas);
        $this->assertSame(
            ['cancelled' => 1, 'confirmed' => 3, 'scheduled' => 1],
            array_combine(
                array_map(static fn (array $l): string => (string) $l['status'], $linhas),
                array_map(static fn (array $l): int => (int) $l['total'], $linhas),
            ),
        );
    }

    public function testGroupByMoreThanOneColumn(): void
    {
        $appointment = Tables::appointment();

        $linhas = $this->db()->selectFields([
            $appointment->schedule_id,
            $appointment->status,
            new Aliased(Func::count(), 'total'),
        ])
            ->from($appointment)
            ->groupBy($appointment->schedule_id, $appointment->status)
            ->orderBy(asc($appointment->schedule_id), asc($appointment->status))
            ->all();

        // Manhã tem 2 confirmed; Tarde tem 1 cancelled, 1 confirmed e 1 scheduled.
        $this->assertCount(4, $linhas);
        $this->assertSame(2, (int) $linhas[0]['total']);
    }

    /**
     * HAVING filtra o GRUPO, e é o que o WHERE não consegue: o WHERE roda antes da
     * agregação e não enxerga o COUNT.
     */
    public function testHavingFiltersGroupsAndNotRows(): void
    {
        $appointment = Tables::appointment();

        $linhas = $this->db()->selectFields([
            $appointment->status,
            new Aliased(Func::count(), 'total'),
        ])
            ->from($appointment)
            ->groupBy($appointment->status)
            ->having(gt(Func::count(), 1))
            ->all();

        $this->assertCount(1, $linhas);
        $this->assertSame('confirmed', $linhas[0]['status']);
        $this->assertSame(3, (int) $linhas[0]['total']);
    }

    /**
     * WHERE e HAVING coexistindo: os dois têm binds, e a ordem em que entram no
     * statement é a ordem em que o compilador emite as cláusulas — se estivesse
     * invertida, os valores iriam para os placeholders errados.
     */
    public function testWhereAndHavingBindInTheRightOrder(): void
    {
        $appointment = Tables::appointment();

        $linhas = $this->db()->selectFields([
            $appointment->schedule_id,
            new Aliased(Func::count(), 'total'),
        ])
            ->from($appointment)
            ->where(eq($appointment->status, 'confirmed'))
            ->groupBy($appointment->schedule_id)
            ->having(gt(Func::count(), 1))
            ->all();

        $this->assertCount(1, $linhas, 'só a agenda da manhã tem mais de um confirmado');
        $this->assertSame(2, (int) $linhas[0]['total']);
    }

    public function testGroupByOverAJoin(): void
    {
        $state = Tables::state();
        $city = Tables::city();

        $linhas = $this->db()->selectFields([
            $state->abbreviation,
            new Aliased(Func::count($city->id), 'cidades'),
        ])
            ->from($state)
            ->leftJoin($city, eq($city->state, $state->id))
            ->groupBy($state->abbreviation)
            ->orderBy(desc(Func::count($city->id)), asc($state->abbreviation))
            ->all();

        $this->assertCount(3, $linhas);
        $this->assertSame('SP', $linhas[0]['abbreviation']);
        $this->assertSame(2, (int) $linhas[0]['cidades']);
    }

    public function testSeveralAggregatesInOneQuery(): void
    {
        $city = Tables::city();

        $linha = $this->db()->selectFields([
            new Aliased(Func::count(), 'total'),
            new Aliased(Func::min($city->ibge), 'menor'),
            new Aliased(Func::max($city->ibge), 'maior'),
            new Aliased(Func::sum($city->ibge), 'soma'),
            new Aliased(Func::avg($city->ibge), 'media'),
        ])->from($city)->one();

        $this->assertNotNull($linha);
        $this->assertSame(4, (int) $linha['total']);
        $this->assertSame(3304557, (int) $linha['menor']);
        $this->assertSame(9900001, (int) $linha['maior']);
        $this->assertSame(3550308 + 3509502 + 3304557 + 9900001, (int) $linha['soma']);
        $this->assertGreaterThan(0, (float) $linha['media']);
    }

    /**
     * `count()` no `SelectBuilder` conta LINHAS, e por isso envolve a consulta agrupada
     * numa subconsulta. Sem isso ele devolveria o total de cada grupo, e uma paginação
     * sobre GROUP BY mostraria o número errado de páginas — que era o comportamento da
     * API antiga.
     */
    public function testCountingAGroupedQueryCountsGroups(): void
    {
        $appointment = Tables::appointment();

        $agrupada = $this->db()->selectFields([
            $appointment->status,
            new Aliased(Func::count(), 'total'),
        ])
            ->from($appointment)
            ->groupBy($appointment->status);

        $this->assertCount(3, $agrupada->all());
        $this->assertSame(
            3,
            $this->db()->select()->from($appointment)->groupBy($appointment->status)->count(),
        );
    }

    /**
     * DISTINCT sobre a projeção inteira.
     */
    public function testDistinctCollapsesRepeatedRows(): void
    {
        $appointment = Tables::appointment();

        $status = $this->db()->selectFields([$appointment->status])
            ->from($appointment)
            ->distinct()
            ->orderBy(asc($appointment->status))
            ->column();

        $this->assertSame(['cancelled', 'confirmed', 'scheduled'], $status);
    }

    /**
     * `lower()`/`upper()` existem para o caso em que o banco é case sensitive e a
     * comparação não deveria ser — o mesmo motivo do `ilike()`.
     */
    public function testLowerAndUpperAreDialectNeutral(): void
    {
        $country = Tables::country();

        $nomes = $this->db()->selectFields([new Aliased(Func::upper($country->name), 'nome')])
            ->from($country)
            ->orderBy(asc($country->name))
            ->column();

        $this->assertSame(['ARGENTINA', 'BRASIL'], $nomes);
    }
}
