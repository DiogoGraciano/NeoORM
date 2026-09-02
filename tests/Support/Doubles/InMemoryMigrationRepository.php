<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Diogodg\Neoorm\Migrations\Runner\MigrationRecord;
use Diogodg\Neoorm\Migrations\Runner\MigrationRepository;
use Diogodg\Neoorm\Migrations\Runner\MigrationStatus;

/**
 * A tabela de controle em memória.
 *
 * É o que torna as guardas do runner — hash divergente, migração fora de ordem, tag
 * desconhecida, retomada de um statement no meio — testáveis sem servidor. E não é só
 * conveniência: reproduzir "uma migração morreu no statement 3 de 5" num banco de verdade
 * exige matar um processo no instante certo. Aqui é um estado, escrito à mão.
 *
 * Registra a ORDEM das chamadas, porque parte do contrato é sequência: `ensureTable` antes
 * de qualquer transação, `start` antes do primeiro statement, `progress` depois de cada um.
 */
final class InMemoryMigrationRepository implements MigrationRepository
{
    /** @var array<string,MigrationRecord> */
    private array $records = [];

    /** @var list<string> */
    public array $calls = [];

    public bool $tableCreated = false;

    /**
     * Injeta um estado que só existiria depois de uma execução interrompida.
     */
    public function seed(MigrationRecord $record): void
    {
        $this->records[$record->tag] = $record;
        $this->tableCreated = true;
    }

    public function ensureTable(): void
    {
        $this->calls[] = 'ensureTable';
        $this->tableCreated = true;
    }

    public function tableExists(): bool
    {
        return $this->tableCreated;
    }

    public function all(): array
    {
        $records = $this->records;
        ksort($records, SORT_STRING);

        return $records;
    }

    public function find(string $tag): ?MigrationRecord
    {
        return $this->records[$tag] ?? null;
    }

    public function start(string $tag, string $hash, int $statements): void
    {
        $this->calls[] = "start:{$tag}";

        // Espelha o UPDATE do repositório real, que reabre a linha sem zerar
        // `applied_index`: é justamente esse índice que faz a retomada retomar.
        $existing = $this->records[$tag] ?? null;

        $this->records[$tag] = new MigrationRecord(
            $tag,
            $hash,
            $statements,
            $existing?->appliedIndex ?? 0,
            MigrationStatus::Running,
        );
    }

    public function progress(string $tag, int $appliedIndex): void
    {
        $this->calls[] = "progress:{$tag}:{$appliedIndex}";

        $record = $this->records[$tag];

        $this->records[$tag] = new MigrationRecord(
            $tag,
            $record->hash,
            $record->statements,
            $appliedIndex,
            $record->status,
        );
    }

    public function finish(string $tag, int $statements): void
    {
        $this->calls[] = "finish:{$tag}";

        $record = $this->records[$tag];

        $this->records[$tag] = new MigrationRecord(
            $tag,
            $record->hash,
            $statements,
            $statements,
            MigrationStatus::Applied,
        );
    }

    public function fail(string $tag, int $appliedIndex, string $error): void
    {
        $this->calls[] = "fail:{$tag}:{$appliedIndex}";

        $record = $this->records[$tag];

        $this->records[$tag] = new MigrationRecord(
            $tag,
            $record->hash,
            $record->statements,
            $appliedIndex,
            MigrationStatus::Failed,
            $error,
        );
    }
}
