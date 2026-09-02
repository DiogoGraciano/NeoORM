<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * Um lock de escopo de SESSÃO, para só um processo aplicar migrações por vez.
 *
 * De sessão, e não de transação, porque no MySQL cada migração é sua própria unidade — não
 * existe uma transação que envolva todas elas para o lock acompanhar. No PostgreSQL isso
 * significa `pg_advisory_lock` e não `pg_advisory_xact_lock`.
 *
 * E sempre no MESMO handle PDO do runner: um lock de sessão tomado em outra conexão é um
 * lock que ninguém está segurando.
 */
interface LockProvider
{
    /**
     * @throws \Diogodg\Neoorm\Migrations\Exception\LockNotAcquiredException
     */
    public function acquire(): void;

    public function release(): void;
}
