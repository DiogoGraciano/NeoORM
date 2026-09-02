<?php

declare(strict_types=1);

namespace Tests\Integration\Command;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Command\MigrationContext;
use Diogodg\Neoorm\Migrations\Command\Seed;
use Diogodg\Neoorm\Migrations\Command\Up;
use Diogodg\Neoorm\Migrations\Command\Generate;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Seed\SeederLoader;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\App\SeederRoots\Integration\ItemSeeder;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\BufferedOutput;
use Tests\Support\SchemaFixture;
use Tests\Support\TempMigrations;

/**
 * `db:seed` ponta a ponta, contra o banco.
 *
 * O `SeedRunnerTest` unitário roda sobre um PDO falso: ele prova a ordem, o filtro e o
 * rollback, mas não prova que a transação de verdade desfaz a escrita nem que a ordem
 * topológica é a que a foreign key exige — um duplo aceita qualquer inserção. Aqui o
 * banco recusa.
 *
 * É também o único teste que exercita a FIAÇÃO inteira: `MigrationContext` carregando o
 * `SeederLoader`, o comando lendo o schema, e o runner abrindo a transação.
 */
#[Group('convergence')]
final class SeedTest extends DatabaseTestCase
{
    private TempMigrations $temp;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = new TempMigrations($this->driver());
        $this->output = new BufferedOutput();
        ItemSeeder::$explode = false;

        SchemaRegistry::flush();
        $this->dropTables();
    }

    protected function tearDown(): void
    {
        ItemSeeder::$explode = false;
        $this->dropTables();
        $this->temp->cleanup();
        SchemaRegistry::flush();
        SchemaFixture::forget();

        parent::tearDown();
    }

    private function dropTables(): void
    {
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        // Filho antes do pai: a FK de `zz_item` aponta para `zz_box`.
        foreach (['zz_item', 'zz_box', '_neoorm_seed'] as $table) {
            try {
                $this->pdo()->exec('DROP TABLE IF EXISTS ' . $dialect->quoteIdentifier($table));
            } catch (\Throwable) {
                // Limpeza não pode mascarar a falha do teste.
            }
        }
    }

    private function context(string $seedersSubdirectory = 'Integration'): MigrationContext
    {
        return new MigrationContext(
            DatabaseConfig::fromConfig(),
            DialectFactory::for($this->driver(), Config::getSchema()),
            $this->temp->directory(),
            new ModelSchemaLoader(
                dirname(__DIR__, 2) . '/App/ConvergenceModels',
                'Tests\\App\\ConvergenceModels',
            ),
            new SeederLoader(
                dirname(__DIR__, 2) . '/App/SeederRoots/' . $seedersSubdirectory,
                'Tests\\App\\SeederRoots\\' . $seedersSubdirectory,
            ),
            migrationsTable: '_neoorm_seed',
            output: $this->output,
            strict: true,
            environment: 'dev',
            pdo: $this->pdo(),
        );
    }

    private function createSchema(MigrationContext $context): void
    {
        (new Generate($context))->execute('initial');
        (new Up($context))->execute();
    }

    private function countRows(string $table): int
    {
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());
        $statement = $this->pdo()->query('SELECT COUNT(*) FROM ' . $dialect->quoteIdentifier($table));

        return $statement === false ? -1 : (int) $statement->fetchColumn();
    }

    /**
     * O caminho feliz, e a prova da ordem: `zz_item` referencia `zz_box`, e o seeder da
     * caixa vem antes no grafo apesar de os dois arquivos estarem em ordem alfabética
     * favorável por acaso. Se a ordem viesse do diretório, a foreign key recusaria.
     */
    #[Test]
    public function seedersRunAgainstTheDatabaseInDependencyOrder(): void
    {
        $context = $this->context();
        $this->createSchema($context);

        $seeded = (new Seed($context))->execute();

        $this->assertSame(['zz_box', 'zz_item'], $seeded);
        $this->assertSame(1, $this->countRows('zz_box'));
        $this->assertSame(1, $this->countRows('zz_item'));
    }

    /**
     * O rollback de verdade: `ItemSeeder` escreve E ENTÃO lança.
     *
     * A escrita da caixa aconteceu num seeder anterior e a do item neste mesmo, então as
     * duas só desaparecem se a transação for real e abranger todos os seeders. É o que um
     * PDO falso não consegue afirmar.
     */
    #[Test]
    public function aFailingSeederLeavesNoPartialData(): void
    {
        $context = $this->context();
        $this->createSchema($context);

        ItemSeeder::$explode = true;

        try {
            (new Seed($context))->execute();
            $this->fail('Esperava MigrationException.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('Nenhum dado foi mantido', $e->getMessage());
        }

        $this->assertSame(0, $this->countRows('zz_box'), 'a escrita do seeder anterior tinha que sumir');
        $this->assertSame(0, $this->countRows('zz_item'));
    }

    /** `--tables` chega até o banco: só a tabela pedida recebe dado. */
    #[Test]
    public function onlyTablesLimitsWhatIsWritten(): void
    {
        $context = $this->context();
        $this->createSchema($context);

        $this->assertSame(['zz_box'], (new Seed($context))->execute(['zz_box']));
        $this->assertSame(1, $this->countRows('zz_box'));
        $this->assertSame(0, $this->countRows('zz_item'));
    }

    /**
     * Semear duas vezes é o cenário de `db:reset --seed` seguido de `db:seed`, e sem
     * guarda de idempotência o segundo esbarra na chave primária. O que se afirma é que a
     * falha é REPORTADA e não deixa dado pela metade — o runner não guarda o que já rodou,
     * e a guarda é responsabilidade declarada do seeder.
     */
    #[Test]
    public function seedingTwiceWithoutAGuardFailsLoudlyAndLeavesTheFirstRunIntact(): void
    {
        $context = $this->context();
        $this->createSchema($context);

        (new Seed($context))->execute();

        try {
            (new Seed($context))->execute();
            $this->fail('Esperava MigrationException: os seeders de fixture não são idempotentes.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('Nenhum dado foi mantido', $e->getMessage());
        }

        $this->assertSame(1, $this->countRows('zz_box'));
        $this->assertSame(1, $this->countRows('zz_item'));
    }

    /** Diretório de seeders vazio: nada acontece, e não é erro. */
    #[Test]
    public function aProjectWithoutSeedersIsNotAnError(): void
    {
        $context = $this->context('NaoExiste');
        $this->createSchema($context);

        $this->assertSame([], (new Seed($context))->execute());
        $this->assertStringContainsString('Nenhum seeder', $this->output->text());
    }
}
