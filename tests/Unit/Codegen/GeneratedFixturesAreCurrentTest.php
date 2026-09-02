<?php

declare(strict_types=1);

namespace Tests\Unit\Codegen;

use Diogodg\Neoorm\Codegen\FileSystemWriter;
use Tests\Support\GeneratedTables;
use Tests\Support\UnitTestCase;

/**
 * Os DTOs commitados em `tests/Generated/` descrevem os models de fixture de hoje.
 *
 * É o `neoorm generate:types --check` rodando contra a própria suíte — o mesmo gate que
 * um projeto põe no CI, exercitado aqui. Sem ele, mudar uma coluna em
 * `tests/App/Models/` deixaria os testes de query passando contra o schema de ontem, o
 * que é pior que falhar: eles continuariam verdes medindo a coisa errada.
 *
 * Roda na suíte unitária porque não precisa de banco: gerar é ler diretório de model e
 * comparar strings.
 */
final class GeneratedFixturesAreCurrentTest extends UnitTestCase
{
    public function testTheCommittedDtosMatchTheFixtureModels(): void
    {
        $files = GeneratedTables::generate();
        $writer = new FileSystemWriter(GeneratedTables::directory());

        if (self::shouldRegenerate()) {
            $writer->write($files);

            $this->addToAssertionCount(1);

            return;
        }

        $drift = $writer->check($files);

        $this->assertTrue(
            $drift->isClean(),
            $drift->summary() . "\nRegenere com `composer test:snapshots` e revise o diff.",
        );
    }

    public function testEveryFixtureModelProducesATableAndARow(): void
    {
        $paths = array_map(
            static fn (object $file): string => $file->relativePath,
            GeneratedTables::generate(),
        );

        // Dez models, e por model uma Row, um Insert e uma Table — mais o registry.
        $this->assertContains('Tables.php', $paths);
        $this->assertContains('Rows/UsersRow.php', $paths);
        $this->assertContains('Inserts/UsersInsert.php', $paths);
        $this->assertContains('UsersTable.php', $paths);
        $this->assertSame(31, count($paths));
    }

    /**
     * Regravar em CI é um teste que se auto-aprova, exatamente como um golden file.
     */
    private static function shouldRegenerate(): bool
    {
        return self::envFlag('UPDATE_SNAPSHOTS') && !self::envFlag('CI');
    }

    private static function envFlag(string $name): bool
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if ($value === false || $value === null || $value === '') {
            return false;
        }

        return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no'], true);
    }
}
