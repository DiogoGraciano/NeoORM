<?php

namespace app\db;

require __DIR__.DIRECTORY_SEPARATOR."configDb.php";

use Exception;
use PDO;
use PDOException;

/**
 * Classe para configuração e obtenção da conexão com o banco de dados.
 */
class ConnectionDb
{
    /**
     * Instância do objeto PDO para a conexão com o banco de dados.
     *
     * @var PDO|null
     */
    private static $pdo = null;

    /**
     * ConnectionDb constructor.
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
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    DBHOST,
                    DBPORT,
                    DBNAME,
                    DBCHARSET
                );
                self::$pdo = new PDO($dsn, DBUSER, DBPASSWORD);
                self::$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                // Lança uma exceção personalizada
                throw new Exception("Erro ao conectar ao banco de dados");
            }
        }

        return self::$pdo;
    }
}
?>
