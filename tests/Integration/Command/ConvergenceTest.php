<?php

declare(strict_types=1);

namespace Tests\Integration\Command;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Migrations\Command\Check;
use Diogodg\Neoorm\Migrations\Command\Generate;
use Diogodg\Neoorm\Migrations\Command\MigrationContext;
use Diogodg\Neoorm\Migrations\Command\Status;
use Diogodg\Neoorm\Migrations\Command\Up;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Snapshot\MigrationDirectory;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\BufferedOutput;
use Tests\Support\SchemaFixture;
use Tests\Support\TempMigrations;

/**
 * P4 — convergência. O gate desta fase.
 *
 * O ciclo completo, pelas mesmas classes que o CLI chama: `generate`, `up`, e então um
 * segundo `generate` que tem que sair VAZIO e um `db:check` que tem que sair limpo.
 *
 * Por que isto não é redundante com o P3: um round-trip só de criação-do-zero passa com os
 * bugs B2 e B4 presentes. B2 — o diff fantasma de collation — só aparece na SEGUNDA
 * execução, quando o sistema compara o que acabou de criar com o que declarou e discorda de
 * si mesmo. B4 — foreign key duplicada — só aparece quando algo é aplicado duas vezes. É o
 * ciclo, e não a criação, que pega os dois.
 *
 * E o ciclo INCREMENTAL (v1 → up → generate vazio → muda o model → generate → up → check)
 * pega uma terceira classe de bug: a de um `ALTER` gerado corretamente cujo resultado no
 * banco não é o que o snapshot afirma.
 */
#[Group('convergence')]
final class ConvergenceTest extends DatabaseTestCase
{
    private TempMigrations $temp;

    private BufferedOutput $output;

    private string $modelsRoot = '';

    private string $modelsNamespace = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = new TempMigrations($this->driver());
        $this->output = new BufferedOutput();
        $this->modelsRoot = dirname(__DIR__, 2) . '/App/ConvergenceModels';
        $this->modelsNamespace = 'Tests\\App\\ConvergenceModels';

        SchemaRegistry::flush();
        $this->dropConvergenceTables();
    }

    protected function tearDown(): void
    {
        $this->dropConvergenceTables();
        $this->temp->cleanup();
        SchemaRegistry::flush();

        // O schema de domínio precisa voltar para a classe seguinte: esta classe derruba
        // tabelas e a tabela de controle.
        SchemaFixture::forget();

        parent::tearDown();
    }

    private function dropConvergenceTables(): void
    {
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        // Filho antes do pai: a FK de `zz_item` aponta para `zz_box`.
        foreach (['zz_item', 'zz_box', '_neoorm_convergence'] as $table) {
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
            new ModelSchemaLoader($this->modelsRoot, $this->modelsNamespace),
            migrationsTable: '_neoorm_convergence',
            output: $this->output,
            strict: true,
            environment: 'dev',
            pdo: $this->pdo(),
        );
    }

    /**
     * O roteiro do plano, linha por linha:
     *
     * ```
     * migration:generate initial   → escreve 0000_initial.sql + snapshot + journal
     * migration:up                 → aplica
     * migration:generate           → DEVE dizer "nada a gerar"
     * db:check                     → DEVE sair limpo
     * ```
     */
    #[Test]
    public function generateThenUpThenGenerateIsEmpty(): void
    {
        $context = $this->context();

        $first = (new Generate($context))->execute('initial');

        $this->assertFalse($first->nothingToDo());
        $this->assertSame(0, $first->index);
        $this->assertSame('initial', $first->tag);
        $this->assertFileExists($this->temp->directory()->sqlPath(0, 'initial'));
        $this->assertFileExists($this->temp->directory()->snapshotPath(0));

        $up = (new Up($context))->execute();

        $this->assertSame(['initial'], $up->tags());

        // A convergência: o mesmo generate, agora, não tem nada a fazer.
        $second = (new Generate($this->context()))->execute();

        $this->assertTrue(
            $second->nothingToDo(),
            "O segundo generate tinha que estar vazio e veio com:\n  "
            . implode("\n  ", $second->describe()),
        );

        // E nada foi escrito: um `generate` vazio não cria arquivo.
        $this->assertSame(['initial'], $this->temp->directory()->journal()->tags());

        $check = (new Check($this->context()))->execute();

        $this->assertTrue(
            $check->isClean(),
            "db:check tinha que estar limpo e veio com:\n  " . implode("\n  ", $check->describe()),
        );
    }

    /**
     * Reaplicar depois de convergir continua no-op.
     *
     * Regressão de B4: o sistema antigo rodava `ALTER TABLE ADD CONSTRAINT` sem idempotência
     * para todas as tabelas, e a segunda execução do migrate estourava com foreign key
     * duplicada.
     */
    #[Test]
    public function upTwiceIsANoOp(): void
    {
        $context = $this->context();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $second = (new Up($this->context()))->execute();

        $this->assertTrue($second->isEmpty());
    }

    /**
     * O ciclo INCREMENTAL, que é o que distingue este teste de um round-trip de criação.
     *
     * v1 aplicado e convergido; muda o model — acrescenta coluna e um índice de UMA coluna,
     * que é justamente o que B1/B8 tornavam impossível —; gera, aplica, e afirma que
     * convergiu de novo.
     */
    #[Test]
    public function theIncrementalCycleConverges(): void
    {
        $context = $this->context();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        // A "mudança no model" é um root de models diferente: os fixtures são arquivos, e
        // trocar de root é o equivalente honesto a editar o model, sem reescrever arquivo do
        // repositório no meio de um teste.
        $this->modelsRoot = dirname(__DIR__, 2) . '/App/ConvergenceModelsV2';
        $this->modelsNamespace = 'Tests\\App\\ConvergenceModelsV2';
        SchemaRegistry::flush();

        $second = (new Generate($this->context()))->execute('add_label_and_index');

        $this->assertFalse($second->nothingToDo());
        $this->assertSame(1, $second->index);

        $descriptions = implode("\n", $second->describe());

        $this->assertStringContainsString('zz_item.label', $descriptions);
        $this->assertStringContainsString('zz_item_label_index', $descriptions);

        $up = (new Up($this->context()))->execute();

        $this->assertSame(['add_label_and_index'], $up->tags());

        $third = (new Generate($this->context()))->execute();

        $this->assertTrue(
            $third->nothingToDo(),
            "Depois do ciclo incremental o generate tinha que estar vazio e veio com:\n  "
            . implode("\n  ", $third->describe()),
        );

        $check = (new Check($this->context()))->execute();

        $this->assertTrue(
            $check->isClean(),
            "db:check tinha que estar limpo e veio com:\n  " . implode("\n  ", $check->describe()),
        );
    }

    /**
     * Um índice de UMA coluna é criado. Regressão de B1 e B8.
     *
     * O sistema antigo tinha dois bugs somados aqui: `TableMysql` recusava explicitamente
     * `count($columns) < 2`, e `SchemaExtractor` devolvia lista onde o comparador esperava
     * mapa, o que gravava o nome do índice como `"0"` e `"1"` — de modo que `CREATE INDEX`
     * nunca era emitido em `update()`.
     */
    #[Test]
    public function aSingleColumnIndexReachesTheDatabase(): void
    {
        $this->modelsRoot = dirname(__DIR__, 2) . '/App/ConvergenceModelsV2';
        $this->modelsNamespace = 'Tests\\App\\ConvergenceModelsV2';

        $context = $this->context();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $live = $this->context()->introspector()->introspect(['zz_item']);
        $table = $live->table('zz_item');

        $this->assertNotNull($table);
        $this->assertArrayHasKey('zz_item_label_index', $table->indexes);
        $this->assertSame(['label'], $table->indexes['zz_item_label_index']->columns);
    }

    /**
     * `status` conta certo depois do ciclo.
     */
    #[Test]
    public function statusReportsTheAppliedMigrations(): void
    {
        $context = $this->context();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $status = (new Status($this->context()))->execute();

        $this->assertSame(['initial'], array_map(fn ($l) => $l->tag, $status->applied()));
        $this->assertSame([], $status->pendingTags());
        $this->assertTrue($status->isClean());
    }

    /**
     * `check` acusa quando o model mudou e ninguém rodou `generate`.
     *
     * É o eixo 1 do drift, o erro mais comum, e o único que não envolve banco nenhum: o
     * repositório está inconsistente consigo mesmo.
     */
    #[Test]
    public function checkCatchesAModelChangedWithoutGenerate(): void
    {
        $context = $this->context();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $this->modelsRoot = dirname(__DIR__, 2) . '/App/ConvergenceModelsV2';
        $this->modelsNamespace = 'Tests\\App\\ConvergenceModelsV2';
        SchemaRegistry::flush();

        $check = (new Check($this->context()))->execute();

        $this->assertFalse($check->isClean());
        $this->assertGreaterThan(0, count($check->modelsVsSnapshot));
        $this->assertCount(0, $check->snapshotVsLive, 'O banco corresponde ao snapshot; é o model que mudou.');
    }

    /**
     * `check` acusa quando o BANCO derivou — alguém rodou DDL à mão.
     *
     * É o eixo 2, e é o que o sistema antigo não conseguia ver de jeito nenhum: ele comparava
     * o model com as tabelas `_schema_*`, que registravam o que o CÓDIGO afirmou ter feito.
     * Uma coluna apagada à mão era invisível.
     */
    #[Test]
    public function checkCatchesADatabaseThatDrifted(): void
    {
        $context = $this->context();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $dialect = DialectFactory::for($this->driver(), Config::getSchema());
        $this->pdo()->exec(sprintf(
            'ALTER TABLE %s DROP COLUMN %s',
            $dialect->quoteIdentifier('zz_item'),
            $dialect->quoteIdentifier('quantity'),
        ));

        $check = (new Check($this->context()))->execute();

        $this->assertFalse($check->isClean());
        $this->assertCount(0, $check->modelsVsSnapshot, 'Os models não mudaram.');
        $this->assertStringContainsString(
            'quantity',
            implode("\n", $check->snapshotVsLive->describe()),
        );
    }

    /**
     * Uma tabela que nenhum model descreve é REPORTADA e não recebe DROP.
     *
     * O oposto exato de B3, onde uma tabela pré-existente era considerada sincronizada para
     * sempre. E o oposto do excesso contrário: apagar o que não se reconhece seria estrago
     * irreversível a partir de uma suposição — a tabela pode ser de outro sistema que
     * compartilha o banco.
     */
    #[Test]
    public function anUndeclaredTableIsReportedAndNeverDropped(): void
    {
        $context = $this->context();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $dialect = DialectFactory::for($this->driver(), Config::getSchema());
        $this->pdo()->exec(
            'CREATE TABLE ' . $dialect->quoteIdentifier('zz_de_outro_sistema') . ' (id INT NOT NULL)',
        );

        try {
            $check = (new Check($this->context()))->execute();

            $this->assertContains('zz_de_outro_sistema', $check->unknownTables);
            $this->assertTrue($check->isClean(), 'Tabela desconhecida não é drift por default.');
            $this->assertFalse($check->isCleanStrict(), 'Com --strict, é.');

            $generate = (new Generate($this->context()))->execute();

            $this->assertTrue(
                $generate->nothingToDo(),
                'Nenhum DROP TABLE pode ser gerado para uma tabela que os models não descrevem.',
            );
        } finally {
            $this->pdo()->exec('DROP TABLE IF EXISTS ' . $dialect->quoteIdentifier('zz_de_outro_sistema'));
        }
    }

    /**
     * O `generate` é OFFLINE: os arquivos saem sem uma única consulta ao banco.
     *
     * Não é otimização. É o que permite gerar migração num CI sem serviço de banco e revisar
     * o SQL no pull request antes de ele tocar em ambiente nenhum. O sistema antigo não
     * podia, porque comparava o model contra tabelas dentro do banco de destino.
     */
    #[Test]
    public function generateNeedsNoDatabase(): void
    {
        $context = new MigrationContext(
            DatabaseConfig::fromConfig(),
            DialectFactory::for($this->driver(), Config::getSchema()),
            $this->temp->directory(),
            new ModelSchemaLoader($this->modelsRoot, $this->modelsNamespace),
            migrationsTable: '_neoorm_convergence',
            output: $this->output,
            // Nenhum PDO: qualquer consulta ao banco daqui em diante abriria conexão nova, e
            // o que se afirma é que nenhuma acontece.
            pdo: null,
        );

        $result = (new Generate($context))->execute('offline');

        $this->assertFalse($result->nothingToDo());
        $this->assertFileExists($this->temp->directory()->sqlPath(0, 'offline'));
    }

    /**
     * O SQL gerado tem breakpoint entre statements e volta a ser divisível.
     */
    #[Test]
    public function theGeneratedFileIsReadableByTheRunner(): void
    {
        (new Generate($this->context()))->execute('initial');

        $sql = $this->temp->readSql(0, 'initial');

        $this->assertStringContainsString(MigrationDirectory::slug('initial'), 'initial');
        $this->assertStringContainsString('--> statement-breakpoint', $sql);

        $statements = (new \Diogodg\Neoorm\Migrations\Runner\SqlFileParser())->parse($sql);

        $this->assertGreaterThan(1, count($statements));

        foreach ($statements as $statement) {
            $this->assertStringNotContainsString('--> statement-breakpoint', $statement);
        }
    }
}
