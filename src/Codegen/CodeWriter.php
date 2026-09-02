<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

use Diogodg\Neoorm\Schema\Type\TypeSpec;

/**
 * As partes mecânicas de emitir PHP: cabeçalho, imports, literais.
 *
 * Determinismo é requisito, não capricho: os arquivos são commitados, então qualquer
 * variação entre execuções vira diff espúrio no repositório de quem usa. Daí não haver
 * data, host nem versão no cabeçalho — um bump de patch não pode reescrever tudo — e a
 * quebra de linha ser sempre `"\n"`, nunca `PHP_EOL`.
 */
final class CodeWriter
{
    private function __construct()
    {
    }

    /**
     * @param list<string> $imports FQCNs; ordenados e deduplicados aqui
     */
    public static function header(string $namespace, array $imports = []): string
    {
        $lines = [
            '<?php',
            '',
            'declare(strict_types=1);',
            '',
            '// ' . GeneratedFile::MARKER . ' — não edite à mão.',
            '// Regenere com: vendor/bin/neoorm generate:types',
            '',
            "namespace {$namespace};",
        ];

        $imports = array_values(array_unique($imports));
        sort($imports, SORT_STRING);

        if ($imports !== []) {
            $lines[] = '';

            foreach ($imports as $import) {
                $lines[] = "use {$import};";
            }
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param list<string> $lines
     */
    public static function docblock(array $lines, string $indent = ''): string
    {
        if ($lines === []) {
            return '';
        }

        $out = [$indent . '/**'];

        foreach ($lines as $line) {
            $out[] = $line === '' ? $indent . ' *' : $indent . ' * ' . $line;
        }

        $out[] = $indent . ' */';

        return implode("\n", $out) . "\n";
    }

    /**
     * Literal PHP de um escalar. Usado nos valores de enum e nos defaults.
     */
    public static function literal(string|int|float|bool|null $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => $value ? 'true' : 'false',
            is_string($value) => "'" . str_replace(["\\", "'"], ["\\\\", "\\'"], $value) . "'",
            default => var_export($value, true),
        };
    }

    /**
     * Um `TypeSpec` como construtor explícito.
     *
     * Emitir `new TypeSpec(TypeName::Varchar, length: 120)` em vez de
     * `TypeSpec::parse('VARCHAR', 120)` evita um parse por instância de tabela e deixa o
     * arquivo gerado dizer o que é sem precisar interpretar string.
     */
    public static function typeSpec(TypeSpec $type): string
    {
        $arguments = ['TypeName::' . $type->name->name];

        if ($type->length !== null) {
            $arguments[] = "length: {$type->length}";
        }

        if ($type->precision !== null) {
            $arguments[] = "precision: {$type->precision}";
        }

        if ($type->scale !== null) {
            $arguments[] = "scale: {$type->scale}";
        }

        if ($type->values !== null) {
            $values = implode(', ', array_map(self::literal(...), $type->values));
            $arguments[] = "values: [{$values}]";
        }

        if ($type->unsigned) {
            $arguments[] = 'unsigned: true';
        }

        return 'new TypeSpec(' . implode(', ', $arguments) . ')';
    }
}
