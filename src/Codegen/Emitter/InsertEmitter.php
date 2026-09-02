<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen\Emitter;

use Diogodg\Neoorm\Codegen\CodeWriter;
use Diogodg\Neoorm\Codegen\GeneratedFile;
use Diogodg\Neoorm\Codegen\Plan\ColumnPlan;
use Diogodg\Neoorm\Codegen\Plan\TablePlan;
use Diogodg\Neoorm\Runtime\Unspecified;

/**
 * O payload de INSERT, com argumentos nomeados.
 *
 * É onde a tipagem paga mais: `new UsersInsert(email: '...')` sem o `name` obrigatório é
 * erro que a IDE e o analisador apontam antes de rodar. No Active Record equivalente, a
 * propriedade tipada não inicializada só explodiria dentro do `save()`.
 */
final class InsertEmitter
{
    public function emit(TablePlan $plan, string $namespace): GeneratedFile
    {
        $ns = $namespace . '\\Inserts';
        $columns = $plan->insertColumns();
        $imports = [];

        foreach ($columns as $column) {
            if ($column->type->import !== null) {
                $imports[] = $column->type->import;
            }

            if ($column->enumClass !== null) {
                $imports[] = $column->enumClass;
            }

            if ($column->needsUnspecified()) {
                $imports[] = Unspecified::class;
            }
        }

        $code = CodeWriter::header($ns, $imports);
        $code .= "\n";
        $code .= CodeWriter::docblock([
            "Payload de INSERT para `{$plan->table->name}`.",
            '',
            'Coluna com DEFAULT ou auto incremento é opcional: deixá-la em `null` OMITE a',
            'coluna do INSERT, e o banco aplica o próprio padrão.',
        ]);
        $code .= "final readonly class {$plan->insertClass}\n{\n";
        $code .= $this->constructor($columns);
        $code .= "\n";
        $code .= $this->toColumns($columns);
        $code .= "}\n";

        return new GeneratedFile("Inserts/{$plan->insertClass}.php", $code);
    }

    /**
     * @param list<ColumnPlan> $columns
     */
    private function constructor(array $columns): string
    {
        $doc = [];

        foreach ($columns as $column) {
            $note = $this->note($column);

            if ($note !== null) {
                $doc[] = '@param ' . $this->docType($column) . ' $' . $column->name() . ' ' . $note;
            }
        }

        $code = CodeWriter::docblock($doc, '    ');
        $code .= "    public function __construct(\n";

        foreach ($columns as $column) {
            $code .= '        public ' . $this->nativeType($column) . ' $' . $column->name()
                . $this->defaultValue($column) . ",\n";
        }

        return $code . "    ) {\n    }\n";
    }

    private function nativeType(ColumnPlan $column): string
    {
        if ($column->needsUnspecified()) {
            // O único caso em que o tipo cresce: `T|Unspecified|null`. Verboso, e é a
            // única forma de distinguir "grave NULL" de "deixe o default".
            return $column->type->asNullable(false)->native() . '|Unspecified|null';
        }

        return $column->isOptionalOnInsert()
            ? $column->type->asNullable()->native()
            : $column->type->asNullable(false)->native();
    }

    private function docType(ColumnPlan $column): string
    {
        if ($column->needsUnspecified()) {
            return $column->type->asNullable(false)->docblock() . '|Unspecified|null';
        }

        return $column->isOptionalOnInsert()
            ? $column->type->asNullable()->docblock()
            : $column->type->asNullable(false)->docblock();
    }

    private function defaultValue(ColumnPlan $column): string
    {
        if ($column->needsUnspecified()) {
            return ' = Unspecified::Value';
        }

        return $column->isOptionalOnInsert() ? ' = null' : '';
    }

    private function note(ColumnPlan $column): ?string
    {
        if ($column->column->autoIncrement) {
            return 'null = o banco gera';
        }

        if ($column->needsUnspecified()) {
            return 'Unspecified = usa o DEFAULT; null = grava NULL';
        }

        if ($column->isOptionalOnInsert() && $column->column->notNull) {
            return 'null = usa o DEFAULT da coluna';
        }

        $comment = $column->column->comment;

        return $comment === null ? null : trim((string) preg_replace('/\s+/', ' ', $comment));
    }

    /**
     * As colunas efetivamente informadas.
     *
     * Devolve valores PHP, não valores de bind: converter é do `Binder`, que conhece o
     * dialeto. Este objeto é deliberadamente livre de banco.
     *
     * @param list<ColumnPlan> $columns
     */
    private function toColumns(array $columns): string
    {
        $code = CodeWriter::docblock(['@return array<string,mixed>'], '    ');
        $code .= "    public function toColumns(): array\n    {\n        \$columns = [];\n\n";

        foreach ($columns as $column) {
            $name = $column->name();

            if ($column->needsUnspecified()) {
                $code .= "        if (!\$this->{$name} instanceof Unspecified) {\n";
                $code .= "            \$columns['{$name}'] = \$this->{$name};\n";
                $code .= "        }\n\n";

                continue;
            }

            if ($column->isOptionalOnInsert()) {
                $code .= "        if (\$this->{$name} !== null) {\n";
                $code .= "            \$columns['{$name}'] = \$this->{$name};\n";
                $code .= "        }\n\n";

                continue;
            }

            $code .= "        \$columns['{$name}'] = \$this->{$name};\n\n";
        }

        return $code . "        return \$columns;\n    }\n";
    }
}
