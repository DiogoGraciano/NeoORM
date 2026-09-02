<?php

declare(strict_types=1);

namespace Tests\Integration\Command;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Migrations\Command\Generate;
use Diogodg\Neoorm\Migrations\Command\MigrationContext;
use Diogodg\Neoorm\Migrations\Command\Push;
use Diogodg\Neoorm\Migrations\Command\Up;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Concerns\IrAssertions;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\BufferedOutput;
use Tests\Support\SchemaFixture;
use Tests\Support\TempMigrations;

/**
 * Os dois caminhos até o banco produzem o MESMO banco.
 *
 * `db:push` compara os models com o banco introspectado e aplica direto. `migration:generate`
 * mais `migration:up` compara os models com o último snapshot, escreve um `.sql` e o executa.
 * São dois códigos diferentes com dois pontos de partida diferentes, e o resultado tem que ser
 * indistinguível.
 *
 * Por que isto merece um teste próprio: os fixtures da suíte usam `push` — é o que
 * `SchemaFixture` faz — enquanto as pessoas usam `generate` + `up`. Se os dois pudessem
 * divergir, a suíte inteira estaria testando um schema que nenhum usuário tem, e a diferença
 * só apareceria em produção.
 *
 * A comparação é entre os dois bancos INTROSPECTADOS, não entre os SQLs gerados. O SQL pode
 * legitimamente diferir — `push` cria tudo de uma vez enquanto `up` pode ter vindo de várias
 * migrações — e o que precisa ser igual é o estado final.
 */
#[Group('convergence')]
final class PushEqualsMigrateTest extends DatabaseTestCase
{
    use IrAssertions;

    private TempMigrations $temp;

    private BufferedOutput $output;

    private const MODELS_ROOT = __DIR__ . '/../../App/ConvergenceModelsV2';

    private const MODELS_NAMESPACE = 'Tests\\App\\ConvergenceModelsV2';

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = new TempMigrations($this->driver());
        $this->output = new BufferedOutput();

        SchemaRegistry::flush();
        $this->dropTables();
    }

    protected function tearDown(): void
    {
        $this->dropTables();
        $this->temp->cleanup();
        SchemaRegistry::flush();
        SchemaFixture::forget();

        parent::tearDown();
    }

    private function dropTables(): void
    {
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        foreach (['zz_item', 'zz_box', '_neoorm_pushequals'] as $table) {
            try {
                $this->pdo()->exec('DROP TABLE IF EXISTS ' . $dialect->quoteIdentifier($table));
            } catch (\Throwable) {
                // Limpeza não pode mascarar a falha do teste.
            }
        }
    }

    private function context(): MigrationContext
    {
        return new MigrationContext(
            DatabaseConfig::fromConfig(),
            DialectFactory::for($this->driver(), Config::getSchema()),
            $this->temp->directory(),
            new ModelSchemaLoader(self::MODELS_ROOT, self::MODELS_NAMESPACE),
            migrationsTable: '_neoorm_pushequals',
            output: $this->output,
            pdo: $this->pdo(),
        );
    }

    #[Test]
    public function pushAndMigrateProduceTheSameDatabase(): void
    {
        $tables = ['zz_box', 'zz_item'];

        // Caminho A: push direto.
        (new Push($this->context()))->execute();
        $afterPush = $this->context()->introspector()->introspect($tables);

        $this->dropTables();

        // Caminho B: generate + up, num diretório de migrações limpo.
        $this->temp->cleanup();
        $this->temp = new TempMigrations($this->driver());

        $context = $this->context();
        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $afterMigrate = $this->context()->introspector()->introspect($tables);

        $this->assertSchemaEquals(
            $afterPush->schema,
            $afterMigrate->schema,
            'push e generate+up têm que deixar o banco no MESMO estado. Se divergirem, a suíte '
            . 'inteira — que usa push nos fixtures — estaria testando um schema que nenhum usuário tem.',
        );

        // E a asserção semântica, pela mesma razão do P3: a igualdade de forma pode passar por
        // cima de um campo que o differ ignora e não deveria.
        $this->assertNoOperations(
            (new SchemaDiffer())->diff($afterPush, $afterMigrate),
            'O differ não pode ver diferença entre os dois caminhos.',
        );
    }

    /**
     * E aplicar `push` sobre um banco que veio de `up` não encontra nada a fazer.
     *
     * É a mesma pergunta pelo outro lado, e é a que pega assimetria de comparação: se `push`
     * enxergasse o banco de um jeito e `generate` de outro, este caso acusaria operações sobre
     * um banco que acabou de ser construído pelo caminho oficial.
     */
    #[Test]
    public function pushOverAMigratedDatabaseHasNothingToDo(): void
    {
        $context = $this->context();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $push = (new Push($this->context()))->execute(dryRun: true);

        $this->assertTrue(
            $push->nothingToDo(),
            "push encontrou trabalho num banco recém-migrado:\n  "
            . implode("\n  ", $push->operations->describe()),
        );
    }

    /**
     * E o contrário: `generate` sobre um banco construído por `push` também não gera nada.
     *
     * Note que aqui o `generate` compara models com SNAPSHOT, não com o banco — então o que se
     * afirma é que o snapshot escrito descreve o mesmo que o `push` construiu.
     */
    #[Test]
    public function migrateOverAPushedDatabaseConverges(): void
    {
        (new Push($this->context()))->execute();

        $context = $this->context();
        (new Generate($context))->execute('initial');

        // O `up` tem que ser inofensivo: o banco já tem tudo. Como não há `IF NOT EXISTS` no
        // DDL gerado, ele FALHA — e é o comportamento certo, porque um push seguido de up é
        // pedir para criar duas vezes. O que se afirma é a convergência do snapshot, não que
        // os dois caminhos sejam intercambiáveis a meio do percurso.
        $check = (new \Diogodg\Neoorm\Migrations\Command\Check($this->context()))->execute();

        $this->assertCount(
            0,
            $check->modelsVsSnapshot,
            'O snapshot escrito pelo generate tem que descrever exatamente os models.',
        );
        $this->assertCount(
            0,
            $check->snapshotVsLive,
            'E o banco construído pelo push tem que corresponder a esse snapshot.',
        );
    }
}
