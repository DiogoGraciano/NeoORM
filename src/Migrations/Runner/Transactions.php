<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * Controle de transação, como contrato.
 *
 * O runner NÃO usa `Connection::beginTransaction()` e companhia, e a razão é concreta:
 * aqueles métodos viram no-op quando o estado não casa e não têm controle de
 * profundidade, então um chamador que já tivesse uma transação aberta veria o rollback do
 * runner ser engolido em silêncio. Aqui as três operações são explícitas, e
 * `inTransaction()` existe para o runner poder AFIRMAR, na entrada, que não há nada aberto.
 */
interface Transactions
{
    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;
}
