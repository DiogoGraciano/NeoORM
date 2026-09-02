<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * O retrato completo do estado das migrações, sem aplicar nada.
 *
 * É um DTO e não um `echo`: o comando de CLI formata, mas um job, um health check ou uma
 * rota administrativa podem fazer outra coisa com o mesmo dado. O `Migrate` antigo dava
 * `echo` de seis lugares, e era por isso que não havia como perguntar ao sistema em que
 * estado ele estava sem que ele respondesse num terminal.
 */
final readonly class RunnerStatus
{
    /**
     * @param list<MigrationStatusLine> $lines na ordem do journal
     * @param list<string> $unknownTags aplicadas no banco e ausentes do journal
     * @param list<string> $orphanFiles `.sql` no disco e ausentes do journal
     */
    public function __construct(
        public string $dialect,
        public array $lines,
        public array $unknownTags = [],
        public array $orphanFiles = [],
        public bool $controlTableExists = true,
    ) {
    }

    /**
     * @return list<MigrationStatusLine>
     */
    public function pending(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (MigrationStatusLine $l): bool => $l->state === MigrationState::Pending
                || $l->state === MigrationState::Interrupted,
        ));
    }

    /**
     * @return list<MigrationStatusLine>
     */
    public function applied(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (MigrationStatusLine $l): bool => $l->state === MigrationState::Applied,
        ));
    }

    /**
     * @return list<MigrationStatusLine>
     */
    public function problems(): array
    {
        return array_values(array_filter(
            $this->lines,
            static fn (MigrationStatusLine $l): bool => $l->state->isProblem(),
        ));
    }

    /**
     * @return list<string>
     */
    public function pendingTags(): array
    {
        return array_map(static fn (MigrationStatusLine $l): string => $l->tag, $this->pending());
    }

    /**
     * Verde quando não falta aplicar nada E não há nenhuma inconsistência.
     *
     * As duas condições, não só a primeira: um repositório sem pendências mas com um
     * arquivo alterado depois de aplicado não está em dia — está com dois ambientes
     * divergindo em silêncio.
     */
    public function isClean(): bool
    {
        return $this->pending() === []
            && $this->problems() === []
            && $this->unknownTags === []
            && $this->orphanFiles === [];
    }
}
