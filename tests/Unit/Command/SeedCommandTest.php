<?php

declare(strict_types=1);

namespace Tests\Unit\Command;

use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Command\MigrationContext;
use Diogodg\Neoorm\Migrations\Command\Seed;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Seed\SeederLoader;
use Diogodg\Neoorm\Migrations\Snapshot\MigrationDirectory;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Doubles\BufferedOutput;
use Tests\Support\UnitTestCase;

/**
 * A guarda contra `Model::seed()` sobrevivente.
 *
 * O método saiu de `Abstract\Model`, e o modo de falha natural de um método removido de
 * classe-base é o pior possível: ele simplesmente deixa de ser chamado. Os dados não
 * aparecem, o comando diz "nenhum seeder" e nada aponta a causa. Um `method_exists` por
 * model converte isso numa lista do que migrar.
 *
 * Roda sem banco porque a checagem acontece ANTES de o runner abrir conexão, e o
 * `UnitTestCase` reprova qualquer teste que abra uma.
 */
final class SeedCommandTest extends UnitTestCase
{
    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->output = new BufferedOutput();
        SchemaRegistry::flush();
    }

    protected function tearDown(): void
    {
        SchemaRegistry::flush();

        parent::tearDown();
    }

    private function context(string $modelsSubdirectory): MigrationContext
    {
        $root = dirname(__DIR__, 2) . '/App/SchemaModels/' . $modelsSubdirectory;

        return new MigrationContext(
            // Uma configuração que NUNCA é usada: este caminho não abre conexão.
            new DatabaseConfig('pgsql', 'inexistente', '5432', 'inexistente', 'ninguem', '', 'utf8'),
            DialectFactory::for('pgsql'),
            new MigrationDirectory(sys_get_temp_dir() . '/neoorm-seed-command', 'pgsql'),
            new ModelSchemaLoader($root, 'Tests\\App\\SchemaModels\\' . $modelsSubdirectory),
            new SeederLoader('/nao/existe', 'Nada'),
            output: $this->output,
        );
    }

    #[Test]
    public function aModelThatStillDeclaresSeedStopsTheCommand(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/Model::seed\(\) não existe mais/');

        (new Seed($this->context('LegacySeed')))->execute();
    }

    /** E a mensagem diz qual arquivo criar, não só que algo está errado. */
    #[Test]
    public function theMessageNamesTheSeederToCreate(): void
    {
        try {
            (new Seed($this->context('LegacySeed')))->execute();
            $this->fail('Esperava MigrationException.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('Tests\\App\\SchemaModels\\LegacySeed\\Legacy::seed()', $e->getMessage());
            $this->assertStringContainsString('LegacySeeder', $e->getMessage());
        }
    }

    /** Sem model legado, o comando segue e diz onde procurou. */
    #[Test]
    public function withoutLegacyModelsItReportsWhereItLooked(): void
    {
        $seeded = (new Seed($this->context('Recursive')))->execute();

        $this->assertSame([], $seeded);
        $this->assertStringContainsString('/nao/existe', $this->output->text());
    }
}
