<?php

declare(strict_types=1);

namespace Diogodg\Neoorm;

use InvalidArgumentException;
use PDO;

/**
 * Configuração de banco como valor imutável.
 *
 * Existe para que nada abaixo da fronteira do CLI precise ler os estáticos de
 * Config nem o singleton de Connection. Isso é o que permite um teste segurar
 * duas conexões de dialetos diferentes ao mesmo tempo, e o que impede que um
 * componente troque o driver global por baixo de outro — a classe de bug que
 * ordem de execução aleatória torna impossível de reproduzir.
 */
final readonly class DatabaseConfig
{
    public function __construct(
        public string $driver,
        public string $host,
        public string $port,
        public string $database,
        public string $user,
        public string $password,
        public string $charset = 'utf8mb4',
        public string $schema = '',
    ) {
        if (!in_array($driver, ['mysql', 'pgsql'], true)) {
            throw new InvalidArgumentException(
                "Driver de banco não suportado: '{$driver}'. Configure DRIVER como 'mysql' ou 'pgsql'.",
            );
        }

        if ($database === '') {
            throw new InvalidArgumentException('DBNAME não pode ser vazio.');
        }
    }

    /**
     * Fotografa a configuração global. Chamado uma vez, na fronteira do CLI.
     */
    public static function fromConfig(): self
    {
        return new self(
            driver: Config::getDriver(),
            host: Config::getHost(),
            port: Config::getPort(),
            database: Config::getDbName(),
            user: Config::getUser(),
            password: Config::getPassword(),
            charset: Config::getCharset() ?: 'utf8mb4',
            schema: Config::getSchema(),
        );
    }

    public function isMysql(): bool
    {
        return $this->driver === 'mysql';
    }

    public function isPgsql(): bool
    {
        return $this->driver === 'pgsql';
    }

    /**
     * Schema efetivo do PostgreSQL. No MySQL o conceito não existe: o "schema"
     * é o próprio banco.
     */
    public function effectiveSchema(): string
    {
        if ($this->isMysql()) {
            return $this->database;
        }

        return $this->schema !== '' ? $this->schema : 'public';
    }

    public function dsn(): string
    {
        if ($this->isMysql()) {
            return sprintf(
                'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                $this->host,
                $this->port,
                $this->database,
                $this->charset,
            );
        }

        return sprintf('pgsql:host=%s;port=%s;dbname=%s', $this->host, $this->port, $this->database);
    }

    /**
     * DSN sem o banco, para operações administrativas (criar/derrubar o banco),
     * que não podem depender de o banco existir.
     */
    public function serverDsn(): string
    {
        if ($this->isMysql()) {
            return sprintf('mysql:host=%s;port=%s;charset=%s', $this->host, $this->port, $this->charset);
        }

        // O PostgreSQL exige um banco na conexão; `postgres` é o de manutenção.
        return sprintf('pgsql:host=%s;port=%s;dbname=postgres', $this->host, $this->port);
    }

    public function withDatabase(string $database): self
    {
        return new self(
            driver: $this->driver,
            host: $this->host,
            port: $this->port,
            database: $database,
            user: $this->user,
            password: $this->password,
            charset: $this->charset,
            schema: $this->schema,
        );
    }

    /**
     * As opções de toda conexão da biblioteca.
     *
     * O driver entra porque uma delas é específica do MySQL. Ver {@see foundRows()}.
     *
     * @return array<int,mixed>
     */
    public static function pdoOptions(?string $driver = null): array
    {
        return [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ] + self::foundRows($driver);
    }

    /**
     * `MYSQL_ATTR_FOUND_ROWS`: faz o `rowCount()` de um UPDATE contar as linhas
     * **casadas**, e não as alteradas.
     *
     * É paridade de dialeto, não preferência. O PostgreSQL sempre contou as casadas, e o
     * MySQL, sem esta opção, conta as que mudaram de valor: um UPDATE que grava o que já
     * estava lá devolve `1` num banco e `0` no outro. Como `0` é justamente o que a
     * aplicação lê como "não achei a linha", a mesma escrita levava a decisões opostas
     * conforme o banco.
     *
     * A constante só existe com `pdo_mysql` compilado, e passá-la a uma conexão
     * PostgreSQL seria opção de driver desconhecida — daí a dupla guarda.
     *
     * @return array<int,mixed>
     */
    private static function foundRows(?string $driver): array
    {
        if ($driver !== 'mysql' || !defined('PDO::MYSQL_ATTR_FOUND_ROWS')) {
            return [];
        }

        return [PDO::MYSQL_ATTR_FOUND_ROWS => true];
    }

    public function connect(): PDO
    {
        return new PDO($this->dsn(), $this->user, $this->password, self::pdoOptions($this->driver));
    }

    public function connectToServer(): PDO
    {
        return new PDO($this->serverDsn(), $this->user, $this->password, self::pdoOptions($this->driver));
    }
}
