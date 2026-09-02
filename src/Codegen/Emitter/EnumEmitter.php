<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen\Emitter;

use Diogodg\Neoorm\Codegen\CodeWriter;
use Diogodg\Neoorm\Codegen\GeneratedFile;
use Diogodg\Neoorm\Codegen\Plan\TablePlan;

/**
 * Um enum PHP por coluna ENUM.
 *
 * É o artefato de maior retorno do gerador: transforma `$row->status === 'activ'` — que
 * é `false` para sempre, sem erro — em erro de compilação.
 *
 * Quando algum valor não rende nome de case válido ou dois colidem, o `TablePlan` não
 * cria o enum e a coluna fica `string`. Melhor perder o refinamento numa coluna do que
 * emitir arquivo que não parseia.
 */
final class EnumEmitter
{
    /**
     * @return list<GeneratedFile>
     */
    public function emit(TablePlan $plan, string $namespace): array
    {
        $files = [];

        foreach ($plan->columns as $column) {
            if ($column->enumClass === null || $column->enumCases === null) {
                continue;
            }

            $short = substr($column->enumClass, (int) strrpos($column->enumClass, '\\') + 1);

            $code = CodeWriter::header($namespace . '\\Enums');
            $code .= "\n";
            $code .= CodeWriter::docblock([
                "Valores de `{$plan->table->name}`.`{$column->name()}`.",
            ]);
            $code .= "enum {$short}: string\n{\n";

            foreach ($column->enumCases as $case => $value) {
                $code .= "    case {$case} = " . CodeWriter::literal($value) . ";\n";
            }

            $code .= "}\n";

            $files[] = new GeneratedFile("Enums/{$short}.php", $code);
        }

        return $files;
    }
}
