<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

use Diogodg\Neoorm\Migrations\Exception\LockNotAcquiredException;
use PDO;

/**
 * `pg_advisory_lock` do PostgreSQL, na variante de SESSÃO.
 *
 * Não `pg_advisory_xact_lock`: aquele solta no fim da transação, e o runner abre uma
 * transação POR MIGRAÇÃO. O lock precisa atravessar todas elas, senão a janela entre duas
 * migrações fica aberta para um segundo runner entrar.
 *
 * O lock advisory do Postgres é indexado por inteiro de 64 bits, não por string, então o
 * nome é reduzido a um inteiro por hash. `try_advisory_lock` não espera, e a espera é
 * construída aqui — assim o timeout é o mesmo dos dois dialetos e não depende de
 * `lock_timeout` do servidor, que também afetaria o DDL das migrações.
 */
final class PgsqlLockProvider implements LockProvider
{
    private readonly int $key;

    private bool $held = false;

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $name,
        private readonly int $timeoutSeconds = 10,
    ) {
        $this->key = self::keyFor($name);
    }

    /**
     * Nome para inteiro de 64 bits com sinal.
     *
     * Os 16 primeiros dígitos hexadecimais do sha1 dariam 64 bits, mas em PHP um valor
     * acima de `PHP_INT_MAX` viraria float e perderia precisão. 15 dígitos cabem com
     * folga em 60 bits, o que é espaço mais que suficiente e mantém o valor inteiro.
     */
    public static function keyFor(string $name): int
    {
        return (int) hexdec(substr(sha1($name), 0, 15));
    }

    public function acquire(): void
    {
        $deadline = time() + $this->timeoutSeconds;

        do {
            $statement = $this->pdo->prepare('SELECT pg_try_advisory_lock(:key)');
            $statement->execute(['key' => $this->key]);

            /** @var mixed $result */
            $result = $statement->fetchColumn();

            if ($result === true || $result === 't' || $result === 1 || $result === '1') {
                $this->held = true;

                return;
            }

            // Espera curta e ativa. É feio, e é o menor mal: `pg_advisory_lock` blocante
            // não tem timeout próprio, e configurar `lock_timeout` na sessão afetaria
            // também o DDL das migrações — um ALTER numa tabela grande passaria a falhar
            // por causa de uma configuração feita para o lock.
            if (time() < $deadline) {
                usleep(200_000);
            }
        } while (time() < $deadline);

        throw new LockNotAcquiredException($this->name, $this->timeoutSeconds);
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        $statement = $this->pdo->prepare('SELECT pg_advisory_unlock(:key)');
        $statement->execute(['key' => $this->key]);

        $this->held = false;
    }
}
