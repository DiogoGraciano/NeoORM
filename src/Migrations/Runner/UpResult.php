<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

final readonly class UpResult
{
    /**
     * @param list<AppliedMigration> $applied
     * @param list<string> $skipped tags pendentes que ficaram de fora por --to ou --step
     */
    public function __construct(
        public array $applied = [],
        public array $skipped = [],
        public bool $dryRun = false,
    ) {
    }

    public function isEmpty(): bool
    {
        return $this->applied === [];
    }

    public function statementCount(): int
    {
        return array_sum(array_map(
            static fn (AppliedMigration $m): int => count($m->statements),
            $this->applied,
        ));
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return array_map(static fn (AppliedMigration $m): string => $m->tag, $this->applied);
    }
}
