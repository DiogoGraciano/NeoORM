<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Diogodg\Neoorm\Migrations\Runner\Transactions;

/**
 * Registra begin/commit/rollBack em vez de emiti-los.
 *
 * O que se afirma com isto não é que a transação "funciona" — isso só um banco prova, e
 * está no teste de integração. É que o runner PEDE transação onde deve e não pede onde não
 * deve: no PostgreSQL uma por migração, no MySQL nenhuma. Um `begin()` no caminho do MySQL
 * seria uma transação que o primeiro DDL commitaria por conta própria, o que é pior que não
 * ter transação — parece que há uma.
 */
final class RecordingTransactions implements Transactions
{
    /** @var list<string> */
    public array $calls = [];

    private bool $open = false;

    public function __construct(public bool $alreadyOpen = false)
    {
        $this->open = $alreadyOpen;
    }

    public function begin(): void
    {
        $this->calls[] = 'begin';
        $this->open = true;
    }

    public function commit(): void
    {
        $this->calls[] = 'commit';
        $this->open = false;
    }

    public function rollBack(): void
    {
        $this->calls[] = 'rollBack';
        $this->open = false;
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }
}
