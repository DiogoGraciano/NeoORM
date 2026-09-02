<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen\Emitter;

use Diogodg\Neoorm\Codegen\CodeWriter;
use Diogodg\Neoorm\Codegen\GeneratedFile;
use Diogodg\Neoorm\Codegen\Plan\ColumnPlan;
use Diogodg\Neoorm\Codegen\Plan\TablePlan;
use Diogodg\Neoorm\Query\Expr\ColumnRef;
use Diogodg\Neoorm\Query\Table;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;

/**
 * A referência tipada de tabela: `Tables::users()->email`.
 *
 * As colunas são **propriedades declaradas**, não `__get`. `__get` funcionaria em runtime
 * e não daria autocomplete nem verificação estática — que é justamente o que este
 * trabalho inteiro existe para entregar.
 */
final class TableEmitter
{
    public function emit(TablePlan $plan, string $namespace): GeneratedFile
    {
        $rowFqcn = $namespace . '\\Rows\\' . $plan->rowClass;

        $imports = [
            $rowFqcn,
            ColumnRef::class,
            Table::class,
            TypeName::class,
            TypeSpec::class,
        ];

        // Os tipos das colunas aparecem no `@var ColumnRef<T>` de cada propriedade. Sem
        // o import, `DateTimeImmutable` seria resolvido no namespace do arquivo gerado —
        // uma classe que não existe — e o analisador leria o tipo como desconhecido.
        foreach ($plan->columns as $column) {
            if ($column->type->import !== null) {
                $imports[] = $column->type->import;
            }

            if ($column->enumClass !== null) {
                $imports[] = $column->enumClass;
            }
        }

        $code = CodeWriter::header($namespace, $imports);

        $code .= "\n";
        $code .= CodeWriter::docblock([
            "Referência tipada a `{$plan->table->name}`.",
            '',
            '@extends Table<' . $plan->rowClass . '>',
        ]);
        $code .= "final class {$plan->tableClass} extends Table\n{\n";
        $code .= "    public const string NAME = '{$plan->table->name}';\n\n";

        foreach ($plan->columns as $column) {
            $doc = $column->type->docblock();
            $code .= "    /** @var ColumnRef<{$doc}> */\n";
            $code .= "    public readonly ColumnRef \${$column->name()};\n\n";
        }

        $code .= "    public function __construct(?string \$alias = null)\n    {\n";
        $code .= "        parent::__construct(\$alias);\n\n";
        $code .= "        \$qualifier = \$alias ?? self::NAME;\n\n";

        foreach ($plan->columns as $column) {
            $code .= "        \$this->{$column->name()} = new ColumnRef(\n";
            $code .= "            \$qualifier,\n";
            $code .= "            '{$column->name()}',\n";
            $code .= '            ' . CodeWriter::typeSpec($column->column->type) . ",\n";
            $code .= '            ' . ($column->column->notNull ? 'true' : 'false') . ",\n";
            $code .= "        );\n";
        }

        $code .= "    }\n\n";
        $code .= "    public function tableName(): string\n    {\n        return self::NAME;\n    }\n\n";
        $code .= CodeWriter::docblock(['@return class-string<' . $plan->rowClass . '>'], '    ');
        $code .= "    public function rowClass(): string\n    {\n";
        $code .= "        return {$plan->rowClass}::class;\n    }\n\n";
        $code .= CodeWriter::docblock(['@param array<string,mixed> $row'], '    ');
        $code .= "    public function hydrate(array \$row): {$plan->rowClass}\n    {\n";
        $code .= "        return {$plan->rowClass}::fromRow(\$row);\n    }\n\n";
        $code .= CodeWriter::docblock(['@return array<string,ColumnRef<mixed>>'], '    ');
        $code .= "    public function columnRefs(): array\n    {\n        return [\n";

        foreach ($plan->columns as $column) {
            $code .= "            '{$column->name()}' => \$this->{$column->name()},\n";
        }

        $code .= "        ];\n    }\n\n";
        $code .= $this->keys($plan);
        $code .= "    public function as(string \$alias): static\n    {\n";
        $code .= "        return new self(\$alias);\n    }\n";
        $code .= "}\n";

        return new GeneratedFile("{$plan->tableClass}.php", $code);
    }

    /**
     * A chave primária e a coluna auto incremento, como as declara o model.
     *
     * O INSERT precisa da segunda para reler a linha gravada onde não há `RETURNING`.
     * Antes ele usava a primeira coluna declarada, o que funciona enquanto todo model
     * começa com `Col::id()` — e devolve a linha errada, sem erro, quando não começa.
     *
     * Os dois métodos são emitidos sempre, inclusive vazios: assim o arquivo gerado diz
     * "esta tabela não tem chave primária" em vez de deixar a resposta para o default da
     * classe base, que significa a mesma coisa mas também significa "gerado por uma
     * versão antiga".
     */
    private function keys(TablePlan $plan): string
    {
        $primary = $plan->table->getPrimaryKeyColumns();
        $auto = $plan->table->autoIncrementColumn()?->name;

        $code = CodeWriter::docblock(['@return list<string>'], '    ');
        $code .= "    public function primaryKeyColumns(): array\n    {\n";
        $code .= $primary === []
            ? "        return [];\n"
            : "        return ['" . implode("', '", $primary) . "'];\n";
        $code .= "    }\n\n";

        $returnType = $auto === null ? '?string' : 'string';
        $code .= "    public function autoIncrementColumn(): {$returnType}\n    {\n";
        $code .= '        return ' . ($auto === null ? 'null' : "'{$auto}'") . ";\n";
        $code .= "    }\n\n";

        $required = array_values(array_map(
            static fn (ColumnPlan $column): string => $column->name(),
            array_filter(
                $plan->columns,
                static fn (ColumnPlan $column): bool => !$column->isOptionalOnInsert(),
            ),
        ));

        $code .= CodeWriter::docblock(['@return list<string>'], '    ');
        $code .= "    public function requiredInsertColumns(): array\n    {\n";
        $code .= $required === []
            ? "        return [];\n"
            : "        return ['" . implode("', '", $required) . "'];\n";
        $code .= "    }\n\n";

        return $code;
    }
}
