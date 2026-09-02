<?php

declare(strict_types=1);

namespace Tests\Unit\Snapshot;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Migrations\Snapshot\SnapshotMeta;
use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Factory\Ir;
use Tests\Support\UnitTestCase;

final class SnapshotTest extends UnitTestCase
{
    /**
     * O id ordena lexicograficamente na mesma sequência em que ordena numericamente.
     * É o que faz `sort()` de nomes de arquivo e `ORDER BY tag` no banco concordarem
     * sem ninguém precisar converter nada.
     */
    public function testIdsAreZeroPaddedToFourDigits(): void
    {
        $this->assertSame('0000', Snapshot::formatId(0));
        $this->assertSame('0009', Snapshot::formatId(9));
        $this->assertSame('0042', Snapshot::formatId(42));
        $this->assertSame('9999', Snapshot::formatId(9999));

        // Passando de 9999 o id cresce em vez de estourar, e a ordem lexicográfica
        // continua correta porque todos os ids acima de 9999 têm mais dígitos.
        $this->assertSame('10000', Snapshot::formatId(10000));
    }

    public function testIdsSortLexicographicallyInNumericOrder(): void
    {
        $ids = array_map(Snapshot::formatId(...), [10, 2, 100, 1, 0]);
        sort($ids, SORT_STRING);

        $this->assertSame(['0000', '0001', '0002', '0010', '0100'], $ids);
    }

    #[DataProvider('invalidIds')]
    public function testAnInvalidIdIsRefused(string $id): void
    {
        $this->expectException(MigrationException::class);

        new Snapshot('pgsql', $id, null, SchemaDefinition::empty());
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function invalidIds(): iterable
    {
        yield 'vazio' => [''];
        yield 'curto' => ['1'];
        yield 'com letra' => ['000a'];
        yield 'com sinal' => ['-001'];
        yield 'com espaço no meio' => ['00 1'];
    }

    public function testANegativeIndexIsRefused(): void
    {
        $this->expectException(MigrationException::class);

        Snapshot::formatId(-1);
    }

    /**
     * A cadeia é estritamente crescente. Um snapshot que aponte para si mesmo, ou
     * para um sucessor, é uma cadeia que o runner não consegue percorrer.
     */
    public function testTheChainMustBeStrictlyIncreasing(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/estritamente crescente/');

        new Snapshot('pgsql', '0001', '0001', SchemaDefinition::empty());
    }

    public function testTheDialectMustBeOneOfTheTwoCanonicalSpellings(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/DialectFactory/');

        new Snapshot('sqlite', '0000', null, SchemaDefinition::empty());
    }

    public function testTheDialectIsNormalizedToLowercase(): void
    {
        $this->assertSame('pgsql', new Snapshot('PgSQL', '0000', null, SchemaDefinition::empty())->dialect);
    }

    /**
     * A primeira migração é `diff(baseline, snapshot0)`, e não um caminho especial de
     * código. Um caminho que só roda uma vez por projeto é um caminho que nunca é
     * testado de verdade — que é onde o sistema antigo escondia a maior parte dos
     * seus bugs.
     */
    public function testTheBaselineIsAnOrdinarySnapshotWithNothingInIt(): void
    {
        $baseline = Snapshot::baseline('mysql');

        $this->assertTrue($baseline->isEmpty());
        $this->assertSame('0000', $baseline->id);
        $this->assertNull($baseline->prevId);
        $this->assertSame([], $baseline->tables());
    }

    public function testNextAdvancesTheChain(): void
    {
        $first = Snapshot::initial('pgsql', Ir::geographySchema());
        $second = $first->next(Ir::geographySchema());

        $this->assertSame('0001', $second->id);
        $this->assertSame('0000', $second->prevId);
        $this->assertSame(1, $second->index());
        $this->assertSame('pgsql', $second->dialect);
    }

    public function testNextCarriesTheDecisionThatProducedTheTransition(): void
    {
        $second = Snapshot::initial('pgsql', Ir::geographySchema())
            ->next(Ir::geographySchema(), new SnapshotMeta(['city' => 'town']));

        $this->assertSame('town', $second->meta->renamedTable('city'));
    }

    /**
     * `hasSameSchema` pergunta "este schema é o mesmo?", não "este arquivo é o mesmo?":
     * id, prevId e meta ficam de fora. É o que `db:check` e o teste de convergência
     * precisam saber.
     */
    public function testSameSchemaIgnoresPositionInTheChainAndTheRenameRecord(): void
    {
        $first = new Snapshot('pgsql', '0000', null, Ir::geographySchema());
        $later = new Snapshot(
            'pgsql',
            '0007',
            '0006',
            Ir::geographySchema(),
            new SnapshotMeta(['a' => 'b']),
        );

        $this->assertTrue($first->hasSameSchema($later));
    }

    public function testSameSchemaIsFalseAcrossDialects(): void
    {
        $mysql = Snapshot::initial('mysql', Ir::geographySchema());
        $pgsql = Snapshot::initial('pgsql', Ir::geographySchema());

        $this->assertFalse($mysql->hasSameSchema($pgsql));
    }

    public function testWithSchemaAndWithMetaDoNotMutate(): void
    {
        $original = Snapshot::initial('pgsql', Ir::geographySchema());

        $emptied = $original->withSchema(SchemaDefinition::empty());
        $tagged = $original->withMeta(new SnapshotMeta(['a' => 'b']));

        $this->assertFalse($original->isEmpty());
        $this->assertTrue($emptied->isEmpty());
        $this->assertTrue($original->meta->isEmpty());
        $this->assertFalse($tagged->meta->isEmpty());
    }

    public function testTableLookupIsCaseInsensitive(): void
    {
        $snapshot = Snapshot::initial('pgsql', Ir::geographySchema());

        $this->assertNotNull($snapshot->table('City'));
        $this->assertNull($snapshot->table('inexistente'));
    }

    // ------------------------------------------------------------ SnapshotMeta

    public function testMetaNormalizesAndSortsItsKeys(): void
    {
        $meta = new SnapshotMeta(
            ['Zebra' => 'Alpha', 'City' => 'Town'],
            ['Zebra.Old' => 'New', 'City.Label' => 'Name'],
        );

        $this->assertSame(['city' => 'town', 'zebra' => 'alpha'], $meta->renamedTables);
        $this->assertSame(['city.label' => 'name', 'zebra.old' => 'new'], $meta->renamedColumns);
    }

    public function testMetaLooksUpRenamesCaseInsensitively(): void
    {
        $meta = new SnapshotMeta(['city' => 'town'], ['city.label' => 'name']);

        $this->assertSame('town', $meta->renamedTable('City'));
        $this->assertSame('name', $meta->renamedColumn('CITY', 'Label'));
        $this->assertNull($meta->renamedTable('outra'));
        $this->assertNull($meta->renamedColumn('city', 'outra'));
    }

    public function testColumnRenamesCanBeReadPerTable(): void
    {
        $meta = new SnapshotMeta([], ['city.label' => 'name', 'city.old' => 'new', 'state.x' => 'y']);

        $this->assertSame(['label' => 'name', 'old' => 'new'], $meta->columnRenamesFor('city'));
        $this->assertSame(['x' => 'y'], $meta->columnRenamesFor('state'));
        $this->assertSame([], $meta->columnRenamesFor('outra'));
    }

    /**
     * A chave plana `tabela.coluna` é o que permite um `ksort` só deixar o JSON
     * estável; um mapa aninhado exigiria ordenar em dois níveis.
     */
    public function testAMalformedColumnKeyIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches("/'tabela.coluna'/");

        new SnapshotMeta([], ['semponto' => 'novo']);
    }

    public function testDangerousNamesNeverEnterTheMeta(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        new SnapshotMeta(['city' => 'town; DROP TABLE users']);
    }

    public function testMetaAccumulatesWithoutMutating(): void
    {
        $original = SnapshotMeta::none();

        $withTable = $original->withTableRename('city', 'town');
        $withColumn = $withTable->withColumnRename('town', 'label', 'name');

        $this->assertTrue($original->isEmpty());
        $this->assertSame('town', $withTable->renamedTable('city'));
        $this->assertNull($withTable->renamedColumn('town', 'label'));
        $this->assertSame('name', $withColumn->renamedColumn('town', 'label'));
        $this->assertSame('town', $withColumn->renamedTable('city'));
    }
}
