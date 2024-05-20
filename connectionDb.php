<?php
namespace app\db;

require __DIR__.DIRECTORY_SEPARATOR."configDb.php";
use ErrorException;
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
    private $pdo = null;

    /**
     * Armazena as instâncias Singleton das subclasses.
     *
     * @var array
     */
    private static $instances = [];

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
     * Obtém a instância Singleton da classe.
     *
     * @return ConnectionDb
     */
    public static function getInstance(): ConnectionDb
    {
        $cls = static::class;
        if (!isset(self::$instances[$cls])) {
            self::$instances[$cls] = new static();
        }

        return self::$instances[$cls];
    }

    /**
     * Obtém a conexão com o banco de dados usando o PDO.
     *
     * @return PDO Retorna uma instância do objeto PDO.
     *
     * @throws ErrorException Lança uma exceção se ocorrer um erro ao conectar com o banco de dados.
     */
    public function startConnection(): PDO
    {
        if ($this->pdo === null) {
            try {
                $dsn = sprintf(
                    'mysql:host=%s;port=%s;dbname=%s;charset=%s',
                    DBHOST,
                    DBPORT,
                    DBNAME,
                    DBCHARSET
                );
                $this->pdo = new PDO($dsn, DBUSER, DBPASSWORD);
                $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            } catch (PDOException $e) {
                // Lança uma exceção personalizada
                throw new ErrorException("Erro ao conectar ao banco de dados");
            }
        }

        return $this->pdo;
    }

    /**
     * Inicia uma transação no banco de dados.
     *
     * @return void
     *
     * @throws ErrorException Lança uma exceção se ocorrer um erro ao iniciar a transação.
     */
    public function beginTransaction(): void
    {
        try {
            $this->startConnection()->beginTransaction();
        } catch (PDOException $e) {
            throw new ErrorException("Erro ao iniciar a transação");
        }
    }

    /**
     * Confirma a transação no banco de dados.
     *
     * @return void
     *
     * @throws ErrorException Lança uma exceção se ocorrer um erro ao confirmar a transação.
     */
    public function commit(): void
    {
        try {
            $this->startConnection()->commit();
        } catch (PDOException $e) {
            throw new ErrorException("Erro ao confirmar a transação");
        }
    }

    /**
     * Desfaz a transação no banco de dados.
     *
     * @return void
     *
     * @throws ErrorException Lança uma exceção se ocorrer um erro ao desfazer a transação.
     */
    public function rollBack(): void
    {
        try {
            $this->startConnection()->rollBack();
        } catch (PDOException $e) {
            throw new ErrorException("Erro ao desfazer a transação");
        }
    }
}
?>
