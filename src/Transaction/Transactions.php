<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Transaction;

/**
 * Controle de transação, como contrato.
 *
 * Duas implementações com políticas deliberadamente diferentes:
 *
 * `PdoTransactions` **recusa** aninhar. É o que o runner de migração usa, e a recusa é
 * proteção: o PDO não aninha, então um `begin()` dentro de outro seria ignorado e o
 * `commit()` seguinte fecharia a transação de fora, dando por aplicada uma migração que
 * ainda estava no meio.
 *
 * `SavepointTransactions` **aninha**, por savepoint. É o que a camada de consulta usa,
 * onde aninhar é normal: um serviço que abre transação chama outro que também abre.
 *
 * Nenhuma das duas é `Connection::beginTransaction()`, que virava no-op quando o estado
 * não casava — e aí uma transação interna não commitava nada e uma falha interna
 * desfazia o trabalho externo, tudo em silêncio.
 */
interface Transactions
{
    public function begin(): void;

    public function commit(): void;

    public function rollBack(): void;

    public function inTransaction(): bool;
}
