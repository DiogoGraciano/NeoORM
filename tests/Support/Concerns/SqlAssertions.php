<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use Tests\Support\SqlNormalizer;

trait SqlAssertions
{
    /**
     * Compara SQL ignorando só indentação.
     *
     * Ver `SqlNormalizer` para o que a normalização faz e, mais importante, para o
     * que ela se recusa a fazer.
     */
    final protected function assertSqlEquals(string $expected, string $actual, string $message = ''): void
    {
        $this->assertSame(
            SqlNormalizer::collapse($expected),
            SqlNormalizer::collapse($actual),
            $message,
        );
    }

    /**
     * @param list<string> $expected
     * @param list<string> $actual
     */
    final protected function assertSqlListEquals(array $expected, array $actual, string $message = ''): void
    {
        $this->assertSame(
            SqlNormalizer::collapseAll($expected),
            SqlNormalizer::collapseAll($actual),
            $message,
        );
    }

    /**
     * A operação compila para exatamente um statement, e é este.
     *
     * @param list<string> $actual
     */
    final protected function assertSingleSql(string $expected, array $actual, string $message = ''): void
    {
        $this->assertCount(
            1,
            $actual,
            $message !== '' ? $message : 'Esperava um único statement, veio: ' . implode(' | ', $actual),
        );
        $this->assertSqlEquals($expected, $actual[0], $message);
    }
}
