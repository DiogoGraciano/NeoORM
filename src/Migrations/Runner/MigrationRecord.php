<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * Uma linha de `_neoorm_migrations`.
 *
 * Guarda o hash do arquivo, e não só a tag, porque tag diz QUE migração rodou e hash diz
 * QUAL CONTEÚDO rodou. Sem o hash, editar um `.sql` já aplicado é uma divergência
 * indetectável entre dois ambientes que se acreditam iguais.
 */
final readonly class MigrationRecord
{
    public function __construct(
        public string $tag,
        public string $hash,
        public int $statements,
        public int $appliedIndex,
        public MigrationStatus $status,
        public ?string $error = null,
        public ?string $startedAt = null,
        public ?string $finishedAt = null,
    ) {
    }

    public function isApplied(): bool
    {
        return $this->status === MigrationStatus::Applied;
    }

    /**
     * Uma migração interrompida no meio, que a próxima execução retoma.
     *
     * Só acontece em banco sem DDL transacional. No PostgreSQL a transação leva a linha
     * junto com o DDL, então nunca sobra estado parcial.
     */
    public function isResumable(): bool
    {
        return !$this->isApplied() && $this->appliedIndex > 0;
    }
}
