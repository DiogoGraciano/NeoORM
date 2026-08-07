<?php

namespace Diogodg\Neoorm;

use Exception;
use PDO;
use PDOException;

/**
 * Classe para configuração e obtenção da conexão com o banco de dados.
 */
class Connection
{
    /**
     * Instância do objeto PDO para a conexão com o banco de dados.
     *
     * @var PDO|null
     */
    private static $pdo = null;

    /**
     * connection constructor.
     * Privado para impedir a criação direta de instâncias (Singleton).
     */
    private function __construct() {}

    /**
     * Impede a clonagem da instância.
     */
    private function __clone() {}

    /**
     * Impede a desserialização da instância.
     *
     * @throws \Exception
     */
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    /**
     * Obtém a conexão com o banco de dados usando o PDO.
     *
     * @return PDO Retorna uma instância do objeto PDO.
     *
     * @throws Exception Lança uma exceção se ocorrer um erro ao conectar com o banco de dados.
     */
    public static function getConnection(): PDO
    {
        if (self::$pdo === null) {
            try {
                if (Config::getDriver() == "mysql") {
                    $dsn = sprintf(
                        Config::getDriver() . ':host=%s;port=%s;dbname=%s;charset=%s',
                        Config::getHost(),
                        Config::getPort(),
                        Config::getDbName(),
                        Config::getCharset()
                    );
                } else {
                    $dsn = sprintf(
                        Config::getDriver() . ':host=%s;port=%s;dbname=%s',
                        Config::getHost(),
                        Config::getPort(),
                        Config::getDbName()
                    );
                }
                self::$pdo = new PDO($dsn, Config::getUser(), Config::getPassword(), [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    // Sem emulação o driver envia os parâmetros separados da query,
                    // e os tipos declarados no bind são de fato respeitados.
                    PDO::ATTR_EMULATE_PREPARES   => false,
                    PDO::ATTR_STRINGIFY_FETCHES  => false,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                ]);
            } catch (PDOException $e) {
                // A mensagem original pode conter host/usuário; mantém genérica para
                // quem chama, mas preserva o original encadeado para o log.
                throw new Exception("Erro ao conectar ao banco de dados", 0, $e);
            }
        }

        return self::$pdo;
    }

    public static function beginTransaction(): void
    {
        try {
            if (self::$pdo === null) {
                self::$pdo = self::getConnection();
            }

            if (!self::$pdo->inTransaction()) {
                self::$pdo->beginTransaction();
            }
        } catch (\PDOException $e) {
            throw new Exception("Erro ao iniciar a transação: " . $e->getMessage());
        }
    }

    public static function commit(): void
    {
        try {
            if (self::$pdo !== null && self::$pdo->inTransaction()) {
                self::$pdo->commit();
            }
        } catch (PDOException $e) {
            throw new Exception("Erro ao confirmar a transação: " . $e->getMessage(), 0, $e);
        }
    }

    public static function rollBack(): void
    {
        try {
            if (self::$pdo !== null && self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
        } catch (PDOException $e) {
            throw new Exception("Erro ao desfazer a transação: " . $e->getMessage(), 0, $e);
        }
    }

    public static function inTransaction(): bool
    {
        return self::$pdo ? self::$pdo->inTransaction() : false;
    }

    /**
     * Encerra a conexão compartilhada.
     *
     * Necessário antes de operações administrativas que exigem que não haja
     * sessão aberta no banco (DROP DATABASE, por exemplo). A próxima chamada a
     * getConnection() abre uma conexão nova.
     */
    public static function close(): void
    {
        if (self::$pdo !== null && self::$pdo->inTransaction()) {
            self::$pdo->rollBack();
        }

        self::$pdo = null;
    }
}
