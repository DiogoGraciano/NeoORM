<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use RuntimeException;

/**
 * Golden files: o conteúdo esperado mora num arquivo versionado, não no teste.
 *
 * Vale a pena para artefatos grandes e legíveis — a DDL inteira de um dialeto,
 * um snapshot JSON. O arquivo é revisável como diff no code review, o que é
 * exatamente a revisão que se quer para "mudei o gerador de SQL": dá para ver de
 * uma vez tudo o que passou a sair diferente.
 */
trait SnapshotAssertions
{
    final protected function assertMatchesSnapshot(string $actual, string $name): void
    {
        $path = self::snapshotPath($name);

        if (self::shouldUpdateSnapshots()) {
            $directory = dirname($path);

            if (!is_dir($directory) && !mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new RuntimeException("Não foi possível criar {$directory}");
            }

            file_put_contents($path, $actual);

            $this->addToAssertionCount(1);

            return;
        }

        if (!is_file($path)) {
            $this->fail(
                "Golden file ausente: {$path}\n"
                . 'Gere com: composer test:snapshots',
            );
        }

        $this->assertSame(
            (string) file_get_contents($path),
            $actual,
            "Golden file {$name} divergiu. Se a mudança é intencional, "
            . 'rode `composer test:snapshots` e revise o diff.',
        );
    }

    /**
     * Escrever golden file em CI é um teste que se auto-aprova.
     *
     * Sem esta guarda, um `UPDATE_SNAPSHOTS=1` vazado para o ambiente de CI faria
     * a suíte gravar o que quer que o código produzisse e passar — silenciosamente
     * transformando o gate em nada. O CI ainda roda
     * `git diff --exit-code tests/__snapshots__` como segundo cinto.
     */
    private static function shouldUpdateSnapshots(): bool
    {
        $update = self::envFlag('UPDATE_SNAPSHOTS');

        if (!$update) {
            return false;
        }

        if (self::envFlag('CI')) {
            throw new RuntimeException(
                'UPDATE_SNAPSHOTS está ligado com CI=true. Golden file gravado em CI é '
                . 'teste que aprova a si mesmo; gere localmente e commite o arquivo.',
            );
        }

        return true;
    }

    private static function envFlag(string $name): bool
    {
        $value = $_ENV[$name] ?? $_SERVER[$name] ?? getenv($name);

        if ($value === false || $value === null || $value === '') {
            return false;
        }

        return !in_array(strtolower((string) $value), ['0', 'false', 'off', 'no'], true);
    }

    private static function snapshotPath(string $name): string
    {
        return dirname(__DIR__, 2) . '/__snapshots__/' . $name;
    }
}
