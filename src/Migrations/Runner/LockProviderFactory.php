<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

use Diogodg\Neoorm\Dialect\Dialect;
use PDO;

/**
 * O lock certo para o dialeto, no MESMO handle PDO do runner.
 *
 * O mesmo handle não é detalhe: os dois bancos oferecem lock de escopo de SESSÃO, e um lock
 * tomado em outra conexão é um lock que ninguém está segurando.
 *
 * Dialeto desconhecido cai em `NullLockProvider` em vez de lançar. É deliberado, e é o
 * único lugar deste sistema onde degradar em silêncio é a resposta certa: um dialeto sem
 * lock ainda aplica migrações corretamente quando um processo roda por vez, que é o caso
 * de 99% dos deploys. Recusar a aplicar seria trocar um risco de concorrência por uma
 * parada certa.
 */
final class LockProviderFactory
{
    private function __construct()
    {
    }

    public static function for(Dialect $dialect, PDO $pdo, string $database, int $timeoutSeconds = 10): LockProvider
    {
        $name = 'neoorm_migrations_' . $database;

        return match ($dialect->name()) {
            'mysql' => new MysqlLockProvider($pdo, $name, $timeoutSeconds),
            'pgsql' => new PgsqlLockProvider($pdo, $name, $timeoutSeconds),
            default => new NullLockProvider(),
        };
    }
}
