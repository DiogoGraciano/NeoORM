<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

final readonly class AppliedMigration
{
    /**
     * @param list<string> $statements os statements executados nesta rodada
     * @param bool $resumed se retomou de uma execução interrompida
     */
    public function __construct(
        public int $idx,
        public string $tag,
        public array $statements,
        public bool $resumed = false,
    ) {
    }
}
