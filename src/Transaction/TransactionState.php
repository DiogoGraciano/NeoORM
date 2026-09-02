<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Transaction;

/**
 * Estado compartilhado da transação de um handle PDO.
 *
 * Deliberadamente não guarda o PDO. Assim ele pode ser o valor de um WeakMap cuja
 * chave é o handle sem criar o ciclo forte `valor -> chave` que impediria a coleta da
 * conexão em workers de longa duração.
 */
final class TransactionState
{
    public int $depth = 0;
}
