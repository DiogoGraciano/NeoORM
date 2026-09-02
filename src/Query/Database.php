<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Transaction\SavepointTransactions;
use Diogodg\Neoorm\Transaction\TransactionRegistry;
use Diogodg\Neoorm\Transaction\Transactions;
use PDO;
use PDOException;
use PDOStatement;
use Throwable;

/**
 * O ponto de entrada da camada de consulta.
 *
 * ```php
 * $db = Database::fromConfig();
 * $u  = Tables::users();
 *
 * $ativos = $db->select()->from($u)->where(eq($u->active, true))->all();
 * ```
 */
final class Database implements Executor
{
    private readonly Transactions $transactions;

    public function __construct(
        private readonly PDO $pdo,
        private readonly Dialect $dialectInstance,
        ?Transactions $transactions = null,
    ) {
        // O controle vem do registro, chaveado pela CONEXÃO. Instanciar um
        // `SavepointTransactions` próprio aqui daria a cada `Database` um contador de
        // profundidade só seu sobre o mesmo handle, e aí o `transaction()` de um dentro
        // do de outro veria `depth === 0` e tentaria abrir uma transação já aberta.
        $this->transactions = $transactions ?? TransactionRegistry::for($pdo, $dialectInstance);
    }

    /**
     * Sem configuração, reusa a conexão compartilhada.
     *
     * Dois handles para o mesmo banco são duas SESSÕES, e uma transação de migração numa
     * mais uma escrita na outra podem travar uma na outra. Reusar é o padrão seguro;
     * passar um `DatabaseConfig` explícito dá handle independente, que é o que
     * multi-tenant e teste com dois dialetos precisam.
     *
     * O preparo é reaplicado em toda entrada. Código externo pode alterar search_path,
     * timezone ou sql_mode no mesmo PDO; a biblioteca restaura seu contrato antes de
     * entregar o executor.
     */
    public static function fromConfig(?DatabaseConfig $config = null): self
    {
        if ($config === null) {
            $pdo = Connection::getConnection();
            $dialect = DialectFactory::for(
                \Diogodg\Neoorm\Config::getDriver(),
                \Diogodg\Neoorm\Config::getSchema(),
            );
        } else {
            $pdo = $config->connect();
            // O schema vem junto: sem ele o `search_path` não é emitido, e as consultas
            // procuram no schema default do usuário do banco enquanto as migrações — que
            // recebem o `DBSCHEMA` pela fronteira do CLI — criaram noutro. Nenhum SQL
            // gerado qualifica nome por schema, então o `search_path` é o contrato
            // inteiro.
            $dialect = DialectFactory::for($config->driver, $config->schema);
        }

        foreach ($dialect->connectionSetup() as $statement) {
            $pdo->exec($statement);
        }

        return new self($pdo, $dialect);
    }

    public function dialect(): Dialect
    {
        return $this->dialectInstance;
    }

    public function run(CompiledQuery $query): PDOStatement
    {
        try {
            $statement = $this->pdo->prepare($query->sql);
            $query->bindTo($statement);
            $statement->execute();

            return $statement;
        } catch (PDOException $e) {
            // O SQL entra na mensagem: sem ele, "SQLSTATE[42S22] Unknown column" manda
            // procurar em qual das consultas do request o erro aconteceu.
            throw new QueryException(
                $e->getMessage() . ' | SQL: ' . $query->sql,
                previous: $e,
            );
        }
    }

    public function lastInsertId(): string|false
    {
        return $this->pdo->lastInsertId();
    }

    /**
     * @return SelectBuilder<object>
     */
    public function select(): SelectBuilder
    {
        return new SelectBuilder($this);
    }

    /**
     * @param list<Expr\Expression> $columns
     */
    public function selectFields(array $columns): FieldSelectBuilder
    {
        return (new FieldSelectBuilder($this))->withColumns($columns);
    }

    /**
     * @template TRow of object
     * @param Table<TRow> $table
     * @return InsertBuilder<TRow>
     */
    public function insert(Table $table): InsertBuilder
    {
        return new InsertBuilder($this, $table);
    }

    /**
     * @template TRow of object
     * @param Table<TRow> $table
     * @return UpdateBuilder<TRow>
     */
    public function update(Table $table): UpdateBuilder
    {
        return new UpdateBuilder($this, $table);
    }

    /**
     * @template TRow of object
     * @param Table<TRow> $table
     * @return DeleteBuilder<TRow>
     */
    public function delete(Table $table): DeleteBuilder
    {
        return new DeleteBuilder($this, $table);
    }

    /**
     * Roda o bloco numa transação, aninhando por savepoint quando já houver uma.
     *
     * Atenção ao MySQL: DDL provoca commit implícito, então uma transação não protege
     * mudança de schema lá.
     *
     * @template T
     * @param callable(Tx):T $work
     * @return T
     */
    public function transaction(callable $work): mixed
    {
        $this->transactions->begin();

        $depth = $this->transactions instanceof SavepointTransactions
            ? $this->transactions->depth()
            : 1;

        try {
            $result = $work(new Tx($this, $depth));
            $this->transactions->commit();

            return $result;
        } catch (Throwable $e) {
            $this->transactions->rollBack();

            throw $e;
        }
    }
}
