<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Migrations\Exception\DriftDetectedException;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Exception\MigrationFailedException;
use Diogodg\Neoorm\Migrations\Exception\MigrationTamperedException;
use Diogodg\Neoorm\Migrations\Exception\OutOfOrderMigrationException;
use Diogodg\Neoorm\Migrations\Exception\StatementFailedException;
use Diogodg\Neoorm\Migrations\Snapshot\Journal;
use Diogodg\Neoorm\Migrations\Snapshot\JournalEntry;
use Diogodg\Neoorm\Migrations\Snapshot\MigrationDirectory;
use Diogodg\Neoorm\Support\Output\NullOutput;
use Diogodg\Neoorm\Support\Output\Output;
use Throwable;

/**
 * Aplica as migrações pendentes, e é o único lugar que escreve na tabela de controle.
 *
 * Duas coisas definem o desenho:
 *
 * **As guardas vêm antes de qualquer statement.** Hash divergente, migração fora de
 * ordem, tag no banco sem entrada no journal, arquivo ausente — tudo é verificado com o
 * banco intocado. É o oposto do sistema antigo, que descobria problemas no meio da
 * aplicação e os enterrava em `catch` vazio; o efeito era um migrate que sempre dizia ter
 * funcionado.
 *
 * **A atomicidade é assimétrica, e isso não é escondido.** No PostgreSQL, DDL é
 * transacional: a migração inteira mais a linha de bookkeeping commitam juntas ou nada
 * acontece. No MySQL, cada DDL faz commit implícito e não há transação possível — então em
 * vez de fingir atomicidade, o runner registra `applied_index` a cada statement e a
 * migração passa a ser RETOMÁVEL. O commit implícito, que no sistema antigo era o perigo
 * que furava transações, aqui é o mecanismo que torna o progresso durável.
 *
 * O contrato honesto, escrito na doc, no log e na mensagem da exceção: **no MySQL uma
 * migração não é atômica; ela é retomável, e diz exatamente em qual statement parou.**
 */
final class MigrationRunner
{
    private bool $sessionPrepared = false;

    public function __construct(
        private readonly Dialect $dialect,
        private readonly MigrationDirectory $directory,
        private readonly MigrationRepository $repository,
        private readonly Executor $executor,
        private readonly Transactions $transactions,
        private readonly LockProvider $lock = new NullLockProvider(),
        private readonly Output $output = new NullOutput(),
        private readonly SqlFileParser $parser = new SqlFileParser(),
    ) {
    }

    /**
     * O estado de tudo, sem aplicar nada e sem lançar por inconsistência.
     *
     * Não lançar é o ponto: `status` é o comando que a pessoa roda JUSTAMENTE quando algo
     * está errado. Se ele morresse na primeira inconsistência, mostraria uma e esconderia
     * as outras — e quem está diagnosticando precisa do quadro inteiro.
     */
    public function status(): RunnerStatus
    {
        $journal = $this->directory->journal();
        $records = $this->repository->all();
        $lines = [];

        foreach ($journal->entries as $entry) {
            $lines[] = new MigrationStatusLine(
                $entry->idx,
                $entry->tag,
                $this->stateOf($entry, $records[$entry->tag] ?? null),
                $records[$entry->tag] ?? null,
            );
        }

        $known = $journal->tags();

        $orphans = array_values(array_filter(
            $this->directory->sqlFiles(),
            function (string $file) use ($journal): bool {
                foreach ($journal->entries as $entry) {
                    if ($this->directory->sqlFileName($entry->idx, $entry->tag) === $file) {
                        return false;
                    }
                }

                return true;
            },
        ));

        return new RunnerStatus(
            $this->dialect->name(),
            $lines,
            array_values(array_diff(array_keys($records), $known)),
            $orphans,
            $this->repository->tableExists(),
        );
    }

    private function stateOf(JournalEntry $entry, ?MigrationRecord $record): MigrationState
    {
        if (!$this->directory->hasSql($entry->idx, $entry->tag)) {
            return MigrationState::FileMissing;
        }

        if ($record === null) {
            return MigrationState::Pending;
        }

        if (!$record->isApplied()) {
            return $record->appliedIndex > 0 ? MigrationState::Interrupted : MigrationState::Pending;
        }

        return $this->directory->hashOf($entry->idx, $entry->tag) === $record->hash
            ? MigrationState::Applied
            : MigrationState::Tampered;
    }

    /**
     * Aplica o que está pendente.
     *
     * @param string|null $to última tag a aplicar, inclusive
     * @param int|null $step quantas migrações aplicar no máximo
     */
    public function up(?string $to = null, ?int $step = null, bool $dryRun = false): UpResult
    {
        // Uma transação aberta aqui é sintoma de que o chamador está tentando envolver as
        // migrações numa transação sua. Isso é impossível no MySQL — o primeiro DDL a
        // commitaria — e no PostgreSQL sequestraria o controle transacional do runner. Uma
        // afirmação clara na entrada vale mais que o comportamento surpreendente na saída.
        if ($this->transactions->inTransaction()) {
            throw new MigrationException(
                'up() foi chamado com uma transação aberta. As migrações controlam a própria '
                . 'transação — no MySQL o primeiro DDL commitaria a sua, e no PostgreSQL o commit do '
                . 'runner fecharia a sua no meio.',
            );
        }

        $this->lock->acquire();

        try {
            $this->prepareSession();

            // Antes de qualquer transação, sempre. É o bug do rastreador antigo virado do
            // avesso: ele rodava esse DDL DENTRO da transação, uma vez por model.
            $this->repository->ensureTable();

            $journal = $this->directory->journal();
            $records = $this->repository->all();

            $this->assertConsistent($journal, $records);

            $pending = $this->pendingEntries($journal, $records);
            [$selected, $skipped] = $this->select($pending, $to, $step);

            if ($dryRun) {
                return new UpResult($this->describeWithoutApplying($selected, $records), $skipped, dryRun: true);
            }

            $applied = [];

            foreach ($selected as $entry) {
                $applied[] = $this->applyOne($entry, $records[$entry->tag] ?? null);
            }

            return new UpResult($applied, $skipped);
        } finally {
            $this->lock->release();
        }
    }

    /**
     * @param list<JournalEntry> $entries
     * @param array<string,MigrationRecord> $records
     * @return list<AppliedMigration>
     */
    private function describeWithoutApplying(array $entries, array $records): array
    {
        $planned = [];

        foreach ($entries as $entry) {
            $statements = $this->parser->parse($this->directory->readSql($entry->idx, $entry->tag));

            // A tag pode não estar em `$records`: uma migração pendente não tem registro
            // nenhum. `$records[$tag]?->…` não cobre isso — o `?->` protege contra objeto
            // nulo, não contra chave ausente.
            $record = $records[$entry->tag] ?? null;
            $from = $record === null ? 0 : $record->appliedIndex;

            $planned[] = new AppliedMigration(
                $entry->idx,
                $entry->tag,
                array_slice($statements, $from),
                resumed: $from > 0,
            );
        }

        return $planned;
    }

    /**
     * Ajusta a sessão uma vez por runner.
     *
     * Migração é o único SQL que esta biblioteca executa sem ter montado — o `.sql` vem do
     * repositório e pode ter sido editado. Por isso a sessão precisa estar num estado
     * conhecido: `sql_mode` sem `NO_BACKSLASH_ESCAPES` no MySQL, `search_path` fixo no
     * PostgreSQL. Sem isso o MESMO texto significa coisas diferentes conforme a
     * configuração de quem roda.
     */
    private function prepareSession(): void
    {
        if ($this->sessionPrepared) {
            return;
        }

        foreach ($this->dialect->sessionSetup() as $sql) {
            $this->executor->execute($sql);
        }

        $this->sessionPrepared = true;
    }

    /**
     * As três guardas, todas com o banco intocado.
     *
     * @param array<string,MigrationRecord> $records
     */
    private function assertConsistent(Journal $journal, array $records): void
    {
        $unknown = array_values(array_diff(array_keys($records), $journal->tags()));

        if ($unknown !== []) {
            throw new DriftDetectedException($unknown);
        }

        $lastAppliedIndex = -1;
        $lastAppliedTag = '';

        foreach ($journal->entries as $entry) {
            $record = $records[$entry->tag] ?? null;

            if ($record === null) {
                continue;
            }

            if (!$this->directory->hasSql($entry->idx, $entry->tag)) {
                if ($record->isApplied()) {
                    throw new MigrationException(
                        "A migração '{$entry->tag}' está registrada como aplicada no banco, mas o "
                        . 'arquivo .sql não está no repositório. Ele é código-fonte e precisa estar '
                        . 'commitado: sem ele não há como verificar que o banco recebeu o que o '
                        . 'repositório descreve.',
                    );
                }

                continue;
            }

            // O hash também é contrato durante a retomada. Se os dois primeiros
            // statements vieram do arquivo A e o processo retoma o terceiro no arquivo
            // B, o banco termina num estado que nenhum dos dois arquivos descreve.
            // `start()` atualiza a linha de controle, então conferir só migrações já
            // aplicadas apagava justamente a evidência dessa mistura.
            $hash = $this->directory->hashOf($entry->idx, $entry->tag);

            if ($hash !== $record->hash) {
                throw new MigrationTamperedException($entry->tag, $record->hash, $hash);
            }

            if ($record->isApplied()) {

                $lastAppliedIndex = $entry->idx;
                $lastAppliedTag = $entry->tag;
            }
        }

        $outOfOrder = [];

        foreach ($journal->entries as $entry) {
            if ($entry->idx >= $lastAppliedIndex) {
                continue;
            }

            if (!($records[$entry->tag] ?? null)?->isApplied()) {
                $outOfOrder[] = $entry->tag;
            }
        }

        if ($outOfOrder !== []) {
            throw new OutOfOrderMigrationException($outOfOrder, $lastAppliedTag, $lastAppliedIndex);
        }
    }

    /**
     * @param array<string,MigrationRecord> $records
     * @return list<JournalEntry>
     */
    private function pendingEntries(Journal $journal, array $records): array
    {
        $pending = [];

        foreach ($journal->entries as $entry) {
            if (($records[$entry->tag] ?? null)?->isApplied() === true) {
                continue;
            }

            $pending[] = $entry;
        }

        return $pending;
    }

    /**
     * Aplica o corte de `--to` e `--step`.
     *
     * O corte é sempre um PREFIXO da lista de pendentes, nunca uma seleção: pular uma
     * migração e aplicar a seguinte produz um banco que nenhum snapshot descreve, e a
     * partir daí todo diff é contra um schema imaginário.
     *
     * @param list<JournalEntry> $pending
     * @return array{list<JournalEntry>,list<string>}
     */
    private function select(array $pending, ?string $to, ?int $step): array
    {
        $selected = $pending;

        if ($to !== null) {
            $position = null;

            foreach ($pending as $index => $entry) {
                if ($entry->tag === $to) {
                    $position = $index;
                    break;
                }
            }

            if ($position === null) {
                // A tag existe no journal e não está pendente: já foi aplicada. "Vá até
                // X" quando já se está em X ou depois é pedido legítimo, e a resposta é
                // não fazer nada — não um erro.
                if ($this->directory->journal()->hasTag($to)) {
                    return [[], array_map(static fn (JournalEntry $e): string => $e->tag, $pending)];
                }

                throw new MigrationException(
                    "--to '{$to}' não é uma migração deste repositório. Pendentes: "
                    . ($pending === []
                        ? '(nenhuma)'
                        : "'" . implode("', '", array_map(
                            static fn (JournalEntry $e): string => $e->tag,
                            $pending,
                        )) . "'"),
                );
            }

            $selected = array_slice($selected, 0, $position + 1);
        }

        if ($step !== null) {
            if ($step < 1) {
                throw new MigrationException("--step precisa ser ao menos 1, e veio {$step}.");
            }

            $selected = array_slice($selected, 0, $step);
        }

        $skipped = array_map(
            static fn (JournalEntry $e): string => $e->tag,
            array_slice($pending, count($selected)),
        );

        return [array_values($selected), array_values($skipped)];
    }

    private function applyOne(JournalEntry $entry, ?MigrationRecord $existing): AppliedMigration
    {
        $sql = $this->directory->readSql($entry->idx, $entry->tag);
        $statements = $this->parser->parse($sql);
        $hash = SqlFileParser::hash($sql);

        // Retomada: o `applied_index` da linha anterior é onde parar de repetir. Lido
        // ANTES de `start()`, que reabre a linha mas não zera o índice.
        $from = $existing !== null && !$existing->isApplied() ? $existing->appliedIndex : 0;

        // O índice de retomada foi contado no arquivo que rodou. Se o arquivo mudou desde
        // então, ele deixa de significar o que diz: continuar do statement 3 do arquivo
        // novo pula o que nunca rodou e repete o que já rodou. `assertConsistent()` não
        // cobre este caso — lá o hash só é conferido para migração APLICADA, e esta parou
        // no meio —, e `start()` sobrescreve o hash guardado, então ninguém mais notaria.
        if ($existing !== null && $existing->isResumable() && $existing->hash !== $hash) {
            throw new MigrationTamperedException(
                $entry->tag,
                $existing->hash,
                $hash,
                resumed: true,
                appliedIndex: $existing->appliedIndex,
            );
        }

        if ($from >= count($statements) && $statements !== []) {
            // A linha diz que todos os statements passaram e a migração não foi marcada
            // como aplicada: o processo morreu entre o último statement e o `finish()`.
            // Não há nada a executar, só a fechar.
            $this->repository->start($entry->tag, $hash, count($statements));
            $this->repository->finish($entry->tag, count($statements));

            return new AppliedMigration($entry->idx, $entry->tag, [], resumed: true);
        }

        $this->output->write(
            sprintf('%s %04d_%s (%d statements)', $from > 0 ? 'retomando' : 'aplicando', $entry->idx, $entry->tag, count($statements))
            . ($from > 0 ? " a partir do statement {$from}" : ''),
        );

        $executed = $this->dialect->supportsTransactionalDdl()
            ? $this->applyAtomically($entry, $statements, $hash)
            : $this->applyResumable($entry, $statements, $hash, $from);

        return new AppliedMigration($entry->idx, $entry->tag, $executed, resumed: $from > 0);
    }

    /**
     * PostgreSQL: a migração e o bookkeeping numa transação só.
     *
     * Genuinamente atômico. A linha de controle nasce e é fechada dentro da mesma
     * transação do DDL, então não existe estado em que o banco tenha metade do schema, nem
     * em que a tabela de controle discorde do schema.
     *
     * @param list<string> $statements
     * @return list<string>
     */
    private function applyAtomically(JournalEntry $entry, array $statements, string $hash): array
    {
        $this->transactions->begin();

        // -1 e não 0: se a falha vier do `start()`, nenhum statement foi tentado, e
        // apontar o statement 1 como culpado mandaria quem lê a mensagem depurar o SQL
        // errado.
        $index = -1;

        try {
            $this->repository->start($entry->tag, $hash, count($statements));

            foreach ($statements as $index => $sql) {
                $this->executor->execute($sql);
            }

            $this->repository->finish($entry->tag, count($statements));
            $this->transactions->commit();

            return $statements;
        } catch (Throwable $e) {
            $this->transactions->rollBack();

            throw new MigrationFailedException(
                $entry->tag,
                max($index, 0),
                count($statements),
                $index < 0 ? '(registro na tabela de controle)' : $statements[$index],
                $e instanceof StatementFailedException ? $e->reason : $e->getMessage(),
                atomic: true,
                previous: $e,
            );
        }
    }

    /**
     * MySQL: sem transação, com progresso durável a cada statement.
     *
     * A linha é inserida em autocommit, de propósito VISÍVEL, antes do primeiro statement,
     * e `applied_index` avança a cada um. Se o processo morrer, o que ficou no banco está
     * registrado — e reexecutar retoma exatamente dali em vez de tentar recriar tabelas que
     * já existem.
     *
     * @param list<string> $statements
     * @return list<string>
     */
    private function applyResumable(JournalEntry $entry, array $statements, string $hash, int $from): array
    {
        $this->repository->start($entry->tag, $hash, count($statements));

        $executed = [];

        foreach ($statements as $index => $sql) {
            if ($index < $from) {
                continue;
            }

            try {
                $this->executor->execute($sql);
            } catch (Throwable $e) {
                $reason = $e instanceof StatementFailedException ? $e->reason : $e->getMessage();

                $this->repository->fail($entry->tag, $index, $reason);

                throw new MigrationFailedException(
                    $entry->tag,
                    $index,
                    count($statements),
                    $sql,
                    $reason,
                    atomic: false,
                    previous: $e,
                );
            }

            $executed[] = $sql;
            $this->repository->progress($entry->tag, $index + 1);
        }

        $this->repository->finish($entry->tag, count($statements));

        return $executed;
    }
}
