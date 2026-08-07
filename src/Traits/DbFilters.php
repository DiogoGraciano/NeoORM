<?php
namespace Diogodg\Neoorm\Traits;

use Diogodg\Neoorm\Definitions\Raw;
use Diogodg\Neoorm\Enums\LogicalOperator;
use Diogodg\Neoorm\Enums\OperatorCondition;
use Diogodg\Neoorm\Enums\OrderCondition;
use Exception;

/**
 * Trait para manipulação de filtros, ordenações, agrupamentos e joins.
 */
trait DbFilters
{
    /**
     * Ativa o modo debug (exibe binds e parâmetros).
     */
    public function setDebug(): static
    {
        $this->debug = true;
        return $this;
    }

    /**
     * Adiciona um filtro WHERE.
     *
     * O operador é validado contra a allowlist de {@see LogicalOperator}; o valor
     * sempre vira bind. Para comparações com NULL use addFilterNull()/addFilterNotNull().
     */
    public function addFilter(
        Raw|string $field,
        string|LogicalOperator $logicalOperator,
        mixed $value,
        OperatorCondition $operatorCondition = OperatorCondition::AND,
        bool $startGroupFilter = false,
        bool $endGroupFilter = false
    ): static {
        $this->filters[] = [
            'condition' => $operatorCondition->name,
            'sql' => $this->buildComparison($field, $logicalOperator, $value, $startGroupFilter, $endGroupFilter),
        ];

        return $this;
    }

    /**
     * Adiciona um filtro "campo IS NULL".
     */
    public function addFilterNull(
        Raw|string $field,
        OperatorCondition $operatorCondition = OperatorCondition::AND,
        bool $startGroupFilter = false,
        bool $endGroupFilter = false
    ): static {
        return $this->addNullFilter($field, 'IS NULL', $operatorCondition, $startGroupFilter, $endGroupFilter);
    }

    /**
     * Adiciona um filtro "campo IS NOT NULL".
     */
    public function addFilterNotNull(
        Raw|string $field,
        OperatorCondition $operatorCondition = OperatorCondition::AND,
        bool $startGroupFilter = false,
        bool $endGroupFilter = false
    ): static {
        return $this->addNullFilter($field, 'IS NOT NULL', $operatorCondition, $startGroupFilter, $endGroupFilter);
    }

    /**
     * Adiciona uma ordenação (ORDER BY).
     */
    public function addOrder(Raw|string $column, OrderCondition $order = OrderCondition::DESC): static
    {
        $this->order[] = $this->validateIdentifier($column) . " " . $order->name;

        return $this;
    }

    /**
     * Adiciona cláusula LIMIT.
     */
    public function addLimit(int $limitIni, int $limitFim = 0): static
    {
        if ($limitFim) {
            $this->limit[] = " LIMIT {$this->setBind($limitIni)},{$this->setBind($limitFim)}";
        } else {
            $this->limit[] = " LIMIT {$this->setBind($limitIni)}";
        }

        return $this;
    }

    /**
     * Adiciona um OFFSET.
     */
    public function addOffset(int $offset): static
    {
        $this->limit[] = " OFFSET {$this->setBind($offset)}";
        return $this;
    }

    /**
     * Adiciona um GROUP BY.
     */
    public function addGroup(...$columns): static
    {
        $validatedColumns = array_map(function ($col) {
            return $this->validateIdentifier($col);
        }, $columns);

        $this->group[] = " GROUP BY " . implode(",", $validatedColumns);
        return $this;
    }

    /**
     * Adiciona um filtro HAVING.
     */
    public function addHaving(
        Raw|string $field,
        string|LogicalOperator $logicalOperator,
        mixed $value,
        OperatorCondition $operatorCondition = OperatorCondition::AND,
        bool $startGroupFilter = false,
        bool $endGroupFilter = false
    ): static {
        $this->having[] = [
            'condition' => $operatorCondition->name,
            'sql' => $this->buildComparison($field, $logicalOperator, $value, $startGroupFilter, $endGroupFilter),
        ];

        return $this;
    }

    /**
     * Adiciona um JOIN (INNER, LEFT, RIGHT etc).
     */
    public function addJoin(
        Raw|string $table,
        Raw|string $columnTable,
        Raw|string $columnRelation,
        string $typeJoin = "INNER",
        string|LogicalOperator $logicalOperator = LogicalOperator::EQUAL
    ): static {
        $table          = $this->validateIdentifier($table);
        $columnTable    = $this->validateIdentifier($columnTable);
        $columnRelation = $this->validateIdentifier($columnRelation);
        $operator       = LogicalOperator::fromMixed($logicalOperator);

        if ($operator->requiresList() || $operator->requiresRange()) {
            throw new Exception("Tabela: {$this->table} - Operador não suportado em join: {$operator->value}");
        }

        $typeJoin = strtoupper(preg_replace('/\s+/', ' ', trim($typeJoin)));
        $valid = ["LEFT", "RIGHT", "INNER", "OUTER", "FULL OUTER", "LEFT OUTER", "RIGHT OUTER"];

        if (!in_array($typeJoin, $valid, true)) {
            throw new Exception("Tabela: {$this->table} - Tipo de join inválido: {$typeJoin}");
        }

        $this->joins[] = " " . $typeJoin . " JOIN " . $table . " ON " .
                         $columnTable . " " . $operator->value . " " . $columnRelation . " ";

        return $this;
    }

    /**
     * Monta a expressão de comparação de um filtro, sempre parametrizando o valor.
     */
    private function buildComparison(
        Raw|string $field,
        string|LogicalOperator $logicalOperator,
        mixed $value,
        bool $startGroupFilter,
        bool $endGroupFilter
    ): string {
        $field    = $this->validateIdentifier($field);
        $operator = LogicalOperator::fromMixed($logicalOperator);

        $start = $startGroupFilter ? "(" : "";
        $end   = $endGroupFilter ? ")" : "";

        if ($operator->requiresList()) {
            if (!is_array($value)) {
                throw new Exception("Para o operador {$operator->value} o valor precisa ser um array.");
            }

            if (!$value) {
                throw new Exception("Para o operador {$operator->value} o array de valores não pode ser vazio.");
            }

            $binds = array_map(fn($data) => $this->setBind($data), $value);

            return $start . $field . " " . $operator->value . " (" . implode(",", $binds) . ")" . $end;
        }

        if ($operator->requiresRange()) {
            if (!is_array($value) || count($value) !== 2) {
                throw new Exception("Para o operador {$operator->value} o valor precisa ser um array com dois elementos.");
            }

            $value = array_values($value);

            return $start . $field . " " . $operator->value . " " .
                   $this->setBind($value[0]) . " AND " . $this->setBind($value[1]) . $end;
        }

        if (is_array($value)) {
            throw new Exception("Para o operador {$operator->value} o valor não pode ser um array.");
        }

        return $start . $field . " " . $operator->value . " " . $this->setBind($value) . $end;
    }

    /**
     * Monta um filtro de nulidade. O predicado vem de constante interna, nunca do chamador.
     */
    private function addNullFilter(
        Raw|string $field,
        string $predicate,
        OperatorCondition $operatorCondition,
        bool $startGroupFilter,
        bool $endGroupFilter
    ): static {
        $field = $this->validateIdentifier($field);

        $start = $startGroupFilter ? "(" : "";
        $end   = $endGroupFilter ? ")" : "";

        $this->filters[] = [
            'condition' => $operatorCondition->name,
            'sql' => $start . $field . " " . $predicate . $end,
        ];

        return $this;
    }
}
