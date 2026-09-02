<?php

declare(strict_types=1);

namespace Tests\Unit\Diff;

use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Dialect\SqlWriter;
use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DialectProviders;
use Tests\Support\Factory\DriftPairs;
use Tests\Support\Factory\EdgeCaseSchemas;
use Tests\Support\UnitTestCase;

/**
 * A costura entre o differ e os dialetos.
 *
 * Os testes do differ afirmam quais operações saem; os dos dialetos afirmam que cada
 * operação vira SQL. Nenhum dos dois pega o caso em que o differ produz uma
 * combinação que um dialeto não sabe renderizar — e é um caso real: `AlterColumn`
 * com identity e `SetTableOptions` com engine são operações que os dois dialetos
 * tratam de formas bem diferentes.
 *
 * Continua sem banco: só compila.
 */
final class DiffCompilesOnBothDialectsTest extends UnitTestCase
{
    use DialectProviders;

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function pairsAndDialects(): iterable
    {
        foreach (DialectFactory::all() as $dialect => $unused) {
            foreach (array_keys(DriftPairs::all()) as $pair) {
                yield "{$dialect}/{$pair}" => [$dialect, $pair];
            }
        }
    }

    #[DataProvider('pairsAndDialects')]
    public function testEveryDiffCompilesToSql(string $dialect, string $pair): void
    {
        [$from, $to] = DriftPairs::all()[$pair];

        $operations = (new SchemaDiffer())->diff(
            Snapshot::initial($dialect, $from),
            Snapshot::initial($dialect, $to),
        );

        $statements = DialectFactory::for($dialect)->compileAll($operations);

        foreach ($statements as $sql) {
            $this->assertNotSame('', trim($sql));
            $this->assertStringNotContainsString(';', $sql);
        }

        // Um diff não vazio que compila para nenhum statement é uma migração que diz
        // ter mudado algo e não muda nada. O único caso legítimo é o de opções de
        // tabela no PostgreSQL, onde engine e collation não existem.
        if (!$operations->isEmpty() && $statements === []) {
            $this->assertSame(
                'pgsql',
                $dialect,
                "O caso '{$pair}' gerou operações mas nenhum statement em {$dialect}.",
            );
        }

        $this->addToAssertionCount(1);
    }

    /**
     * @return iterable<string,array{string,string}>
     */
    public static function schemasAndDialects(): iterable
    {
        foreach (DialectFactory::all() as $dialect => $unused) {
            foreach (array_keys(EdgeCaseSchemas::all()) as $schema) {
                yield "{$dialect}/{$schema}" => [$dialect, $schema];
            }
        }
    }

    /**
     * Criar cada schema do zero produz um arquivo `.sql` completo, sem exceção e sem
     * statement vazio. É o caminho do `migration:generate initial`.
     */
    #[DataProvider('schemasAndDialects')]
    public function testCreatingEverySchemaFromScratchProducesAWritableFile(string $dialect, string $schema): void
    {
        $operations = (new SchemaDiffer())->diff(
            Snapshot::baseline($dialect),
            Snapshot::initial($dialect, EdgeCaseSchemas::get($schema)),
        );

        $file = (new SqlWriter())->write(DialectFactory::for($dialect)->compileAll($operations));

        if ($schema === 'empty') {
            $this->assertSame('', $file);

            return;
        }

        $this->assertStringEndsWith(";\n", $file);
        $this->assertStringContainsString('CREATE TABLE', $file);
    }
}
