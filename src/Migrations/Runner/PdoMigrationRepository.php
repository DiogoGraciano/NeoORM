<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use PDO;

/**
 * A tabela de controle num banco de verdade.
 *
 * Tudo aqui é statement preparado, exceto o nome da tabela, que não pode ser
 * parametrizado: ele é quotado pelo dialeto e já vem validado por regex em
 * `Config::getMigrationsTable()`. Vale reparar que o DDL da tabela é a única string de SQL
 * do runner que não passa pelo compilador de operações — ela precisa existir antes de
 * qualquer operação poder ser registrada.
 */
final class PdoMigrationRepository implements MigrationRepository
{
    private readonly string $quoted;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Dialect $dialect,
        private readonly string $table,
    ) {
        $this->quoted = $dialect->quoteIdentifier($table);
    }

    public function ensureTable(): void
    {
        if ($this->pdo->inTransaction()) {
            // Não é preciosismo. No MySQL este DDL faria commit implícito da transação de
            // quem chamou, e o rollback dele passaria a não desfazer nada — foi
            // literalmente o bug do rastreador antigo, que rodava DDL no construtor.
            throw new MigrationException(
                'ensureTable() foi chamado dentro de uma transação. A tabela de controle tem que '
                . 'existir ANTES de qualquer transação começar: no MySQL este DDL faz commit '
                . 'implícito e engoliria o rollback de quem chamou.',
            );
        }

        foreach ($this->dialect->migrationsTableDdl($this->table) as $sql) {
            $this->pdo->exec($sql);
        }
    }

    public function tableExists(): bool
    {
        // Consultar a própria tabela e capturar "não existe" aborta a transação inteira
        // no PostgreSQL. O catálogo responde à mesma pergunta sem produzir erro.
        if ($this->dialect->name() === 'pgsql') {
            $statement = $this->pdo->prepare('SELECT to_regclass(:table_name)');
            $statement->execute(['table_name' => $this->table]);

            return $statement->fetchColumn() !== null;
        }

        $statement = $this->pdo->prepare(
            'SELECT 1 FROM information_schema.tables '
            . 'WHERE table_schema = DATABASE() AND table_name = :table_name LIMIT 1',
        );
        $statement->execute(['table_name' => $this->table]);

        return $statement->fetchColumn() !== false;
    }

    public function all(): array
    {
        if (!$this->tableExists()) {
            return [];
        }

        $statement = $this->pdo->query(
            "SELECT tag, hash, statements, applied_index, status, error, started_at, finished_at
               FROM {$this->quoted} ORDER BY tag",
        );

        if ($statement === false) {
            return [];
        }

        $records = [];

        /** @var array<string,mixed> $row */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $record = $this->hydrate($row);
            $records[$record->tag] = $record;
        }

        return $records;
    }

    public function find(string $tag): ?MigrationRecord
    {
        if (!$this->tableExists()) {
            return null;
        }

        $statement = $this->pdo->prepare(
            "SELECT tag, hash, statements, applied_index, status, error, started_at, finished_at
               FROM {$this->quoted} WHERE tag = :tag",
        );
        $statement->execute(['tag' => $tag]);

        /** @var array<string,mixed>|false $row */
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * Insere, ou atualiza quando a tag já está lá.
     *
     * A tag já estar lá é o caso da RETOMADA no MySQL: a linha ficou com
     * `status = 'failed'` e um `applied_index` no meio, e agora a mesma migração volta a
     * rodar. Sem o update, a chave única em `tag` derrubaria a retentativa — o único
     * caminho de recuperação que existe num banco sem DDL transacional.
     *
     * Não uso `ON CONFLICT` / `ON DUPLICATE KEY`: a sintaxe divide os dois bancos e o
     * ganho seria nenhum, porque este caminho roda uma vez por migração e já está dentro
     * do lock.
     */
    public function start(string $tag, string $hash, int $statements): void
    {
        if ($this->find($tag) !== null) {
            $update = $this->pdo->prepare(
                "UPDATE {$this->quoted}
                    SET hash = :hash, statements = :statements, status = :status,
                        error = NULL, started_at = {$this->now()}, finished_at = NULL
                  WHERE tag = :tag",
            );
            $update->execute([
                'hash' => $hash,
                'statements' => $statements,
                'status' => MigrationStatus::Running->value,
                'tag' => $tag,
            ]);

            return;
        }

        $insert = $this->pdo->prepare(
            "INSERT INTO {$this->quoted} (tag, hash, statements, applied_index, status, started_at)
                  VALUES (:tag, :hash, :statements, 0, :status, {$this->now()})",
        );
        $insert->execute([
            'tag' => $tag,
            'hash' => $hash,
            'statements' => $statements,
            'status' => MigrationStatus::Running->value,
        ]);
    }

    public function progress(string $tag, int $appliedIndex): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->quoted} SET applied_index = :applied_index WHERE tag = :tag",
        );
        $statement->execute(['applied_index' => $appliedIndex, 'tag' => $tag]);
    }

    public function finish(string $tag, int $statements): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->quoted}
                SET status = :status, applied_index = :applied_index, error = NULL,
                    finished_at = {$this->now()}
              WHERE tag = :tag",
        );
        $statement->execute([
            'status' => MigrationStatus::Applied->value,
            'applied_index' => $statements,
            'tag' => $tag,
        ]);
    }

    public function fail(string $tag, int $appliedIndex, string $error): void
    {
        $statement = $this->pdo->prepare(
            "UPDATE {$this->quoted}
                SET status = :status, applied_index = :applied_index, error = :error,
                    finished_at = {$this->now()}
              WHERE tag = :tag",
        );
        $statement->execute([
            'status' => MigrationStatus::Failed->value,
            'applied_index' => $appliedIndex,
            'error' => mb_substr($error, 0, 4000),
            'tag' => $tag,
        ]);
    }

    /**
     * O relógio do BANCO, não o do PHP.
     *
     * Os dois horários registrados servem para ler o histórico depois, e um deploy que
     * roda num container com fuso diferente do servidor deixaria a coluna incomparável
     * com o resto do banco.
     */
    private function now(): string
    {
        return 'CURRENT_TIMESTAMP';
    }

    /**
     * @param array<string,mixed> $row
     */
    private function hydrate(array $row): MigrationRecord
    {
        $status = MigrationStatus::tryFrom((string) ($row['status'] ?? ''));

        if ($status === null) {
            throw new MigrationException(
                "A tabela de controle tem um status desconhecido ('" . (string) ($row['status'] ?? '')
                . "') na tag '" . (string) $row['tag'] . "'. Alguém editou a tabela à mão, ou ela foi "
                . 'escrita por uma versão mais nova da biblioteca.',
            );
        }

        return new MigrationRecord(
            (string) $row['tag'],
            (string) $row['hash'],
            (int) $row['statements'],
            (int) $row['applied_index'],
            $status,
            $row['error'] !== null ? (string) $row['error'] : null,
            $row['started_at'] !== null ? (string) $row['started_at'] : null,
            $row['finished_at'] !== null ? (string) $row['finished_at'] : null,
        );
    }
}
