<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * Uma linha do `migration:status`: o que o journal diz, cruzado com o banco e o disco.
 */
final readonly class MigrationStatusLine
{
    public function __construct(
        public int $idx,
        public string $tag,
        public MigrationState $state,
        public ?MigrationRecord $record = null,
    ) {
    }

    public function describe(): string
    {
        $label = match ($this->state) {
            MigrationState::Applied => 'aplicada',
            MigrationState::Pending => 'pendente',
            // Sem registro não há onde ela parou, e "statement 1 de 0" seria pior que
            // não dizer nada.
            MigrationState::Interrupted => $this->record === null
                ? 'interrompida'
                : sprintf(
                    'interrompida no statement %d de %d',
                    $this->record->appliedIndex + 1,
                    $this->record->statements,
                ),
            MigrationState::Tampered => 'ALTERADA depois de aplicada',
            MigrationState::FileMissing => 'ARQUIVO AUSENTE',
        };

        return sprintf('%04d  %-40s %s', $this->idx, $this->tag, $label);
    }
}
