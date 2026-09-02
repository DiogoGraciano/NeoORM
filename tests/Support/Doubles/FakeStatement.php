<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use PDOStatement;

/**
 * O statement do {@see FakePdo}: anota os binds e devolve o resultado roteirizado.
 */
final class FakeStatement extends PDOStatement
{
    private int $cursor = 0;

    /**
     * @param list<array<string,mixed>> $rows
     */
    public function __construct(
        private readonly FakePdo $pdo,
        private readonly int $index,
        private readonly array $rows = [],
    ) {
    }

    public function bindValue(string|int $param, mixed $value, int $type = \PDO::PARAM_STR): bool
    {
        // Sem o `:` inicial: a asserção fica `$pdo->binds[0]['p0']`, que é como o
        // `BindCollector` nomeia o placeholder.
        $this->pdo->recordBind($this->index, ltrim((string) $param, ':'), $value, $type);

        return true;
    }

    public function execute(?array $params = null): bool
    {
        return true;
    }

    public function fetchAll(int $mode = \PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        if ($mode === \PDO::FETCH_COLUMN) {
            return array_map(static fn (array $row): mixed => reset($row) ?: null, $this->rows);
        }

        return $this->rows;
    }

    public function fetch(int $mode = \PDO::FETCH_DEFAULT, int $cursorOrientation = \PDO::FETCH_ORI_NEXT, int $cursorOffset = 0): mixed
    {
        return $this->rows[$this->cursor++] ?? false;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $row = $this->rows[0] ?? null;

        if ($row === null) {
            return false;
        }

        return array_values($row)[$column] ?? false;
    }

    public function rowCount(): int
    {
        return count($this->rows);
    }
}
