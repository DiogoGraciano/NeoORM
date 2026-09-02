<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Migrations\Operation\OperationList;

final readonly class PushResult
{
    /**
     * @param list<string> $statements
     * @param list<string> $warnings
     */
    public function __construct(
        public OperationList $operations,
        public array $statements = [],
        public array $warnings = [],
        public bool $dryRun = false,
    ) {
    }

    public function nothingToDo(): bool
    {
        return count($this->operations) === 0;
    }
}
