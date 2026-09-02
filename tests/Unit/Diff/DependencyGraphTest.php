<?php

declare(strict_types=1);

namespace Tests\Unit\Diff;

use Diogodg\Neoorm\Migrations\Diff\DependencyGraph;
use Tests\Support\Factory\EdgeCaseSchemas;
use Tests\Support\Factory\Ir;
use Tests\Support\UnitTestCase;

final class DependencyGraphTest extends UnitTestCase
{
    public function testDependenciesComeBeforeDependents(): void
    {
        $graph = DependencyGraph::fromSchema(Ir::geographySchema());

        $this->assertSame(['country', 'state', 'city'], $graph->sort());
    }

    public function testReverseIsTheRemovalOrder(): void
    {
        $graph = DependencyGraph::fromSchema(Ir::geographySchema());

        $this->assertSame(['city', 'state', 'country'], $graph->reverse());
    }

    /**
     * Nomes de arquivo em ordem inversa da dependência. É a prova de que a ordem vem
     * do grafo — e a ordem alfabética era exatamente o que o sistema antigo usava.
     */
    public function testOrderComesFromTheGraphNotFromTheName(): void
    {
        $graph = DependencyGraph::fromSchema(EdgeCaseSchemas::get('deep_chain'));

        $this->assertSame(
            [
                'chain_10', 'chain_09', 'chain_08', 'chain_07', 'chain_06',
                'chain_05', 'chain_04', 'chain_03', 'chain_02', 'chain_01',
            ],
            $graph->sort(),
        );
    }

    /**
     * Ciclo não lança: foreign key mútua é SQL legítimo, e transformar um schema
     * válido em erro seria pior que escolher uma ordem arbitrária. O desempate é pelo
     * menor nome, o que faz a escolha ser sempre a mesma.
     */
    public function testACycleIsBrokenByTheSmallestNameInsteadOfThrowing(): void
    {
        $graph = DependencyGraph::fromSchema(EdgeCaseSchemas::get('cyclic'));

        $this->assertTrue($graph->hasCycle());
        $this->assertSame(['cyclic_a', 'cyclic_b'], $graph->sort());
        $this->assertSame($graph->sort(), $graph->sort());
    }

    /**
     * Auto-referência não é ciclo: uma tabela não precisa existir antes de si mesma, e
     * manter a aresta criaria um ciclo trivial em todo model com FK para a própria
     * tabela.
     */
    public function testSelfReferenceIsNotACycle(): void
    {
        $graph = DependencyGraph::fromSchema(EdgeCaseSchemas::get('self_reference'));

        $this->assertFalse($graph->hasCycle());
        $this->assertSame(['category'], $graph->sort());
        $this->assertSame([], $graph->dependencies['category']);
    }

    public function testAnAcyclicGraphReportsNoCycle(): void
    {
        $this->assertFalse(DependencyGraph::fromSchema(Ir::geographySchema())->hasCycle());
    }

    public function testAnEmptyGraphSortsToNothing(): void
    {
        $graph = new DependencyGraph([]);

        $this->assertSame([], $graph->sort());
        $this->assertSame([], $graph->reverse());
        $this->assertFalse($graph->hasCycle());
    }

    /**
     * Aresta para nó que não está no grafo é descartada. Não há nada para criar antes,
     * e mantê-la faria o sort depender de um nó inexistente — quem reclama de FK para
     * tabela ausente é o SchemaValidator, com o nome da FK.
     */
    public function testEdgesToUnknownNodesAreDropped(): void
    {
        $graph = new DependencyGraph(['a' => ['fantasma'], 'b' => ['a']]);

        $this->assertSame([], $graph->dependencies['a']);
        $this->assertSame(['a', 'b'], $graph->sort());
    }

    public function testDuplicateEdgesAreCollapsed(): void
    {
        $graph = new DependencyGraph(['a' => [], 'b' => ['a', 'a', 'a']]);

        $this->assertSame(['a'], $graph->dependencies['b']);
    }

    /**
     * `orderOf` ordena um subconjunto: a lista de tabelas a criar num diff nunca é o
     * schema inteiro.
     */
    public function testASubsetIsOrderedAccordingToTheFullGraph(): void
    {
        $graph = DependencyGraph::fromSchema(Ir::geographySchema());

        $this->assertSame(['country', 'city'], $graph->orderOf(['city', 'country']));
        $this->assertSame(['state'], $graph->orderOf(['state']));
    }

    /**
     * Nome fora do grafo vai para o fim, em ordem alfabética, em vez de desaparecer
     * em silêncio.
     */
    public function testNamesOutsideTheGraphGoToTheEndInsteadOfVanishing(): void
    {
        $graph = DependencyGraph::fromSchema(Ir::geographySchema());

        $this->assertSame(
            ['country', 'city', 'outra', 'zebra'],
            $graph->orderOf(['zebra', 'city', 'outra', 'country']),
        );
    }

    public function testTheGraphIsStableAcrossEquivalentConstructions(): void
    {
        $first = new DependencyGraph(['b' => ['a'], 'a' => []]);
        $second = new DependencyGraph(['a' => [], 'b' => ['a']]);

        $this->assertSame($first->dependencies, $second->dependencies);
        $this->assertSame($first->sort(), $second->sort());
    }
}
