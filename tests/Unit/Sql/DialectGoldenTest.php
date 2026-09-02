<?php

declare(strict_types=1);

namespace Tests\Unit\Sql;

use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Dialect\SqlWriter;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DialectProviders;
use Tests\Support\Concerns\SnapshotAssertions;
use Tests\Support\Factory\Ops;
use Tests\Support\UnitTestCase;

/**
 * A superfície inteira de cada dialeto num arquivo só.
 *
 * Os testes vizinhos afirmam comportamentos pontuais; este afirma o conjunto. O
 * valor está no code review: mexer no gerador de SQL produz um diff onde dá para
 * ler de uma vez tudo o que passou a sair diferente, em vez de descobrir pela
 * lista de testes que quebraram.
 *
 * Regenerar com `composer test:snapshots`, e revisar o diff antes de commitar —
 * o golden file é a revisão, não a asserção.
 */
final class DialectGoldenTest extends UnitTestCase
{
    use DialectProviders;
    use SnapshotAssertions;

    #[DataProvider('dialects')]
    public function testTheWholeDialectSurfaceMatchesItsGoldenFile(string $dialect): void
    {
        $compiler = DialectFactory::for($dialect);
        $writer = new SqlWriter();

        $sections = [
            "-- Superfície do dialeto {$dialect}, gerada por DialectGoldenTest.",
            '-- Regenere com: composer test:snapshots',
        ];

        foreach ([...Ops::catalog(), ...Ops::variants()] as $label => $operation) {
            $statements = $compiler->compile($operation);

            $sections[] = "\n-- [{$label}] {$operation->describe()}";
            $sections[] = $statements === []
                ? '-- (nenhum statement neste dialeto)'
                : rtrim($writer->write($statements), "\n");
        }

        $sections[] = "\n-- [migrations_table] tabela de controle do runner";
        $sections[] = rtrim($writer->write($compiler->migrationsTableDdl('_neoorm_migrations')), "\n");

        $this->assertMatchesSnapshot(implode("\n", $sections) . "\n", "dialect_{$dialect}.sql");
    }
}
