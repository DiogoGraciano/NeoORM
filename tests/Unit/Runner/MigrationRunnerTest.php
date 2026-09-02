<?php

declare(strict_types=1);

namespace Tests\Unit\Runner;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Dialect\SqlWriter;
use Diogodg\Neoorm\Migrations\Exception\DriftDetectedException;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Exception\MigrationFailedException;
use Diogodg\Neoorm\Migrations\Exception\MigrationTamperedException;
use Diogodg\Neoorm\Migrations\Exception\OutOfOrderMigrationException;
use Diogodg\Neoorm\Migrations\Runner\MigrationRecord;
use Diogodg\Neoorm\Migrations\Runner\MigrationRunner;
use Diogodg\Neoorm\Migrations\Runner\MigrationState;
use Diogodg\Neoorm\Migrations\Runner\MigrationStatus;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\Doubles\BufferedOutput;
use Tests\Support\Doubles\InMemoryMigrationRepository;
use Tests\Support\Doubles\RecordingExecutor;
use Tests\Support\Doubles\RecordingTransactions;
use Tests\Support\TempMigrations;
use Tests\Support\UnitTestCase;

/**
 * O runner, sem banco nenhum.
 *
 * As guardas do runner são a parte com mais casos e a que ninguém consegue reproduzir à mão
 * num servidor: "morreu no statement 3 de 5", "o arquivo foi editado depois de aplicado",
 * "outro branch aplicou a 0008 antes de a 0007 ser mergeada". Com o repositório em memória
 * e um diretório temporário, cada um desses é um estado escrito à mão.
 *
 * O que este arquivo NÃO prova é que uma transação de verdade desfaz DDL de verdade. Isso é
 * assunto do banco, e está em `Integration/Runner/`.
 */
final class MigrationRunnerTest extends UnitTestCase
{
    private TempMigrations $temp;

    private InMemoryMigrationRepository $repository;

    private RecordingExecutor $executor;

    private RecordingTransactions $transactions;

    private BufferedOutput $output;

    protected function setUp(): void
    {
        parent::setUp();

        $this->temp = new TempMigrations('pgsql');
        $this->repository = new InMemoryMigrationRepository();
        $this->executor = new RecordingExecutor();
        $this->transactions = new RecordingTransactions();
        $this->output = new BufferedOutput();
    }

    protected function tearDown(): void
    {
        $this->temp->cleanup();

        parent::tearDown();
    }

    private function runner(?Dialect $dialect = null): MigrationRunner
    {
        return new MigrationRunner(
            $dialect ?? DialectFactory::pgsql(),
            $this->temp->directory(),
            $this->repository,
            $this->executor,
            $this->transactions,
            output: $this->output,
        );
    }

    /**
     * Um runner com o dialeto do MySQL contra o mesmo diretório.
     *
     * O dialeto é o que decide o caminho: com DDL transacional, transação por migração; sem,
     * progresso durável a cada statement. Trocar só o dialeto é o que deixa a diferença de
     * comportamento visível num teste só.
     */
    private function mysqlRunner(): MigrationRunner
    {
        $this->temp = new TempMigrations('mysql');

        return $this->runner(DialectFactory::mysql());
    }

    private function sql(string ...$statements): string
    {
        return (new SqlWriter())->write($statements);
    }

    // ---- caminho normal ----

    #[Test]
    public function withNothingToDoItAppliesNothing(): void
    {
        $result = $this->runner()->up();

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $result->tags());
    }

    /**
     * A tabela de controle é criada ANTES de qualquer transação.
     *
     * É o bug do rastreador antigo virado do avesso: ele rodava `CREATE TABLE IF NOT EXISTS`
     * dentro do construtor, uma vez por model, e no MySQL cada um desses DDL faz commit
     * implícito — furando a transação de quem tivesse aberto uma.
     */
    #[Test]
    public function theControlTableIsCreatedBeforeAnyTransaction(): void
    {
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)'));

        $this->runner()->up();

        $this->assertSame('ensureTable', $this->repository->calls[0]);
        $this->assertSame('begin', $this->transactions->calls[0]);
    }

    #[Test]
    public function itAppliesEveryStatementOfEveryPendingMigration(): void
    {
        $this->temp
            ->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'))
            ->add(1, 'segunda', $this->sql('CREATE INDEX i ON a (id)'));

        $result = $this->runner()->up();

        $this->assertSame(['initial', 'segunda'], $result->tags());
        $this->assertSame(3, $result->statementCount());
        $this->assertSame(
            ['CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)', 'CREATE INDEX i ON a (id)'],
            $this->executor->executed,
        );
    }

    /**
     * Reaplicar é no-op. É o gate desta fase, e o oposto direto do bug B4: o sistema antigo
     * rodava `ALTER TABLE ADD CONSTRAINT` sem idempotência para TODAS as tabelas, e por isso
     * a segunda execução do migrate estourava com foreign key duplicada.
     */
    #[Test]
    public function reapplyingIsANoOp(): void
    {
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)'));

        $this->runner()->up();
        $this->executor->executed = [];

        $second = $this->runner()->up();

        $this->assertTrue($second->isEmpty());
        $this->assertSame([], $this->executor->executed);
    }

    /**
     * A sessão é ajustada antes de qualquer statement de migração.
     *
     * Migração é o único SQL que a biblioteca executa sem ter montado, e por isso a sessão
     * precisa estar num estado conhecido: sem isso o mesmo arquivo significa coisas
     * diferentes conforme a configuração de quem roda.
     */
    #[Test]
    public function theSessionIsPreparedFirst(): void
    {
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)'));

        $this->runner(DialectFactory::pgsql('public'))->up();

        $this->assertSame(['SET search_path TO "public"'], $this->executor->sessionSetup);
    }

    #[Test]
    public function anEmptyMigrationIsAppliedAsAppliedWithNoStatements(): void
    {
        $this->temp->add(0, 'vazia', '');

        $result = $this->runner()->up();

        $this->assertSame(['vazia'], $result->tags());
        $this->assertSame(0, $result->statementCount());
        $this->assertTrue($this->repository->find('vazia')?->isApplied());
    }

    // ---- atomicidade assimétrica ----

    /**
     * No PostgreSQL, uma transação por migração — e o bookkeeping dentro dela.
     */
    #[Test]
    public function postgresWrapsEachMigrationInATransaction(): void
    {
        $this->temp
            ->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)'))
            ->add(1, 'segunda', $this->sql('CREATE TABLE b (id INT)'));

        $this->runner()->up();

        $this->assertSame(['begin', 'commit', 'begin', 'commit'], $this->transactions->calls);
    }

    /**
     * No MySQL, NENHUMA transação.
     *
     * Abrir uma seria pior que não abrir: o primeiro DDL a commitaria por conta própria, e o
     * código passaria a parecer protegido quando não está. Melhor não ter transação e ter
     * `applied_index`.
     */
    #[Test]
    public function mysqlOpensNoTransactionAtAll(): void
    {
        $runner = $this->mysqlRunner();
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)'));

        $runner->up();

        $this->assertSame([], $this->transactions->calls);
    }

    /**
     * E o progresso é registrado statement por statement, que é o que torna a retomada
     * possível.
     */
    #[Test]
    public function mysqlRecordsProgressAfterEachStatement(): void
    {
        $runner = $this->mysqlRunner();
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)', 'CREATE TABLE b (id INT)'));

        $runner->up();

        $this->assertSame(
            ['ensureTable', 'start:initial', 'progress:initial:1', 'progress:initial:2', 'finish:initial'],
            $this->repository->calls,
        );
    }

    // ---- falha no meio ----

    /**
     * No PostgreSQL a falha desfaz tudo, inclusive a linha de controle.
     */
    #[Test]
    public function aFailureInPostgresRollsBackAndLeavesNoRow(): void
    {
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)', 'ISSO NÃO É SQL'));
        $this->executor = new RecordingExecutor(failOn: [1], reason: 'syntax error');

        try {
            $this->runner()->up();
            $this->fail('Esperava MigrationFailedException.');
        } catch (MigrationFailedException $e) {
            $this->assertSame(1, $e->statementIndex);
            $this->assertSame(2, $e->statementCount);
            $this->assertTrue($e->atomic);
            $this->assertStringContainsString('nada desta migração foi aplicado', $e->getMessage());
            $this->assertStringContainsString('statement 2 de 2', $e->getMessage());
        }

        $this->assertSame(['begin', 'rollBack'], $this->transactions->calls);
    }

    /**
     * No MySQL a falha grava onde parou, e a mensagem diz que a migração é retomável.
     *
     * O contrato assimétrico não é escondido: está na doc, no log e aqui na mensagem da
     * exceção. Prometer atomicidade onde o banco não a oferece seria pior que declarar a
     * limitação.
     */
    #[Test]
    public function aFailureInMysqlRecordsWhereItStopped(): void
    {
        $this->temp = new TempMigrations('mysql');
        $this->temp->add(0, 'initial', $this->sql(
            'CREATE TABLE a (id INT)',
            'CREATE TABLE b (id INT)',
            'ISSO NÃO É SQL',
        ));
        $this->executor = new RecordingExecutor(failOn: [2], reason: 'syntax error');
        $runner = $this->runner(DialectFactory::mysql());

        try {
            $runner->up();
            $this->fail('Esperava MigrationFailedException.');
        } catch (MigrationFailedException $e) {
            $this->assertSame(2, $e->statementIndex);
            $this->assertFalse($e->atomic);
            $this->assertStringContainsString('não tem DDL transacional', $e->getMessage());
            $this->assertStringContainsString('retoma deste ponto', $e->getMessage());
        }

        $record = $this->repository->find('initial');

        $this->assertSame(MigrationStatus::Failed, $record?->status);
        $this->assertSame(2, $record->appliedIndex, 'Dois statements passaram; o terceiro é o que falta.');
        $this->assertTrue($record->isResumable());
    }

    /**
     * E reexecutar RETOMA, em vez de repetir o que já passou.
     *
     * Repetir seria tentar `CREATE TABLE` numa tabela que já existe — ou seja, uma falha
     * diferente, mascarando a original. É o mesmo modo de falha do bug B4.
     */
    #[Test]
    public function mysqlResumesFromWhereItStopped(): void
    {
        $this->temp = new TempMigrations('mysql');
        $this->temp->add(0, 'initial', $this->sql('primeiro', 'segundo', 'terceiro'));

        $this->repository->seed(new MigrationRecord(
            'initial',
            $this->temp->directory()->hashOf(0, 'initial'),
            statements: 3,
            appliedIndex: 2,
            status: MigrationStatus::Failed,
            error: 'syntax error',
        ));

        $result = $this->runner(DialectFactory::mysql())->up();

        $this->assertSame(['terceiro'], $this->executor->executed);
        $this->assertTrue($result->applied[0]->resumed);
        $this->assertTrue($this->repository->find('initial')?->isApplied());
    }

    #[Test]
    public function mysqlRefusesToResumeAnEditedMigration(): void
    {
        $this->temp = new TempMigrations('mysql');
        $this->temp->add(0, 'initial', $this->sql('primeiro', 'segundo', 'terceiro'));

        $originalHash = $this->temp->directory()->hashOf(0, 'initial');
        $this->repository->seed(new MigrationRecord(
            'initial',
            $originalHash,
            statements: 3,
            appliedIndex: 2,
            status: MigrationStatus::Failed,
            error: 'syntax error',
        ));

        $this->temp->tamper(0, 'initial', $this->sql('primeiro', 'segundo editado', 'terceiro'));

        $this->expectException(MigrationTamperedException::class);
        $this->runner(DialectFactory::mysql())->up();
    }

    /**
     * O caso de borda da retomada: o processo morreu ENTRE o último statement e o `finish`.
     *
     * Nada a executar, tudo a fechar. Sem este caminho o runner tentaria rodar o statement
     * de índice 3 de uma lista de 3 — ou pior, `array_slice` devolveria vazio e a migração
     * ficaria pendente para sempre.
     */
    #[Test]
    public function aMigrationThatDiedBeforeFinishIsJustClosed(): void
    {
        $this->temp = new TempMigrations('mysql');
        $this->temp->add(0, 'initial', $this->sql('primeiro', 'segundo'));

        $this->repository->seed(new MigrationRecord(
            'initial',
            $this->temp->directory()->hashOf(0, 'initial'),
            statements: 2,
            appliedIndex: 2,
            status: MigrationStatus::Running,
        ));

        $result = $this->runner(DialectFactory::mysql())->up();

        $this->assertSame([], $this->executor->executed);
        $this->assertTrue($this->repository->find('initial')?->isApplied());
        $this->assertTrue($result->applied[0]->resumed);
    }

    // ---- as três guardas ----

    /**
     * Arquivo editado depois de aplicado: recusa, com os dois hashes.
     */
    #[Test]
    public function anEditedMigrationIsRefused(): void
    {
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)'));
        $this->runner()->up();

        $this->temp->tamper(0, 'initial', $this->sql('CREATE TABLE a (id BIGINT)'));

        $this->expectException(MigrationTamperedException::class);
        $this->expectExceptionMessageMatches('/editada depois de aplicada.*Não há down/s');

        $this->runner()->up();
    }

    /**
     * E CRLF não conta como edição: o hash normaliza fim de linha.
     *
     * Sem isso, um checkout com `core.autocrlf` ligado invalidaria o histórico inteiro de um
     * projeto — uma configuração de Git virando incidente.
     */
    #[Test]
    public function aCrlfCheckoutIsNotTampering(): void
    {
        $sql = $this->sql('CREATE TABLE a (id INT)');
        $this->temp->add(0, 'initial', $sql);
        $this->runner()->up();

        $this->temp->tamper(0, 'initial', str_replace("\n", "\r\n", $sql));

        $this->assertTrue($this->runner()->up()->isEmpty());
    }

    /**
     * Tag no banco que o repositório não conhece: para, e diz qual.
     *
     * É o OPOSTO do bug B3, que ao encontrar estado que não reconhecia gravava um snapshot
     * dizendo que estava tudo sincronizado — e aquela tabela nunca mais era comparada com
     * nada.
     */
    #[Test]
    public function aTagTheRepositoryDoesNotKnowStopsEverything(): void
    {
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)'));

        $this->repository->seed(new MigrationRecord(
            'de_outro_branch',
            hash: str_repeat('0', 64),
            statements: 1,
            appliedIndex: 1,
            status: MigrationStatus::Applied,
        ));

        $this->expectException(DriftDetectedException::class);
        $this->expectExceptionMessageMatches('/de_outro_branch/');

        $this->runner()->up();
    }

    /**
     * Pendente com índice menor que uma aplicada: para.
     *
     * Aplicar assim é perigoso porque costuma FUNCIONAR: a 0000 roda, ninguém vê erro, e o
     * banco resultante não é o que nenhum dos dois snapshots descreve.
     */
    #[Test]
    public function aPendingMigrationBeforeAnAppliedOneStopsEverything(): void
    {
        $this->temp
            ->add(0, 'de_outro_branch', $this->sql('CREATE TABLE a (id INT)'))
            ->add(1, 'ja_aplicada', $this->sql('CREATE TABLE b (id INT)'));

        $this->repository->seed(new MigrationRecord(
            'ja_aplicada',
            $this->temp->directory()->hashOf(1, 'ja_aplicada'),
            statements: 1,
            appliedIndex: 1,
            status: MigrationStatus::Applied,
        ));

        try {
            $this->runner()->up();
            $this->fail('Esperava OutOfOrderMigrationException.');
        } catch (OutOfOrderMigrationException $e) {
            $this->assertSame(['de_outro_branch'], $e->pendingTags);
            $this->assertSame('ja_aplicada', $e->appliedTag);
            $this->assertStringContainsString('merge de branches paralelos', $e->getMessage());
        }

        $this->assertSame([], $this->executor->executed);
    }

    /**
     * Arquivo aplicado que desapareceu do repositório: recusa.
     *
     * Não dá para verificar que o banco recebeu o que o repositório descreve quando o
     * repositório não descreve mais nada.
     */
    #[Test]
    public function anAppliedMigrationWithoutItsFileIsRefused(): void
    {
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)'));
        $this->runner()->up();

        $this->temp->removeSql(0, 'initial');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/não está no repositório.*código-fonte/s');

        $this->runner()->up();
    }

    /**
     * As guardas rodam com o banco INTOCADO.
     *
     * Não é detalhe de ordem: descobrir a inconsistência depois de aplicar três migrações
     * deixa o banco num estado que ninguém pediu, e sem down não há volta.
     */
    #[Test]
    public function theGuardsRunBeforeAnyStatement(): void
    {
        $this->temp
            ->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)'))
            ->add(1, 'segunda', $this->sql('CREATE TABLE b (id INT)'));

        $this->repository->seed(new MigrationRecord(
            'fantasma',
            hash: str_repeat('0', 64),
            statements: 1,
            appliedIndex: 1,
            status: MigrationStatus::Applied,
        ));

        try {
            $this->runner()->up();
        } catch (DriftDetectedException) {
            // esperado
        }

        $this->assertSame([], $this->executor->executed);
        $this->assertSame([], $this->transactions->calls);
    }

    // ---- transação de quem chama ----

    #[Test]
    public function upRefusesToRunInsideAnOpenTransaction(): void
    {
        $this->transactions = new RecordingTransactions(alreadyOpen: true);

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/transação aberta.*primeiro DDL commitaria/s');

        $this->runner()->up();
    }

    // ---- seleção: --to e --step ----

    #[Test]
    public function stepLimitsHowManyAreApplied(): void
    {
        $this->temp
            ->add(0, 'a', $this->sql('um'))
            ->add(1, 'b', $this->sql('dois'))
            ->add(2, 'c', $this->sql('tres'));

        $result = $this->runner()->up(step: 2);

        $this->assertSame(['a', 'b'], $result->tags());
        $this->assertSame(['c'], $result->skipped);
    }

    #[Test]
    public function toStopsAtTheGivenTagInclusive(): void
    {
        $this->temp
            ->add(0, 'a', $this->sql('um'))
            ->add(1, 'b', $this->sql('dois'))
            ->add(2, 'c', $this->sql('tres'));

        $result = $this->runner()->up(to: 'b');

        $this->assertSame(['a', 'b'], $result->tags());
        $this->assertSame(['c'], $result->skipped);
    }

    /**
     * `--to` numa tag JÁ aplicada é pedido legítimo, e a resposta é não fazer nada.
     *
     * "Leve o banco até X" quando ele já está em X ou depois não é erro — é uma condição já
     * satisfeita. Lançar aqui obrigaria quem escreve script de deploy a saber o estado do
     * banco antes de pedir o estado que quer.
     */
    #[Test]
    public function toAnAlreadyAppliedTagDoesNothing(): void
    {
        $this->temp->add(0, 'a', $this->sql('um'))->add(1, 'b', $this->sql('dois'));

        $this->runner()->up(to: 'a');
        $this->executor->executed = [];

        $result = $this->runner()->up(to: 'a');

        $this->assertTrue($result->isEmpty());
        $this->assertSame([], $this->executor->executed);
    }

    #[Test]
    public function toAnUnknownTagIsAnError(): void
    {
        $this->temp->add(0, 'a', $this->sql('um'));

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches("/--to 'nao_existe' não é uma migração deste repositório/");

        $this->runner()->up(to: 'nao_existe');
    }

    #[Test]
    public function stepBelowOneIsAnError(): void
    {
        $this->temp->add(0, 'a', $this->sql('um'));

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/--step precisa ser ao menos 1/');

        $this->runner()->up(step: 0);
    }

    // ---- dry run ----

    /**
     * O dry run diz o que faria e não toca em nada — nem no banco, nem no bookkeeping.
     */
    #[Test]
    public function aDryRunExecutesNothing(): void
    {
        $this->temp->add(0, 'initial', $this->sql('CREATE TABLE a (id INT)', 'CREATE INDEX i ON a (id)'));

        $result = $this->runner()->up(dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertSame(['initial'], $result->tags());
        $this->assertSame(2, $result->statementCount());
        $this->assertSame([], $this->executor->executed);
        $this->assertSame([], $this->transactions->calls);
        $this->assertNull($this->repository->find('initial'));
    }

    /**
     * E num cenário de retomada, ele mostra só o que falta.
     */
    #[Test]
    public function aDryRunOfAResumeShowsOnlyWhatIsLeft(): void
    {
        $this->temp = new TempMigrations('mysql');
        $this->temp->add(0, 'initial', $this->sql('primeiro', 'segundo', 'terceiro'));

        $this->repository->seed(new MigrationRecord(
            'initial',
            $this->temp->directory()->hashOf(0, 'initial'),
            statements: 3,
            appliedIndex: 2,
            status: MigrationStatus::Failed,
        ));

        $result = $this->runner(DialectFactory::mysql())->up(dryRun: true);

        $this->assertSame(['terceiro'], $result->applied[0]->statements);
        $this->assertTrue($result->applied[0]->resumed);
    }

    // ---- status ----

    #[Test]
    public function statusReportsAppliedAndPending(): void
    {
        $this->temp
            ->add(0, 'a', $this->sql('um'))
            ->add(1, 'b', $this->sql('dois'));

        $this->runner()->up(step: 1);

        $status = $this->runner()->status();

        $this->assertSame(['a'], array_map(fn ($l) => $l->tag, $status->applied()));
        $this->assertSame(['b'], $status->pendingTags());
        $this->assertFalse($status->isClean());
    }

    #[Test]
    public function statusIsCleanWhenEverythingIsApplied(): void
    {
        $this->temp->add(0, 'a', $this->sql('um'));
        $this->runner()->up();

        $this->assertTrue($this->runner()->status()->isClean());
    }

    /**
     * `status` NÃO lança por inconsistência: é o comando que se roda justamente quando algo
     * está errado, e morrer na primeira mostraria uma e esconderia as outras.
     */
    #[Test]
    public function statusReportsProblemsInsteadOfThrowing(): void
    {
        $this->temp
            ->add(0, 'aplicada_e_editada', $this->sql('um'))
            ->add(1, 'arquivo_sumiu', $this->sql('dois'));

        $this->runner()->up(step: 1);
        $this->temp->tamper(0, 'aplicada_e_editada', $this->sql('outro'));
        $this->temp->removeSql(1, 'arquivo_sumiu');
        $this->temp->addOrphanSql('0002_de_outro_branch.sql', 'SELECT 1');

        $status = $this->runner()->status();

        $this->assertSame(MigrationState::Tampered, $status->lines[0]->state);
        $this->assertSame(MigrationState::FileMissing, $status->lines[1]->state);
        $this->assertSame(['0002_de_outro_branch.sql'], $status->orphanFiles);
        $this->assertFalse($status->isClean());
        $this->assertCount(2, $status->problems());
    }

    #[Test]
    public function statusReportsAnInterruptedMigration(): void
    {
        $this->temp = new TempMigrations('mysql');
        $this->temp->add(0, 'initial', $this->sql('um', 'dois', 'tres'));

        $this->repository->seed(new MigrationRecord(
            'initial',
            $this->temp->directory()->hashOf(0, 'initial'),
            statements: 3,
            appliedIndex: 2,
            status: MigrationStatus::Failed,
        ));

        $status = $this->runner(DialectFactory::mysql())->status();

        $this->assertSame(MigrationState::Interrupted, $status->lines[0]->state);
        $this->assertStringContainsString('interrompida no statement 3 de 3', $status->lines[0]->describe());
        $this->assertSame(['initial'], $status->pendingTags());
    }

    #[Test]
    public function statusReportsTagsTheRepositoryDoesNotKnow(): void
    {
        $this->repository->seed(new MigrationRecord(
            'fantasma',
            hash: str_repeat('0', 64),
            statements: 1,
            appliedIndex: 1,
            status: MigrationStatus::Applied,
        ));

        $status = $this->runner()->status();

        $this->assertSame(['fantasma'], $status->unknownTags);
        $this->assertFalse($status->isClean());
    }
}
