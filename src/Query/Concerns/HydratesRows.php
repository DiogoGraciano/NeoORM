<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Concerns;

use Diogodg\Neoorm\Query\Table;
use PDO;
use PDOStatement;

/**
 * A fronteira comum entre resultado cru do PDO e DTO gerado.
 *
 * INSERT, UPDATE e DELETE com RETURNING percorrem a resposta da mesma forma. Manter o
 * laço aqui garante que uma correção de hidratação não fique aplicada em só dois dos
 * três builders.
 */
trait HydratesRows
{
    /**
     * @template THydrated of object
     * @param Table<THydrated> $table
     * @return list<THydrated>
     */
    private function hydrateRows(PDOStatement $statement, Table $table): array
    {
        $rows = [];

        /** @var array<string,mixed> $raw */
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $raw) {
            $rows[] = $table->hydrate($raw);
        }

        return $rows;
    }
}
