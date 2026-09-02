<?php

declare(strict_types=1);

namespace Tests\Unit\Transaction;

use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Transaction\SavepointTransactions;
use Diogodg\Neoorm\Transaction\TransactionRegistry;
use RuntimeException;
use Tests\Support\Doubles\FakePdo;
use Tests\Support\UnitTestCase;
use WeakReference;

final class SavepointTransactionsTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        TransactionRegistry::flush();
    }

    public function testRegistrySharesDepthWithoutRetainingThePdoKey(): void
    {
        $pdo = new FakePdo();
        $reference = WeakReference::create($pdo);
        $first = TransactionRegistry::for($pdo, DialectFactory::pgsql());
        $second = TransactionRegistry::for($pdo, DialectFactory::pgsql());

        $first->begin();
        $second->begin();

        $this->assertSame(['BEGIN', 'SAVEPOINT "neoorm_sp1"'], $pdo->executed);

        $second->rollBack();
        $first->rollBack();
        unset($first, $second, $pdo);
        gc_collect_cycles();

        $this->assertNull($reference->get(), 'O registro não pode manter a conexão viva.');
    }

    public function testServerEndedNestedTransactionResetsDepthWithoutExecutingSavepointRollback(): void
    {
        $pdo = new FakePdo();
        $transactions = new SavepointTransactions($pdo, DialectFactory::mysql());
        $transactions->begin();
        $transactions->begin();
        $pdo->endTransaction();

        $transactions->rollBack();

        $this->assertSame(0, $transactions->depth());
        $this->assertSame(['BEGIN', 'SAVEPOINT `neoorm_sp1`'], $pdo->executed);
    }

    public function testFailedSavepointReleaseDoesNotPrematurelyDecreaseDepth(): void
    {
        $pdo = new FakePdo();
        $transactions = new SavepointTransactions($pdo, DialectFactory::pgsql());
        $transactions->begin();
        $transactions->begin();
        $pdo->failNextExec = true;

        try {
            $transactions->commit();
            $this->fail('A falha do release deveria ser propagada.');
        } catch (RuntimeException) {
            $this->assertSame(2, $transactions->depth());
        }

        $transactions->rollBack();
        $transactions->rollBack();
        $this->assertSame(0, $transactions->depth());
    }

    public function testFailedOuterCommitKeepsDepthWhileTransactionRemainsOpen(): void
    {
        $pdo = new FakePdo();
        $transactions = new SavepointTransactions($pdo, DialectFactory::pgsql());
        $transactions->begin();
        $pdo->failNextCommit = true;

        try {
            $transactions->commit();
            $this->fail('A falha do commit deveria ser propagada.');
        } catch (RuntimeException) {
            $this->assertSame(1, $transactions->depth());
        }

        $transactions->rollBack();
        $this->assertSame(0, $transactions->depth());
    }
}
