<?php

declare(strict_types=1);

namespace Tests\Integration\Runner;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Dialect\SqlWriter;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Exception\MigrationFailedException;
use Diogodg\Neoorm\Migrations\Runner\MigrationRunner;
use Diogodg\Neoorm\Migrations\Runner\MigrationStatus;
use Diogodg\Neoorm\Migrations\Runner\PdoExecutor;
use Diogodg\Neoorm\Migrations\Runner\PdoMigrationRepository;
use Diogodg\Neoorm\Migrations\Runner\PdoTransactions;
use PDO;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\DatabaseTestCase;
use Tests\Support\TempMigrations;

/**
 * O runner contra um servidor de verdade.
 *
 * O que só um banco prova, e é por isso que estes casos existem além dos unitários:
 *
 * 1. Que a transação do PostgreSQL desfaz DDL DE VERDADE — que a tabela criada pelo
 *    primeiro statement não está lá depois da falha do segundo.
 * 2. Que no MySQL ela NÃO desfaz, porque o DDL commitou implicitamente. Este é o teste
 *    que documenta a assimetria com o comportamento observado em vez de com uma
 *    afirmação da doc.
 * 3. Que a tabela de controle sobrevive à volta pelo banco: os tipos, o unique em `tag`,
 *    a leitura dos horários.
 */
#[Group('runner')]
final class MigrationRunnerTest extends DatabaseTestCase
{
    private TempMigrations $temp;

    private string $table = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = new TempMigrations($this->driver());

        // Uma tabela de controle POR TESTE, com nome próprio. Compartilhar a de produção
        // faria um caso enxergar as migrações de outro, e sob ordem aleatória a falha
        // apareceria longe da causa.
        $this->table = '_neoorm_runner_test_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        $this->dropEverything();
        $this->temp->cleanup();

        parent::tearDown();
    }

    private function dropEverything(): void
    {
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        foreach ([$this->table, 'zz_runner_a', 'zz_runner_b', 'zz_runner_c'] as $name) {
            try {
                $this->pdo()->exec('DROP TABLE IF EXISTS ' . $dialect->quoteIdentifier($name));
            } catch (\Throwable) {
                // Limpeza de tearDown não pode mascarar a falha do teste.
            }
        }
    }

    private function runner(): MigrationRunner
    {
        $pdo = $this->pdo();
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        return new MigrationRunner(
            $dialect,
            $this->temp->directory(),
            new PdoMigrationRepository($pdo, $dialect, $this->table),
            new PdoExecutor($pdo),
            new PdoTransactions($pdo),
        );
    }

    private function sql(string ...$statements): string
    {
        return (new SqlWriter())->write($statements);
    }

    private function tableExists(string $name): bool
    {
        try {
            $dialect = DialectFactory::for($this->driver(), Config::getSchema());
            $this->pdo()->query('SELECT 1 FROM ' . $dialect->quoteIdentifier($name) . ' WHERE 1 = 0');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    #[Test]
    public function itAppliesMigrationsAndRecordsThem(): void
    {
        $this->temp
            ->add(0, 'cria_a', $this->sql('CREATE TABLE zz_runner_a (id INT NOT NULL)'))
            ->add(1, 'cria_b', $this->sql('CREATE TABLE zz_runner_b (id INT NOT NULL)'));

        $result = $this->runner()->up();

        $this->assertSame(['cria_a', 'cria_b'], $result->tags());
        $this->assertTrue($this->tableExists('zz_runner_a'));
        $this->assertTrue($this->tableExists('zz_runner_b'));

        $records = $this->repository()->all();

        $this->assertSame(['cria_a', 'cria_b'], array_keys($records));
        $this->assertTrue($records['cria_a']->isApplied());
        $this->assertSame(1, $records['cria_a']->statements);
        $this->assertSame(1, $records['cria_a']->appliedIndex);
        $this->assertNotNull($records['cria_a']->startedAt);
        $this->assertNotNull($records['cria_a']->finishedAt);
        $this->assertNull($records['cria_a']->error);
    }

    private function repository(): PdoMigrationRepository
    {
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        return new PdoMigrationRepository($this->pdo(), $dialect, $this->table);
    }

    /**
     * Reaplicar é no-op contra o banco de verdade.
     *
     * É o gate desta fase e a regressão direta do bug B4: o sistema antigo rodava
     * `ALTER TABLE ADD CONSTRAINT` sem idempotência para todas as tabelas, e a segunda
     * execução do migrate estourava com foreign key duplicada.
     */
    #[Test]
    public function reapplyingIsANoOp(): void
    {
        $this->temp->add(0, 'cria_a', $this->sql('CREATE TABLE zz_runner_a (id INT NOT NULL)'));

        $this->runner()->up();
        $second = $this->runner()->up();

        $this->assertTrue($second->isEmpty());
        $this->assertTrue($this->tableExists('zz_runner_a'));
    }

    /**
     * O hash e a tabela de controle atravessam o banco intactos.
     */
    #[Test]
    public function theControlTableSurvivesTheRoundTrip(): void
    {
        $sqlText = $this->sql('CREATE TABLE zz_runner_a (id INT NOT NULL)');
        $this->temp->add(0, 'cria_a', $sqlText);

        $this->runner()->up();

        $record = $this->repository()->find('cria_a');

        $this->assertNotNull($record);
        $this->assertSame($this->temp->directory()->hashOf(0, 'cria_a'), $record->hash);
        $this->assertSame(64, strlen($record->hash));
        $this->assertSame(MigrationStatus::Applied, $record->status);
    }

    /**
     * `ensureTable()` dentro de uma transação é recusado.
     *
     * No MySQL esse DDL faria commit implícito da transação de quem chamou, e o rollback
     * dela passaria a não desfazer nada. Era exatamente o que o rastreador antigo fazia, e a
     * consequência era um `testTransactionRollback` que só passava no PostgreSQL.
     */
    #[Test]
    public function ensureTableInsideATransactionIsRefused(): void
    {
        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $this->expectException(MigrationException::class);
            $this->expectExceptionMessageMatches('/commit.*implícito|dentro de uma transação/s');

            $this->repository()->ensureTable();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    #[Test]
    public function upRefusesToRunInsideAnOpenTransaction(): void
    {
        $this->temp->add(0, 'cria_a', $this->sql('CREATE TABLE zz_runner_a (id INT NOT NULL)'));

        $pdo = $this->pdo();
        $pdo->beginTransaction();

        try {
            $this->expectException(MigrationException::class);

            $this->runner()->up();
        } finally {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
        }
    }

    /**
     * A ASSIMETRIA, observada e não afirmada.
     *
     * Uma migração de dois statements onde o segundo falha. No PostgreSQL a tabela criada
     * pelo primeiro NÃO existe depois — DDL é transacional e o rollback a desfez, junto com
     * a linha de bookkeeping. No MySQL ela EXISTE, porque o `CREATE TABLE` já commitou por
     * conta própria, e o que resta é o `applied_index` dizendo até onde foi.
     *
     * As duas metades num teste só, porque é a comparação que é o conteúdo: prometer
     * atomicidade onde o banco não a oferece seria pior que declarar a limitação.
     */
    #[Test]
    public function aFailureIsAtomicOnPostgresAndResumableOnMysql(): void
    {
        $this->temp->add(0, 'meia_falha', $this->sql(
            'CREATE TABLE zz_runner_a (id INT NOT NULL)',
            'ISSO NAO E SQL VALIDO',
        ));

        try {
            $this->runner()->up();
            $this->fail('Esperava MigrationFailedException.');
        } catch (MigrationFailedException $e) {
            $this->assertSame(1, $e->statementIndex);
            $this->assertSame(2, $e->statementCount);
        }

        if ($this->isPgsql()) {
            $this->assertFalse(
                $this->tableExists('zz_runner_a'),
                'DDL é transacional no PostgreSQL: o rollback tem que ter desfeito o CREATE TABLE.',
            );
            $this->assertNull(
                $this->repository()->find('meia_falha'),
                'A linha de bookkeeping estava na mesma transação, então ela também voltou.',
            );

            return;
        }

        $this->assertTrue(
            $this->tableExists('zz_runner_a'),
            'No MySQL o CREATE TABLE já commitou por conta própria; não há rollback que o desfaça.',
        );

        $record = $this->repository()->find('meia_falha');

        $this->assertNotNull($record, 'A linha é gravada em autocommit, de propósito, para o progresso ser durável.');
        $this->assertSame(MigrationStatus::Failed, $record->status);
        $this->assertSame(1, $record->appliedIndex);
        $this->assertTrue($record->isResumable());
        $this->assertNotNull($record->error);
    }

    /**
     * E no MySQL a retomada retoma: o statement que já passou não roda de novo.
     *
     * Se rodasse, o erro passaria a ser "tabela já existe" — uma falha diferente,
     * mascarando a original, que é o modo de falha do bug B4.
     */
    #[Test]
    public function mysqlResumesInsteadOfRedoing(): void
    {
        if ($this->isPgsql()) {
            self::markTestSkipped('Retomada só existe onde DDL não é transacional.');
        }

        $this->temp->add(0, 'meia_falha', $this->sql(
            'CREATE TABLE zz_runner_a (id INT NOT NULL)',
            'ISSO NAO E SQL VALIDO',
        ));

        try {
            $this->runner()->up();
        } catch (MigrationFailedException) {
            // esperado
        }

        // Conserta o arquivo... não: o hash mudaria e a guarda de adulteração recusaria, e
        // com razão. O jeito honesto de destravar é o mesmo que um usuário tem: o statement
        // 2 continua inválido, então a retomada falha DE NOVO no mesmo ponto — e é isso que
        // se afirma, porque prova que ela não repetiu o statement 1.
        try {
            $this->runner()->up();
            $this->fail('Esperava MigrationFailedException de novo.');
        } catch (MigrationFailedException $e) {
            $this->assertSame(
                1,
                $e->statementIndex,
                'Falhou no MESMO statement: o CREATE TABLE não foi repetido, senão o erro seria '
                . '"tabela já existe" e viria do statement 0.',
            );
            $this->assertStringNotContainsString('exists', strtolower($e->reason));
        }
    }

    /**
     * Dois runners concorrentes não aplicam em dobro.
     *
     * O lock é de sessão, então o segundo runner precisa de uma CONEXÃO própria — no mesmo
     * handle, ele seria o dono do lock e passaria direto.
     */
    #[Test]
    public function twoConcurrentRunnersDoNotBothApply(): void
    {
        $this->temp->add(0, 'cria_a', $this->sql('CREATE TABLE zz_runner_a (id INT NOT NULL)'));

        $first = $this->pdo();
        $second = $this->freshConnection();

        $dialect = DialectFactory::for($this->driver(), Config::getSchema());
        $name = 'neoorm_test_lock_' . bin2hex(random_bytes(4));

        $holder = $this->lockFor($first, $name);
        $holder->acquire();

        try {
            $runner = new MigrationRunner(
                $dialect,
                $this->temp->directory(),
                new PdoMigrationRepository($second, $dialect, $this->table),
                new PdoExecutor($second),
                new PdoTransactions($second),
                $this->lockFor($second, $name, timeoutSeconds: 1),
            );

            $this->expectException(\Diogodg\Neoorm\Migrations\Exception\LockNotAcquiredException::class);

            $runner->up();
        } finally {
            $holder->release();
        }
    }

    private function freshConnection(): PDO
    {
        $config = \Diogodg\Neoorm\DatabaseConfig::fromConfig();

        return new PDO($config->dsn(), $config->user, $config->password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);
    }

    private function lockFor(
        PDO $pdo,
        string $name,
        int $timeoutSeconds = 10,
    ): \Diogodg\Neoorm\Migrations\Runner\LockProvider {
        return $this->isPgsql()
            ? new \Diogodg\Neoorm\Migrations\Runner\PgsqlLockProvider($pdo, $name, $timeoutSeconds)
            : new \Diogodg\Neoorm\Migrations\Runner\MysqlLockProvider($pdo, $name, $timeoutSeconds);
    }

    /**
     * E quando o lock é liberado, o segundo runner aplica normalmente.
     *
     * Sem este par, o teste acima passaria com um lock que nunca solta.
     */
    #[Test]
    public function afterTheLockIsReleasedTheOtherRunnerProceeds(): void
    {
        $this->temp->add(0, 'cria_a', $this->sql('CREATE TABLE zz_runner_a (id INT NOT NULL)'));

        $name = 'neoorm_test_lock_' . bin2hex(random_bytes(4));
        $holder = $this->lockFor($this->pdo(), $name);

        $holder->acquire();
        $holder->release();

        $second = $this->freshConnection();
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        $runner = new MigrationRunner(
            $dialect,
            $this->temp->directory(),
            new PdoMigrationRepository($second, $dialect, $this->table),
            new PdoExecutor($second),
            new PdoTransactions($second),
            $this->lockFor($second, $name, timeoutSeconds: 1),
        );

        $this->assertSame(['cria_a'], $runner->up()->tags());
        $this->assertTrue($this->tableExists('zz_runner_a'));
    }

    /**
     * O setup de sessão é aceito pelo servidor.
     *
     * `sessionSetup()` monta SQL à mão, fora do compilador de operações — é o único lugar do
     * runner onde isso acontece. Um teste que só verificasse a string provaria que ela é
     * igual a ela mesma.
     */
    #[Test]
    public function theSessionSetupIsAcceptedByTheServer(): void
    {
        $dialect = DialectFactory::for($this->driver(), Config::getSchema());

        foreach ($dialect->sessionSetup() as $sql) {
            $this->pdo()->exec($sql);
        }

        $this->assertNotSame([], $dialect->sessionSetup(), 'Os dois dialetos têm algo a ajustar na sessão.');
    }

    /**
     * E o efeito dele é o que se pretende: no MySQL, contrabarra volta a escapar.
     *
     * Não basta o servidor aceitar o comando — o que importa é o `sql_mode` resultante, que é
     * o que dá significado a todo literal dos arquivos `.sql`.
     */
    #[Test]
    public function mysqlSessionSetupActuallyDisablesNoBackslashEscapes(): void
    {
        if ($this->isPgsql()) {
            self::markTestSkipped('NO_BACKSLASH_ESCAPES é do MySQL.');
        }

        $pdo = $this->pdo();
        $pdo->exec("SET SESSION sql_mode = CONCAT(@@SESSION.sql_mode, ',NO_BACKSLASH_ESCAPES')");

        try {
            $this->assertStringContainsString(
                'NO_BACKSLASH_ESCAPES',
                (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn(),
            );

            foreach (DialectFactory::mysql()->sessionSetup() as $sql) {
                $pdo->exec($sql);
            }

            $mode = (string) $pdo->query('SELECT @@SESSION.sql_mode')->fetchColumn();

            $this->assertStringNotContainsString('NO_BACKSLASH_ESCAPES', $mode);
            $this->assertStringContainsString(
                'STRICT_TRANS_TABLES',
                $mode,
                'O resto do sql_mode tem que sobreviver: desligar STRICT_TRANS_TABLES trocaria um erro '
                . 'de migração por truncamento silencioso de dados.',
            );
        } finally {
            $pdo->exec('SET SESSION sql_mode = DEFAULT');
        }
    }
}
