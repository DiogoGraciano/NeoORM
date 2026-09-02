<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

use Diogodg\Neoorm\Migrations\Exception\LockNotAcquiredException;
use PDO;

/**
 * `GET_LOCK` / `RELEASE_LOCK` do MySQL.
 *
 * O nome do lock é truncado em 64 caracteres porque é o limite que o MySQL 5.7 passou a
 * impor — antes disso ele aceitava qualquer tamanho e truncava em silêncio, o que é pior:
 * dois bancos com prefixo igual e sufixo diferente compartilhariam o mesmo lock sem que
 * ninguém percebesse. Truncar com hash mantém a distinção.
 */
final class MysqlLockProvider implements LockProvider
{
    private const MAX_NAME_LENGTH = 64;

    private readonly string $name;

    private bool $held = false;

    public function __construct(
        private readonly PDO $pdo,
        string $name,
        private readonly int $timeoutSeconds = 10,
    ) {
        $this->name = self::truncate($name);
    }

    public static function truncate(string $name): string
    {
        if (strlen($name) <= self::MAX_NAME_LENGTH) {
            return $name;
        }

        return substr($name, 0, self::MAX_NAME_LENGTH - 9) . '_' . substr(sha1($name), 0, 8);
    }

    public function acquire(): void
    {
        $statement = $this->pdo->prepare('SELECT GET_LOCK(:name, :timeout)');
        $statement->execute(['name' => $this->name, 'timeout' => $this->timeoutSeconds]);

        /** @var mixed $result */
        $result = $statement->fetchColumn();

        // GET_LOCK devolve 1 para obtido, 0 para timeout e NULL para erro. Só o 1 serve.
        if ((string) $result !== '1') {
            throw new LockNotAcquiredException($this->name, $this->timeoutSeconds);
        }

        $this->held = true;
    }

    public function release(): void
    {
        if (!$this->held) {
            return;
        }

        $statement = $this->pdo->prepare('SELECT RELEASE_LOCK(:name)');
        $statement->execute(['name' => $this->name]);

        $this->held = false;
    }
}
