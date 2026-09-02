<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Migrations\Diff\RenameCandidates;
use Diogodg\Neoorm\Migrations\Operation\OperationList;

/**
 * O que o `generate` decidiu, antes ou depois de escrever.
 *
 * Um DTO e não um `echo`. O `Migrate` antigo imprimia de seis lugares diferentes, e por
 * isso não havia como perguntar "o que você faria?" sem que ele fizesse — nem como usá-lo
 * fora de um terminal.
 *
 * `pendingRenames` é o que permite o prompt de rename sem a biblioteca conhecer terminal: o
 * comando de CLI chama `execute(dryRun: true)`, pergunta sobre o que veio aqui, e chama de
 * novo com as respostas.
 */
final readonly class GenerateResult
{
    /**
     * @param list<string> $statements
     * @param list<string> $writtenFiles
     * @param list<string> $warnings
     */
    public function __construct(
        public OperationList $operations,
        public array $statements = [],
        public string $sql = '',
        public array $writtenFiles = [],
        public array $warnings = [],
        public RenameCandidates $pendingRenames = new RenameCandidates(),
        public ?string $tag = null,
        public ?int $index = null,
        public bool $dryRun = false,
    ) {
    }

    /**
     * Nada a fazer: os models já estão descritos pelo último snapshot.
     *
     * É esta a resposta que o gate de convergência exige depois de um `up` — e a que o
     * sistema antigo nunca conseguia dar, porque o diff fantasma de collation (B2) fazia
     * toda execução achar que havia algo a mudar.
     */
    public function nothingToDo(): bool
    {
        return count($this->operations) === 0;
    }

    public function isDestructive(): bool
    {
        return $this->operations->hasDestructive();
    }

    public function wroteFiles(): bool
    {
        return $this->writtenFiles !== [];
    }

    /**
     * @return list<string>
     */
    public function describe(): array
    {
        return $this->operations->describe();
    }
}
