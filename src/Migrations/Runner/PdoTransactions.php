<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use PDO;

final class PdoTransactions implements Transactions
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function begin(): void
    {
        if ($this->pdo->inTransaction()) {
            throw new MigrationException(
                'begin() com uma transação já aberta. O PDO não aninha transações, então a segunda '
                . 'chamada seria silenciosamente ignorada e o commit seguinte fecharia a de fora.',
            );
        }

        $this->pdo->beginTransaction();
    }

    public function commit(): void
    {
        if (!$this->pdo->inTransaction()) {
            throw new MigrationException('commit() sem transação aberta.');
        }

        $this->pdo->commit();
    }

    /**
     * O rollback tolera não haver transação aberta, ao contrário do commit.
     *
     * A assimetria é deliberada: este método é chamado de dentro de um `catch`, e nesse
     * ponto pode ser que a transação já tenha sido desfeita pelo próprio servidor — o
     * MySQL faz isso ao dar deadlock ou timeout, e um DDL lá já a fechou por commit
     * implícito. Lançar aqui trocaria a exceção original, a que diz o que realmente deu
     * errado, por uma sobre bookkeeping.
     */
    public function rollBack(): void
    {
        if ($this->pdo->inTransaction()) {
            $this->pdo->rollBack();
        }
    }

    public function inTransaction(): bool
    {
        return $this->pdo->inTransaction();
    }
}
