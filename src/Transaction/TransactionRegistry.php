<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Transaction;

use Diogodg\Neoorm\Dialect\Dialect;
use PDO;
use WeakMap;

/**
 * O controle de transação de cada conexão, um por handle PDO.
 *
 * Existe porque o estado transacional pertence à **conexão**, não ao objeto que a usa.
 * Cada `Database` tinha o seu `SavepointTransactions`, com o seu contador de
 * profundidade — mas todos compartilhavam o PDO do `Connection`. Dois
 * `Database::fromConfig()` em pontos diferentes da aplicação, um `transaction()` dentro
 * do outro, e o de dentro via `depth === 0`, chamava `beginTransaction()` sobre uma
 * transação já aberta e recebia `PDOException`. O aninhamento por savepoint, que é a
 * razão de o `SavepointTransactions` existir, só funcionava quando por acaso havia um
 * `Database` só.
 *
 * `WeakMap` e não array chaveado por `spl_object_id`: o id é reciclado quando o objeto
 * morre, então a conexão seguinte herdaria o contador de profundidade da anterior. O
 * valor guarda somente `TransactionState`, nunca o PDO; desse modo a chave pode ser
 * coletada normalmente quando uma conexão explícita sai de escopo.
 */
final class TransactionRegistry
{
    /** @var WeakMap<PDO,TransactionState>|null */
    private static ?WeakMap $handles = null;

    private function __construct()
    {
    }

    /**
     * O controle desta conexão, criado na primeira chamada.
     *
     * O dialeto só é consultado quando a entrada nasce: ele decide a grafia do savepoint,
     * e uma conexão não troca de dialeto no meio da vida.
     */
    public static function for(PDO $pdo, Dialect $dialect): Transactions
    {
        self::$handles ??= new WeakMap();

        $state = self::$handles[$pdo] ??= new TransactionState();

        return new SavepointTransactions($pdo, $dialect, $state);
    }

    /**
     * Esquece o controle de uma conexão, ou de todas.
     *
     * Para os testes, que reusam o mesmo dublê de PDO entre casos e precisam de
     * profundidade zerada — mesmo papel do `SchemaRegistry::flush()`.
     */
    public static function flush(?PDO $pdo = null): void
    {
        if (self::$handles === null) {
            return;
        }

        if ($pdo === null) {
            self::$handles = null;

            return;
        }

        unset(self::$handles[$pdo]);
    }
}
