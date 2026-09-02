<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Transaction;

use Diogodg\Neoorm\Dialect\Dialect;
use PDO;

/**
 * Transação que aninha, por savepoint.
 *
 * O nível 0 é a transação de verdade (`BEGIN`); do 1 em diante são savepoints. É o que
 * faz um serviço que abre transação poder chamar outro que também abre, sem que o
 * `commit()` interno feche a transação externa — o buraco que o `Connection` estático
 * tinha, onde `beginTransaction()` era no-op se já houvesse transação.
 */
final class SavepointTransactions implements Transactions
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly Dialect $dialect,
        private readonly TransactionState $state = new TransactionState(),
    ) {
    }

    public function begin(): void
    {
        if ($this->state->depth === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec($this->dialect->savepoint($this->state->depth));
        }

        $this->state->depth++;
    }

    public function commit(): void
    {
        if ($this->state->depth === 0) {
            throw new \LogicException('commit() sem transação aberta.');
        }

        $targetDepth = $this->state->depth - 1;

        try {
            if ($targetDepth === 0) {
                $this->pdo->commit();
            } else {
                $this->pdo->exec($this->dialect->releaseSavepoint($targetDepth));
            }
        } catch (\Throwable $e) {
            // Alguns servidores encerram a transação ao falhar o COMMIT. Nesse caso o
            // estado local não pode continuar anunciando savepoints que já não existem.
            if (!$this->pdo->inTransaction()) {
                $this->state->depth = 0;
            }

            throw $e;
        }

        // Só avança o bookkeeping depois que o banco confirmou a operação.
        $this->state->depth = $targetDepth;
    }

    /**
     * O rollback tolera não haver transação aberta, ao contrário do commit.
     *
     * A assimetria é deliberada: este método é chamado de dentro de um `catch`, e ali a
     * transação pode já ter sido desfeita pelo próprio servidor — o MySQL faz isso em
     * deadlock e timeout, e qualquer DDL já a fechou por commit implícito. Lançar aqui
     * trocaria a exceção original, a que diz o que deu errado, por uma sobre bookkeeping.
     */
    public function rollBack(): void
    {
        if ($this->state->depth === 0) {
            return;
        }

        // Vale para qualquer profundidade. Antes esta proteção existia apenas no nível
        // externo, então um deadlock dentro de savepoint tentava executar ROLLBACK TO
        // depois que o servidor já havia encerrado tudo e escondia a exceção original.
        if (!$this->pdo->inTransaction()) {
            $this->state->depth = 0;

            return;
        }

        $targetDepth = $this->state->depth - 1;

        try {
            if ($targetDepth === 0) {
                $this->pdo->rollBack();
            } else {
                $this->pdo->exec($this->dialect->rollbackToSavepoint($targetDepth));
            }
        } finally {
            // Mesmo que o driver reporte erro, não se deve reutilizar localmente um
            // nível que tentamos desfazer. Se o servidor matou a transação, zera tudo.
            $this->synchronizeAfterRollback($targetDepth);
        }
    }

    private function synchronizeAfterRollback(int $targetDepth): void
    {
        $this->state->depth = $this->pdo->inTransaction() ? $targetDepth : 0;
    }

    public function inTransaction(): bool
    {
        return $this->state->depth > 0;
    }

    public function depth(): int
    {
        return $this->state->depth;
    }
}
