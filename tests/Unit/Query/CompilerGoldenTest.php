<?php

declare(strict_types=1);

namespace Tests\Unit\Query;

use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Query\Compiler\Compiler;
use Diogodg\Neoorm\Query\Compiler\CompiledQuery;
use Diogodg\Neoorm\Query\Exception\QueryException;
use Diogodg\Neoorm\Query\State\DeleteState;
use Diogodg\Neoorm\Query\State\InsertState;
use Diogodg\Neoorm\Query\State\SelectState;
use Diogodg\Neoorm\Query\State\UpdateState;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DialectProviders;
use Tests\Support\Concerns\SnapshotAssertions;
use Tests\Support\Factory\Queries;
use Tests\Support\UnitTestCase;

/**
 * Toda forma de consulta que a camada sabe montar, num arquivo por dialeto.
 *
 * Mesmo papel do `DialectGoldenTest` para a DDL: o valor está no code review. Mexer no
 * compilador produz um diff onde se lê de uma vez tudo o que passou a sair diferente —
 * e, principalmente, dá para comparar os dois arquivos lado a lado e ver onde os bancos
 * divergem.
 *
 * Os binds saem junto com o SQL de propósito. Um erro de ordem de bind não muda o texto
 * da query, só o valor que vai em cada posição, e sem isso no golden passaria despercebido.
 *
 * Regenerar com `composer test:snapshots` e revisar o diff antes de commitar.
 */
final class CompilerGoldenTest extends UnitTestCase
{
    use DialectProviders;
    use SnapshotAssertions;

    #[DataProvider('dialects')]
    public function testEveryQueryFormMatchesItsGoldenFile(string $dialect): void
    {
        $compiler = new Compiler();
        $engine = DialectFactory::for($dialect);

        $sections = [
            "-- Consultas do dialeto {$dialect}, geradas por CompilerGoldenTest.",
            '-- Regenere com: composer test:snapshots',
        ];

        foreach (Queries::catalog() as $label => $state) {
            $sections[] = "\n-- [{$label}]";
            $sections[] = $this->render($compiler, $state, $engine);
        }

        foreach (Queries::returningVariants() as $label => $state) {
            $sections[] = "\n-- [{$label}]";

            try {
                $sections[] = $this->render($compiler, $state, $engine);
            } catch (QueryException $e) {
                // RETURNING não existe no MySQL. Registrar a recusa no golden é melhor
                // que omitir o caso: o arquivo passa a mostrar o que cada banco NÃO faz.
                $sections[] = '-- recusado: ' . $e->getMessage();
            }
        }

        $this->assertMatchesSnapshot(implode("\n", $sections) . "\n", "query_{$dialect}.sql");
    }

    private function render(
        Compiler $compiler,
        SelectState|InsertState|UpdateState|DeleteState $state,
        \Diogodg\Neoorm\Dialect\Dialect $dialect,
    ): string {
        $compiled = match (true) {
            $state instanceof SelectState => $compiler->compileSelect($state, $dialect),
            $state instanceof InsertState => $compiler->compileInsert($state, $dialect),
            $state instanceof UpdateState => $compiler->compileUpdate($state, $dialect),
            $state instanceof DeleteState => $compiler->compileDelete($state, $dialect),
        };

        return $compiled->sql . ';' . $this->binds($compiled);
    }

    private function binds(CompiledQuery $compiled): string
    {
        if ($compiled->binds === []) {
            return '';
        }

        $lines = [];

        foreach ($compiled->binds as $placeholder => [$value, $type]) {
            $lines[] = "--   :{$placeholder} = " . var_export($value, true) . " (PDO type {$type})";
        }

        return "\n" . implode("\n", $lines);
    }
}
