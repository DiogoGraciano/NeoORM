<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use PDO;
use PDOStatement;

/**
 * Um PDO que anota o que recebeu em vez de falar com banco.
 *
 * Estende `PDO` **sem chamar `parent::__construct()`**: é o que permite existir sem
 * conexão. A técnica é padrão e segura enquanto todo método usado estiver sobrescrito —
 * um método não sobrescrito acessaria o handle não inicializado.
 *
 * Existe porque há coisas que só o caminho de execução prova: que o tipo do bind foi
 * mesmo aplicado, que o `returningOne()` do MySQL emite exatamente dois statements na
 * ordem certa, que o aninhamento de transação emite os savepoints certos. Nada disso
 * aparece testando o compilador, que só devolve texto.
 */
final class FakePdo extends PDO
{
    /** @var list<string> os statements executados, em ordem */
    public array $executed = [];

    /** @var list<array<string,array{mixed,int}>> os binds de cada prepare, em ordem */
    public array $binds = [];

    private bool $inTransaction = false;

    public bool $failNextCommit = false;

    public bool $failNextExec = false;

    /**
     * @param list<list<array<string,mixed>>> $resultSets uma fila: cada execute consome um
     */
    public function __construct(
        private array $resultSets = [],
        private string|false $lastInsertId = '1',
    ) {
        // Sem parent::__construct() de propósito.
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->executed[] = $query;
        $index = count($this->executed) - 1;
        $this->binds[$index] = [];

        return new FakeStatement(
            $this,
            $index,
            array_shift($this->resultSets) ?? [],
        );
    }

    public function exec(string $statement): int|false
    {
        $this->executed[] = $statement;

        if ($this->failNextExec) {
            $this->failNextExec = false;

            throw new \RuntimeException('exec falhou');
        }

        return 0;
    }

    public function beginTransaction(): bool
    {
        $this->executed[] = 'BEGIN';
        $this->inTransaction = true;

        return true;
    }

    public function commit(): bool
    {
        $this->executed[] = 'COMMIT';

        if ($this->failNextCommit) {
            $this->failNextCommit = false;

            throw new \RuntimeException('commit falhou');
        }

        $this->inTransaction = false;

        return true;
    }

    public function rollBack(): bool
    {
        $this->executed[] = 'ROLLBACK';
        $this->inTransaction = false;

        return true;
    }

    public function inTransaction(): bool
    {
        return $this->inTransaction;
    }

    public function lastInsertId(?string $name = null): string|false
    {
        return $this->lastInsertId;
    }

    public function recordBind(int $statement, string $placeholder, mixed $value, int $type): void
    {
        $this->binds[$statement][$placeholder] = [$value, $type];
    }

    /** Simula servidor que encerrou a transação (deadlock, timeout ou DDL implícito). */
    public function endTransaction(): void
    {
        $this->inTransaction = false;
    }

    /**
     * Só os statements que são SQL de verdade, sem o bookkeeping de transação.
     *
     * @return list<string>
     */
    public function queries(): array
    {
        return array_values(array_filter(
            $this->executed,
            static fn (string $sql): bool => !in_array($sql, ['BEGIN', 'COMMIT', 'ROLLBACK'], true),
        ));
    }
}
