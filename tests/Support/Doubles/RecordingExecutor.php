<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Diogodg\Neoorm\Migrations\Exception\StatementFailedException;
use Diogodg\Neoorm\Migrations\Runner\Executor;

/**
 * Anota os statements em vez de executá-los, e falha nos que se pedir.
 *
 * `failOn` recebe o índice — contando só os statements de migração, não o setup de sessão —
 * porque o cenário que interessa é "morreu no meio": é ele que separa um banco com DDL
 * transacional de um sem, e é o único caminho pelo qual a retomada existe.
 */
final class RecordingExecutor implements Executor
{
    /** @var list<string> */
    public array $executed = [];

    /** @var list<string> */
    public array $sessionSetup = [];

    /**
     * @param list<int> $failOn índices (base zero) que devem falhar
     */
    public function __construct(
        private array $failOn = [],
        private readonly string $reason = 'erro simulado',
    ) {
    }

    public function execute(string $sql): void
    {
        // O setup de sessão não conta como statement de migração: contá-lo deslocaria
        // todos os índices e faria o teste afirmar sobre o statement errado.
        if (str_starts_with($sql, 'SET ')) {
            $this->sessionSetup[] = $sql;

            return;
        }

        $index = count($this->executed);

        if (in_array($index, $this->failOn, true)) {
            throw new StatementFailedException($sql, $this->reason);
        }

        $this->executed[] = $sql;
    }
}
