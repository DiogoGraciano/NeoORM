<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use PDO;
use Throwable;

/**
 * Cria e derruba o banco de dados inteiro.
 *
 * Separado do resto porque é a única operação que não pode acontecer estando conectado
 * ao próprio banco — nenhum dos dois servidores derruba um banco com sessão aberta
 * nele. Por isso tudo aqui usa a conexão de SERVIDOR (`serverDsn()`), não a do banco.
 *
 * O nome do banco não pode ser parametrizado por PDO: entra na DDL como identificador.
 * A validação por regex é o que separa configuração de injeção.
 */
final class DatabaseAdmin
{
    public function __construct(private readonly DatabaseConfig $config)
    {
    }

    public function recreate(): void
    {
        $pdo = $this->serverConnection();
        $name = $this->quotedName();

        try {
            $pdo->exec('DROP DATABASE IF EXISTS ' . $name);
            $pdo->exec('CREATE DATABASE ' . $name);
        } catch (Throwable $e) {
            throw new MigrationException(
                "Falha ao recriar o banco '{$this->config->database}': " . $e->getMessage()
                . '. Sessões abertas no banco impedem o DROP.',
                previous: $e,
            );
        }
    }

    public function createIfMissing(): void
    {
        if ($this->exists()) {
            return;
        }

        try {
            $this->serverConnection()->exec('CREATE DATABASE ' . $this->quotedName());
        } catch (Throwable $e) {
            throw new MigrationException(
                "Falha ao criar o banco '{$this->config->database}': " . $e->getMessage(),
                previous: $e,
            );
        }
    }

    public function exists(): bool
    {
        $pdo = $this->serverConnection();

        $sql = $this->config->driver === 'mysql'
            ? 'SELECT 1 FROM information_schema.schemata WHERE schema_name = ?'
            : 'SELECT 1 FROM pg_database WHERE datname = ?';

        $statement = $pdo->prepare($sql);
        $statement->execute([$this->config->database]);

        return $statement->fetchColumn() !== false;
    }

    private function serverConnection(): PDO
    {
        return $this->config->connectToServer();
    }

    private function quotedName(): string
    {
        $name = $this->config->database;

        if (preg_match('/^[a-zA-Z0-9_]+$/', $name) !== 1) {
            throw new MigrationException(
                "Nome de banco inválido: '{$name}'. Use apenas letras, dígitos e underscore — "
                . 'o nome entra na DDL como identificador e não pode ser parametrizado.',
            );
        }

        return $this->config->driver === 'mysql' ? "`{$name}`" : "\"{$name}\"";
    }
}
