<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * Achata estruturas aninhadas em caminhos pontilhados, para a falha ser legível.
 *
 * `assertEquals` de dois arrays aninhados representando um IR de quinze tabelas
 * imprime centenas de linhas e não diz onde está a diferença. Achatando os dois
 * lados e comparando caminho por caminho, a falha vira uma linha:
 * `city.columns.name.type.length: 120 !== null`.
 *
 * Isso não é conforto: é o que decide se alguém investiga a falha ou desiste dela.
 */
final class ArrayPath
{
    private function __construct()
    {
    }

    /**
     * @param array<array-key,mixed> $data
     * @return array<string,mixed>
     */
    public static function flatten(array $data, string $prefix = ''): array
    {
        $flat = [];

        foreach ($data as $key => $value) {
            $path = $prefix === '' ? (string) $key : "{$prefix}.{$key}";

            if (is_array($value) && $value !== []) {
                $flat = [...$flat, ...self::flatten($value, $path)];

                continue;
            }

            $flat[$path] = $value;
        }

        return $flat;
    }

    /**
     * Só os caminhos em que os dois lados discordam.
     *
     * @param array<array-key,mixed> $expected
     * @param array<array-key,mixed> $actual
     * @return array<string,array{expected:mixed,actual:mixed}>
     */
    public static function differences(array $expected, array $actual): array
    {
        $left = self::flatten($expected);
        $right = self::flatten($actual);

        $paths = array_unique([...array_keys($left), ...array_keys($right)]);
        sort($paths, SORT_STRING);

        $differences = [];

        foreach ($paths as $path) {
            $expectedValue = $left[$path] ?? '<ausente>';
            $actualValue = $right[$path] ?? '<ausente>';

            if ($expectedValue !== $actualValue) {
                $differences[$path] = ['expected' => $expectedValue, 'actual' => $actualValue];
            }
        }

        return $differences;
    }

    /**
     * @param array<array-key,mixed> $expected
     * @param array<array-key,mixed> $actual
     */
    public static function describeDifferences(array $expected, array $actual): string
    {
        $differences = self::differences($expected, $actual);

        if ($differences === []) {
            return '';
        }

        $lines = [];

        foreach ($differences as $path => $sides) {
            $lines[] = sprintf(
                '%s: esperado %s, recebido %s',
                $path,
                self::render($sides['expected']),
                self::render($sides['actual']),
            );
        }

        return implode("\n", $lines);
    }

    private static function render(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if ($value === null) {
            return 'null';
        }

        if (is_array($value)) {
            return $value === [] ? '[]' : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_string($value)) {
            return "'{$value}'";
        }

        return (string) $value;
    }
}
