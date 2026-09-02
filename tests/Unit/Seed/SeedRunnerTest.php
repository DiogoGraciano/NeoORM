<?php

declare(strict_types=1);

namespace Tests\Unit\Seed;

use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Seed\SeedRunner;
use Diogodg\Neoorm\Migrations\Seed\SeederLoader;
use Diogodg\Neoorm\Query\Database;
use Diogodg\Neoorm\Query\Tx;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\App\SeederRoots\Valid\InvoiceSeeder;
use Tests\App\SeederRoots\Valid\Nested\LineSeeder;
use Tests\App\SeederRoots\Valid\RootSeeder;
use Tests\Support\Doubles\FakePdo;
use Tests\Support\UnitTestCase;

/**
 * O runner dos seeders, sem banco de verdade.
 *
 * Um `Database` sobre `FakePdo` dá um `Tx` real com um PDO falso, o que é exatamente o
 * que estes testes precisam: a transação existe e é observável, mas nada trafega. O
 * runner não tinha teste direto nenhum antes desta fase.
 */
final class SeedRunnerTest extends UnitTestCase
{
    private FakePdo $pdo;

    protected function setUp(): void
    {
        parent::setUp();

        SchemaRegistry::flush();
        RootSeeder::$ran = [];
        InvoiceSeeder::$seen = null;
        LineSeeder::$explode = false;

        $this->pdo = new FakePdo();
    }

    protected function tearDown(): void
    {
        RootSeeder::$ran = [];
        InvoiceSeeder::$seen = null;
        LineSeeder::$explode = false;
        SchemaRegistry::flush();

        parent::tearDown();
    }

    private function schema(): SchemaDefinition
    {
        return (new ModelSchemaLoader(
            __DIR__ . '/../../App/SchemaModels/Recursive',
            'Tests\\App\\SchemaModels\\Recursive',
        ))->load();
    }

    private function loader(string $subdirectory = 'Valid'): SeederLoader
    {
        return new SeederLoader(
            __DIR__ . '/../../App/SeederRoots/' . $subdirectory,
            'Tests\\App\\SeederRoots\\' . $subdirectory,
        );
    }

    /** Quantas vezes o PDO falso viu um marcador de transação. */
    private function times(string $marker): int
    {
        return count(array_filter($this->pdo->executed, static fn (string $s): bool => $s === $marker));
    }

    private function runner(?SeederLoader $loader = null): SeedRunner
    {
        return new SeedRunner(
            $this->schema(),
            $loader ?? $this->loader(),
            executor: new Database($this->pdo, DialectFactory::for('pgsql')),
        );
    }

    /**
     * A ordem vem do grafo de foreign keys, não do nome do arquivo.
     *
     * `RootSeeder` é o ÚLTIMO em ordem alfabética e tem que rodar PRIMEIRO, porque as
     * outras duas tabelas dependem de `rec_root`. Antes a ordem era alfabética: `Country`
     * vir antes de `State` funcionava por acidente, e qualquer par cuja dependência não
     * seguisse o alfabeto falhava.
     */
    #[Test]
    public function seedersRunInDependencyOrder(): void
    {
        $seeded = $this->runner()->run();

        $this->assertSame(['rec_root', 'rec_invoice', 'rec_line'], $seeded);
        $this->assertSame(['rec_root', 'rec_invoice', 'rec_line'], RootSeeder::$ran);
    }

    /**
     * O executor entregue ao seeder é o `Tx` da transação.
     *
     * É a correção silenciosa da mudança: antes cada `seed()` chamava
     * `Database::fromConfig()` por conta própria e só por acidente compartilhava o PDO da
     * transação — bastava passar um `DatabaseConfig` para a escrita cair fora dela e
     * sobreviver a um rollback.
     */
    #[Test]
    public function theSeederReceivesTheTransactionExecutor(): void
    {
        $this->runner()->run();

        $this->assertInstanceOf(Tx::class, InvoiceSeeder::$seen);
    }

    #[Test]
    public function everythingRunsInsideOneTransaction(): void
    {
        $this->runner()->run();

        $this->assertFalse($this->pdo->inTransaction());
        $this->assertSame(1, $this->times('BEGIN'), 'uma transação para todos os seeders');
        $this->assertSame(1, $this->times('COMMIT'));
    }

    /**
     * Um seeder que lança desfaz tudo, e a exceção diz que nada foi mantido.
     */
    #[Test]
    public function aFailingSeederRollsBackEverything(): void
    {
        LineSeeder::$explode = true;

        try {
            $this->runner()->run();
            $this->fail('Esperava MigrationException.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('seed quebrou no meio', $e->getMessage());
            $this->assertStringContainsString('Nenhum dado foi mantido', $e->getMessage());
        }

        $this->assertSame(0, $this->times('COMMIT'));
        $this->assertSame(1, $this->times('ROLLBACK'));
    }

    /** `--tables` filtra por tabela, preservando a ordem do grafo entre as escolhidas. */
    #[Test]
    public function onlyTablesFiltersWithoutBreakingTheOrder(): void
    {
        $seeded = $this->runner()->run(['rec_line', 'rec_root']);

        $this->assertSame(['rec_root', 'rec_line'], $seeded);
    }

    /** Um filtro que não casa com nada não abre transação nenhuma. */
    #[Test]
    public function anEmptySelectionDoesNothing(): void
    {
        $this->assertSame([], $this->runner()->run(['rec_decoy']));
        $this->assertSame(0, $this->times('BEGIN'));
    }

    /** Projeto sem seeder: nada acontece, e não é erro. */
    #[Test]
    public function noSeedersMeansNoTransaction(): void
    {
        $runner = $this->runner(new SeederLoader('/nao/existe', 'Nada'));

        $this->assertSame([], $runner->run());
        $this->assertSame(0, $this->times('BEGIN'));
    }
}
