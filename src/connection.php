<?php

namespace diogodg\neoorm;

use Exception;
use PDO;
use PDOException;

/**
 * Classe para configuração e obtenção da conexão com o banco de dados.
 */
class connection
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
    private function __construct() 
    {
    }

    /**
     * Impede a clonagem da instância.
     */
    private function __clone() 
    {
    }

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
                if(DRIVER == "mysql"){
                    $dsn = sprintf(
                        DRIVER.':host=%s;port=%s;dbname=%s;charset=%s',
                        DBHOST,
                        DBPORT,
                        DBNAME,
                        DBCHARSET
                    );
                }else{
                    $dsn = sprintf(
                        DRIVER.':host=%s;port=%s;dbname=%s',
                        DBHOST,
                        DBPORT,
                        DBNAME
                    );
                }
                self::$pdo = new PDO($dsn, DBUSER, DBPASSWORD);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                throw new Exception("Erro ao conectar ao banco de dados");
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
            if (self::$pdo->inTransaction()) {
                self::$pdo->commit();
            }
        } catch (\PDOException $e) {
            throw new Exception("Erro ao confirmar a transação: " . $e->getMessage());
        }
    }

    public static function rollBack(): void
    {
        try {
            if (self::$pdo->inTransaction()) {
                self::$pdo->rollBack();
            }
        } catch (\PDOException $e) {
            throw new Exception("Erro ao desfazer a transação: " . $e->getMessage());
        }
    }

    public static function inTransaction(): bool
    {
        return self::$pdo ? self::$pdo->inTransaction() : false;
    }
}
?>
