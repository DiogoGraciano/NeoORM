<?php

declare(strict_types=1);

namespace Diogodg\Neoorm;

use Diogodg\Neoorm\Transaction\TransactionRegistry;
use Exception;
use PDO;
use PDOException;

/**
 * Classe para configuração e obtenção da conexão com o banco de dados.
 */
final class Connection
{
    private static ?PDO $pdo = null;

    /**
     * connection constructor.
     * Privado para impedir a criação direta de instâncias (Singleton).
     */
    private function __construct()
    {
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
                // DatabaseConfig é a única fronteira que conhece DSN e opções do PDO.
                // Duplicar essa montagem aqui já fez a conexão compartilhada divergir
                // das conexões explícitas uma vez.
                self::$pdo = DatabaseConfig::fromConfig()->connect();
            } catch (PDOException $e) {
                // A mensagem original pode conter host/usuário; mantém genérica para
                // quem chama, mas preserva o original encadeado para o log.
                throw new Exception('Erro ao conectar ao banco de dados', 0, $e);
            }
        }

        return self::$pdo;
    }

    /**
     * Indica se a conexão compartilhada já foi aberta.
     *
     * Existe para a suíte unitária poder afirmar que nenhum teste puro abriu
     * socket: definir schema, gerar snapshot e diferenciar não podem depender
     * de banco, e a única forma de garantir isso é verificar.
     */
    public static function isOpen(): bool
    {
        return self::$pdo !== null;
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

        if (self::$pdo !== null) {
            // O controle de transação guarda a profundidade dos savepoints; uma sessão
            // nova precisa começar em zero.
            TransactionRegistry::flush(self::$pdo);
        }

        self::$pdo = null;
    }
}
