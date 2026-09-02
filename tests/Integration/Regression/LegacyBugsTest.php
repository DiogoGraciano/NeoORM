<?php

declare(strict_types=1);

namespace Tests\Integration\Regression;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Migrations\Command\Generate;
use Diogodg\Neoorm\Migrations\Command\MigrationContext;
use Diogodg\Neoorm\Migrations\Command\Up;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Migrations\SchemaApplier;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\App\Models\Appointment;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Doubles\BufferedOutput;
use Tests\Support\SchemaFixture;
use Tests\Support\TempMigrations;

/**
 * Um teste por bug confirmado do sistema antigo, nomeado pelo bug.
 *
 * É redundante de propósito: cada um destes comportamentos já está coberto por algum teste
 * de unidade ou pelo round-trip. O valor deste arquivo é outro — `composer test:regression`
 * imprime uma linha verde por bug conhecido, nos dois dialetos, e essa lista é o que se olha
 * antes de acreditar que a reescrita valeu a pena.
 *
 * Cada caso cita o `arquivo:linha` da arquitetura antiga onde o bug morava. Aqueles arquivos
 * não existem mais; a referência é para quem for ler o histórico do git.
 */
#[Group('regression')]
final class LegacyBugsTest extends DatabaseTestCase
{
    private TempMigrations $temp;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = new TempMigrations($this->driver());
        $this->output = new BufferedOutput();

        SchemaRegistry::flush();
        SchemaFixture::ensure();
    }

    protected function tearDown(): void
    {
        $this->temp->cleanup();
        $this->dropRegressionTables();

        parent::tearDown();
    }

    private function dropRegressionTables(): void
    {
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        foreach (['zz_item', 'zz_box', '_neoorm_regression'] as $table) {
            try {
                $this->pdo()->exec('DROP TABLE IF EXISTS ' . $dialect->quoteIdentifier($table));
            } catch (\Throwable) {
                // Limpeza não pode mascarar a falha do teste.
            }
        }
    }

    private function convergenceContext(): MigrationContext
    {
        return new MigrationContext(
            DatabaseConfig::fromConfig(),
            DialectFactory::for($this->driver(), Config::getSchema()),
            $this->temp->directory(),
            new ModelSchemaLoader(
                dirname(__DIR__, 2) . '/App/ConvergenceModelsV2',
                'Tests\\App\\ConvergenceModelsV2',
            ),
            migrationsTable: '_neoorm_regression',
            output: $this->output,
            pdo: $this->pdo(),
        );
    }

    private function domainSchema(): \Diogodg\Neoorm\Schema\SchemaDefinition
    {
        return (new ModelSchemaLoader(Config::getPathModel(), Config::getModelNamespace()))
            ->loadValidated($this->driver());
    }

    private function introspectDomain(): Snapshot
    {
        return (new \Diogodg\Neoorm\Migrations\Introspection\Introspector(
            $this->pdo(),
            DatabaseConfig::fromConfig(),
        ))->introspect($this->domainSchema()->tableNames());
    }

    /**
     * B1/B8 — `CREATE INDEX` nunca era emitido, e índice de coluna única era proibido.
     *
     * Dois bugs somados. `SchemaExtractor.php:219-225` devolvia uma LISTA onde
     * `SchemaTracker.php:340` e `SchemaComparator.php:274` esperavam um MAPA, o que gravava
     * `index_name` como `"0"` e `"1"` e fazia o comparador nunca reconhecer um índice
     * existente. E `TableMysql.php:237` recusava explicitamente `count($columns) < 2`, de modo
     * que um índice de uma coluna era impossível de declarar.
     */
    #[Test]
    public function b1AndB8SingleColumnIndexesAreCreated(): void
    {
        $context = $this->convergenceContext();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $table = $this->convergenceContext()->introspector()->introspect(['zz_item'])->table('zz_item');

        $this->assertNotNull($table);
        $this->assertArrayHasKey('zz_item_label_index', $table->indexes);
        $this->assertSame(
            ['label'],
            $table->indexes['zz_item_label_index']->columns,
            'Um índice de UMA coluna tem que chegar ao banco.',
        );
    }

    /**
     * B2 — diff fantasma de collation, que nunca convergia.
     *
     * `SchemaComparator.php:65-67` comparava `$current['collation']` e gerava SQL a partir de
     * `$current['collation_name']`, uma chave que não existia. Somado a isso, o builder tinha
     * `utf8mb4_general_ci` como default, que discorda permanentemente do default de um MySQL 8
     * — `utf8mb4_0900_ai_ci`. O resultado era um `ALTER` de collation a cada execução, para
     * sempre.
     *
     * A correção é de modelagem, não de comparação: `null` em `engine`/`collation` significa
     * "não escolhido", e o differ não compara o que não foi escolhido.
     */
    #[Test]
    public function b2CollationDoesNotDriftForever(): void
    {
        $declared = $this->domainSchema();
        $live = $this->introspectDomain();

        $first = (new SchemaDiffer())->diff($live, $live->withSchema($declared));

        $this->assertCount(
            0,
            $first,
            "O banco acabou de ser montado a partir destes models e o differ achou trabalho:\n  "
            . implode("\n  ", $first->describe()),
        );

        // E de novo, porque o sintoma de B2 era justamente reaparecer a cada execução.
        $second = (new SchemaDiffer())->diff($this->introspectDomain(), $live->withSchema($declared));

        $this->assertCount(0, $second);
    }

    /**
     * B3 — tabela pré-existente considerada sincronizada PARA SEMPRE.
     *
     * `SchemaComparator.php:30-33` devolvia `[]` ao encontrar uma tabela que não conhecia, e o
     * chamador gravava o snapshot em seguida — registrando que estava tudo certo. A partir
     * dali aquela tabela nunca mais era comparada com nada.
     *
     * O comportamento novo é o oposto em ambas as direções: a tabela desconhecida é
     * REPORTADA, com nome, e nunca recebe `DROP` — porque ela pode ser de outro sistema que
     * compartilha o banco.
     */
    #[Test]
    public function b3AnUnknownTableIsReportedAndNeverSilentlyAccepted(): void
    {
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());
        $this->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS ' . $dialect->quoteIdentifier('zz_box') . ' (id INT NOT NULL)',
        );

        $introspector = new \Diogodg\Neoorm\Migrations\Introspection\Introspector(
            $this->pdo(),
            DatabaseConfig::fromConfig(),
        );

        $unknown = $introspector->unknownTables($this->domainSchema()->tableNames(), '_neoorm_migrations');

        $this->assertContains('zz_box', $unknown, 'A tabela desconhecida tem que ser reportada.');

        // E nenhum DROP é gerado para ela: o differ só compara o que os models descrevem.
        $declared = $this->domainSchema();
        $operations = (new SchemaDiffer())->diff(
            $introspector->introspect($declared->tableNames()),
            Snapshot::introspected($this->driver(), $declared),
        );

        $this->assertStringNotContainsString('zz_box', implode("\n", $operations->describe()));
    }

    /**
     * B4 — a segunda execução estourava com foreign key duplicada.
     *
     * `TableMysql.php:402` rodava `ALTER TABLE ADD CONSTRAINT` sem idempotência, para TODAS as
     * tabelas, em toda execução. Um `migrate` seguido de outro `migrate` era garantia de erro.
     */
    #[Test]
    public function b4ApplyingTwiceIsANoOp(): void
    {
        $context = $this->convergenceContext();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $second = (new Up($this->convergenceContext()))->execute();

        $this->assertTrue($second->isEmpty(), 'A segunda aplicação não pode ter nada a fazer.');
    }

    /**
     * B5 — só uma das quatro foreign keys de `appointment` era rastreada.
     *
     * `TableMysql.php:214` indexava `foreningTables` pela coluna REFERENCIADA, cujo default é
     * `"id"`. Como as quatro FKs de `appointment` referenciam `id`, as quatro colidiam na
     * mesma chave e três sumiam.
     */
    #[Test]
    public function b5AllFourForeignKeysOfAppointmentSurvive(): void
    {
        $table = $this->introspectDomain()->table(Appointment::table);

        $this->assertNotNull($table);
        $this->assertCount(
            4,
            $table->foreignKeys,
            'As quatro FKs de appointment referenciam `id`; indexá-las pela coluna referenciada '
            . "fazia três desaparecerem. Vieram: \n  " . implode("\n  ", array_keys($table->foreignKeys)),
        );

        $columns = array_map(
            static fn (\Diogodg\Neoorm\Schema\ForeignKeyDefinition $fk): string => $fk->columns[0],
            array_values($table->foreignKeys),
        );
        sort($columns);

        $this->assertSame(['client_id', 'employee_id', 'schedule_id', 'user_id'], $columns);
    }

    /**
     * B6 — `ON DELETE CASCADE` declarado no model nunca era detectado.
     *
     * `SchemaExtractor.php:306` hardcodava `RESTRICT` ao ler a definição de volta, então
     * qualquer ação referencial declarada era descartada silenciosamente e o banco ficava com
     * a ação errada.
     */
    #[Test]
    public function b6OnDeleteCascadeReachesTheDatabase(): void
    {
        $context = $this->convergenceContext();

        (new Generate($context))->execute('initial');
        (new Up($context))->execute();

        $table = $this->convergenceContext()->introspector()->introspect(['zz_item'])->table('zz_item');

        $this->assertNotNull($table);

        $foreignKey = array_values($table->foreignKeys)[0] ?? null;

        $this->assertNotNull($foreignKey);
        $this->assertSame(
            ReferentialAction::Cascade,
            $foreignKey->onDelete,
            'O model declara ON DELETE CASCADE, e é isso que o banco tem que ter.',
        );
    }

    /**
     * B7 — comentário e TAMANHO de coluna descartados no PostgreSQL.
     *
     * `TablePgsql.php:153` não referenciava `$column->comment` nem `$column->size`, então todo
     * `VARCHAR(120)` virava `varchar` sem limite. É o bug mais grave da lista: afetava TODA
     * coluna de TODOS os models, e um `varchar` sem limite aceita qualquer coisa — o banco
     * deixava de ser a última linha de defesa contra dado malformado.
     */
    #[Test]
    public function b7ColumnLengthAndCommentSurviveOnBothDialects(): void
    {
        $table = $this->introspectDomain()->table(\Tests\App\Models\City::table);

        $this->assertNotNull($table);
        $this->assertSame(
            120,
            $table->columns['name']->type->length,
            'O VARCHAR(120) declarado no model tem que chegar ao banco COM o limite.',
        );
        $this->assertSame('City name', $table->columns['name']->comment);
        $this->assertSame('Cities table', $table->comment);
    }

    /**
     * Transação furada por construção no MySQL.
     *
     * `SchemaTracker.php:25` rodava cinco `CREATE TABLE IF NOT EXISTS` no CONSTRUTOR, uma vez
     * por model. No MySQL cada DDL faz commit implícito, então qualquer transação aberta era
     * confirmada sem que ninguém pedisse — e o `testTransactionRollback` do ORM só passava no
     * PostgreSQL, onde DDL é transacional e escondia o problema.
     *
     * Hoje a tabela de controle é criada uma vez, FORA de qualquer transação, e o repositório
     * recusa explicitamente ser chamado de dentro de uma.
     */
    #[Test]
    public function ddlInsideATransactionIsRefusedInsteadOfSilentlyCommitting(): void
    {
        $pdo = $this->pdo();
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        $repository = new \Diogodg\Neoorm\Migrations\Runner\PdoMigrationRepository(
            $pdo,
            $dialect,
            '_neoorm_regression',
        );

        $pdo->beginTransaction();

        try {
            $this->expectException(\Diogodg\Neoorm\Migrations\Exception\MigrationException::class);
            $this->expectExceptionMessageMatches('/dentro de uma transação/');

            $repository->ensureTable();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * `Model::table()` abria conexão PDO.
     *
     * `TableMysql.php:156` conectava, e `Db.php:33` chamava `Model::table()` em TODA
     * instanciação de model — então criar um objeto em memória abria socket. Era também a razão
     * de nada de definição de schema ser testável isoladamente.
     *
     * A guarda permanente disto é a suíte unitária inteira: `UnitTestCase` derruba qualquer
     * teste que abra conexão, e mil e tantos testes montam schema sem servidor. Este caso é a
     * afirmação direta, para a lista de regressões ficar completa.
     */
    #[Test]
    public function definingASchemaOpensNoConnection(): void
    {
        Connection::close();

        SchemaRegistry::flush();
        $definition = SchemaRegistry::for(Appointment::class);

        $this->assertSame(Appointment::table, $definition->name);
        $this->assertFalse(
            Connection::isOpen(),
            'Definir schema é operação de memória: nenhuma conexão pode ser aberta.',
        );
    }

    /**
     * Seeds rodavam ANTES de as foreign keys existirem, em ordem alfabética de arquivo.
     *
     * `Migrate.php:73` semeava dentro do loop de criação e `:81` só depois criava as FKs. Que
     * `Country` fosse semeado antes de `State` funcionava por acidente do alfabeto.
     *
     * Hoje a ordem sai do grafo de dependência, e o schema já convergiu antes de o primeiro
     * insert acontecer.
     */
    #[Test]
    public function seedsRunInDependencyOrderAfterTheSchemaConverges(): void
    {
        $schema = $this->domainSchema();
        $order = \Diogodg\Neoorm\Migrations\Diff\DependencyGraph::fromSchema($schema)->sort();

        $country = array_search('country', $order, true);
        $state = array_search('state', $order, true);
        $city = array_search('city', $order, true);

        $this->assertIsInt($country);
        $this->assertIsInt($state);
        $this->assertIsInt($city);
        $this->assertLessThan($state, $country, 'country tem que vir antes de state.');
        $this->assertLessThan($city, $state, 'state tem que vir antes de city.');
    }

    /**
     * Falha de statement era engolida por `catch` vazio.
     *
     * A arquitetura antiga enterrava quase toda exceção em `try { } catch { /* ignora *\/ }`, e
     * o efeito era um `migrate` que sempre dizia ter funcionado — inclusive quando não tinha
     * aplicado metade do que prometeu.
     */
    #[Test]
    public function aRejectedStatementPropagatesWithTheSql(): void
    {
        $applier = new SchemaApplier(
            DialectFactory::for($this->driver(), Config::getSchema()),
            new \Diogodg\Neoorm\Migrations\Runner\PdoExecutor($this->pdo()),
            $this->output,
        );

        $operations = \Diogodg\Neoorm\Migrations\Operation\OperationList::of(
            new \Diogodg\Neoorm\Migrations\Operation\RawSql('ISSO NAO E SQL VALIDO'),
        );

        try {
            $applier->apply($operations);
            $this->fail('Esperava StatementFailedException: a falha não pode ser engolida.');
        } catch (\Diogodg\Neoorm\Migrations\Exception\StatementFailedException $e) {
            $this->assertSame('ISSO NAO E SQL VALIDO', $e->sql);
            $this->assertNotSame('', $e->reason);
        }
    }
}
