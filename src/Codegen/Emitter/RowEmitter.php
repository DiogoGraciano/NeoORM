<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen\Emitter;

use Diogodg\Neoorm\Codegen\CodeWriter;
use Diogodg\Neoorm\Codegen\GeneratedFile;
use Diogodg\Neoorm\Codegen\Plan\ColumnPlan;
use Diogodg\Neoorm\Codegen\Plan\TablePlan;
use Diogodg\Neoorm\Runtime\Casting\Cast;

/**
 * O DTO de linha: `final readonly`, com `fromRow()` hidratando coluna por coluna.
 *
 * A hidratação é **gerada**, não reflexiva. Um hidratador genérico dirigido por metadados
 * seria menos código, e perderia as quatro coisas que importam: velocidade (sem reflexão
 * por linha), verificabilidade (o analisador confere a chamada do construtor no ponto de
 * uso), legibilidade em code review (a conversão de cada coluna está à vista no diff) e
 * ausência de metadado de schema em runtime.
 */
final class RowEmitter
{
    public function emit(TablePlan $plan, string $namespace): GeneratedFile
    {
        $ns = $namespace . '\\Rows';
        $imports = [...$plan->rowImports(), Cast::class];

        foreach ($plan->columns as $column) {
            if ($column->enumClass !== null) {
                $imports[] = $column->enumClass;
            }
        }

        $code = CodeWriter::header($ns, $imports);
        $code .= "\n";
        $code .= CodeWriter::docblock($this->classDoc($plan));
        $code .= "final readonly class {$plan->rowClass}\n{\n";
        $code .= $this->constructor($plan);
        $code .= "\n";
        $code .= $this->fromRow($plan);
        $code .= "\n";
        $code .= $this->toArray($plan);
        $code .= "}\n";

        return new GeneratedFile("Rows/{$plan->rowClass}.php", $code);
    }

    /**
     * @return list<string>
     */
    private function classDoc(TablePlan $plan): array
    {
        $lines = ["Uma linha de `{$plan->table->name}`."];

        if ($plan->table->comment !== null) {
            $lines[] = '';
            $lines[] = $plan->table->comment;
        }

        return $lines;
    }

    private function constructor(TablePlan $plan): string
    {
        $doc = [];

        foreach ($plan->columns as $column) {
            $refined = $column->type->docblock() !== $column->type->native();
            $comment = $column->column->comment;

            if ($refined || $comment !== null) {
                $doc[] = '@param ' . $column->type->docblock() . ' $' . $column->name()
                    . ($comment === null ? '' : ' ' . $this->oneLine($comment));
            }
        }

        $code = CodeWriter::docblock($doc, '    ');
        $code .= "    public function __construct(\n";

        foreach ($plan->columns as $column) {
            $code .= '        public ' . $column->type->native() . ' $' . $column->name() . ",\n";
        }

        return $code . "    ) {\n    }\n";
    }

    private function fromRow(TablePlan $plan): string
    {
        $code = CodeWriter::docblock([
            'Hidrata a partir de uma linha crua do PDO.',
            '',
            'Chave ausente vira null e o caster recusa quando a coluna é NOT NULL: uma linha',
            'meio montada em silêncio é pior que a exceção que diz qual SELECT não trouxe o quê.',
            '',
            '@param array<string,mixed> $row',
        ], '    ');

        $code .= "    public static function fromRow(array \$row): self\n    {\n";
        $code .= "        return new self(\n";

        foreach ($plan->columns as $column) {
            $code .= '            ' . $column->name() . ': ' . $this->castExpression($plan, $column) . ",\n";
        }

        return $code . "        );\n    }\n";
    }

    private function castExpression(TablePlan $plan, ColumnPlan $column): string
    {
        $context = $plan->table->name . '.' . $column->name();
        $raw = "\$row['{$column->name()}'] ?? null";
        $cast = "Cast::{$column->type->castMethod()}({$raw}, '{$context}')";

        if (!$column->isEnum()) {
            return $cast;
        }

        $short = $this->shortName($column->enumClass ?? '');

        // O enum converte depois do caster: `from()` exige string, e é o caster que
        // garante que veio string — inclusive quando o driver devolveu int.
        return $column->column->notNull
            ? "{$short}::from({$cast})"
            : "\$row['{$column->name()}'] === null ? null : {$short}::from({$cast})";
    }

    private function toArray(TablePlan $plan): string
    {
        $code = CodeWriter::docblock(['@return array<string,mixed>'], '    ');
        $code .= "    public function toArray(): array\n    {\n        return [\n";

        foreach ($plan->columns as $column) {
            $code .= "            '{$column->name()}' => \$this->{$column->name()},\n";
        }

        return $code . "        ];\n    }\n";
    }

    private function shortName(string $fqcn): string
    {
        $position = strrpos($fqcn, '\\');

        return $position === false ? $fqcn : substr($fqcn, $position + 1);
    }

    private function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
