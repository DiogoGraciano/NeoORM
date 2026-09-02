<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen\Plan;

use Diogodg\Neoorm\Codegen\CodegenException;
use Diogodg\Neoorm\Codegen\Naming;
use Diogodg\Neoorm\Codegen\PhpType;
use Diogodg\Neoorm\Codegen\TypeMapper;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\Type\TypeName;

/**
 * Uma tabela resolvida para emissão: nomes de classe e o plano de cada coluna.
 */
final readonly class TablePlan
{
    /**
     * @param list<ColumnPlan> $columns em ordem de declaração
     */
    private function __construct(
        public TableDefinition $table,
        public array $columns,
        public string $rowClass,
        public string $insertClass,
        public string $tableClass,
    ) {
    }

    public static function build(TableDefinition $table, TypeMapper $types, string $namespace): self
    {
        self::assertInvariants($table);

        $columns = [];

        foreach ($table->getColumns() as $column) {
            $type = $types->for($column);
            $enumClass = null;
            $enumCases = null;

            if ($column->type->name === TypeName::Enum) {
                $cases = Naming::enumCases($column->type->values ?? []);

                if ($cases !== null) {
                    $enumCases = $cases;
                    $enumClass = $namespace . '\\Enums\\' . Naming::enumClass($table->name, $column->name);
                    $type = $type->asClass($enumClass, 'string');
                }
            }

            $columns[] = new ColumnPlan($column, $type, $enumClass, $enumCases);
        }

        return new self(
            $table,
            $columns,
            Naming::rowClass($table->name),
            Naming::insertClass($table->name),
            Naming::tableClass($table->name),
        );
    }

    /**
     * O gerador se recusa a emitir em vez de emitir algo sutilmente errado.
     *
     * Auto incremento é o ponto sensível: `TableDefinition::autoIncrementColumn()`
     * devolve a PRIMEIRA que encontrar, em silêncio, e uma coluna auto incremento fora
     * da chave primária faria o `Insert` omitir a coluna errada.
     */
    private static function assertInvariants(TableDefinition $table): void
    {
        $auto = [];

        foreach ($table->getColumns() as $column) {
            if ($column->autoIncrement) {
                $auto[] = $column->name;
            }
        }

        if (count($auto) > 1) {
            throw new CodegenException(
                "A tabela '{$table->name}' declara mais de uma coluna auto incremento ("
                . implode(', ', $auto) . '). Nenhum dos dois bancos suporta isso, e o IR '
                . 'devolveria só a primeira.',
            );
        }

        if ($auto !== [] && !in_array($auto[0], $table->getPrimaryKeyColumns(), true)) {
            throw new CodegenException(
                "A coluna '{$auto[0]}' de '{$table->name}' é auto incremento mas não faz parte "
                . 'da chave primária.',
            );
        }
    }

    /**
     * Os imports que o DTO de linha precisa.
     *
     * @return list<string>
     */
    public function rowImports(): array
    {
        $imports = [];

        foreach ($this->columns as $column) {
            if ($column->type->import !== null) {
                $imports[] = $column->type->import;
            }
        }

        return $imports;
    }

    /**
     * As colunas do INSERT, obrigatórias primeiro.
     *
     * O PHP exige que parâmetro sem default venha antes dos que têm. Auto incremento vai
     * por último dentro dos opcionais: é o que quase nunca se informa.
     *
     * @return list<ColumnPlan>
     */
    public function insertColumns(): array
    {
        $required = [];
        $optional = [];
        $auto = [];

        foreach ($this->columns as $column) {
            if ($column->column->autoIncrement) {
                $auto[] = $column;
            } elseif ($column->isOptionalOnInsert()) {
                $optional[] = $column;
            } else {
                $required[] = $column;
            }
        }

        return [...$required, ...$optional, ...$auto];
    }

    public function typeOf(string $column): PhpType
    {
        foreach ($this->columns as $plan) {
            if ($plan->name() === $column) {
                return $plan->type;
            }
        }

        throw new CodegenException("Coluna '{$column}' não existe em '{$this->table->name}'.");
    }
}
