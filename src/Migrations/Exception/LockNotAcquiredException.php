<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Exception;

/**
 * Outro processo está aplicando migrações neste banco.
 *
 * Esperar não é opção segura por tempo indefinido, e aplicar em paralelo é pior: dois
 * runners lendo a mesma lista de pendentes aplicariam as mesmas migrações duas vezes, e
 * `CREATE TABLE` duas vezes é erro — quando não é pior, como um `ALTER` que soma uma
 * coluna duas vezes.
 *
 * O lock é de SESSÃO, não de transação, porque no MySQL cada migração é sua própria
 * unidade e o lock precisa atravessar todas elas.
 */
final class LockNotAcquiredException extends MigrationException
{
    public function __construct(public readonly string $lockName, public readonly int $timeoutSeconds)
    {
        parent::__construct(
            "Não foi possível obter o lock de migração '{$lockName}' em {$timeoutSeconds}s. "
            . 'Outro processo está aplicando migrações neste banco. Se você tem certeza de que não, '
            . 'a sessão que segurava o lock pode ter morrido sem soltá-lo — reconectar ou reiniciar '
            . 'o servidor libera.',
        );
    }
}
