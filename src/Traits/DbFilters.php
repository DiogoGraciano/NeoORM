<?php
namespace Diogodg\Neoorm\Traits;

use Diogodg\Neoorm\Definitions\Raw;
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
     */
    public function addFilter(
        Raw|string $field,
        string $logicalOperator,
        mixed $value,
        OperatorCondition $operatorCondition = OperatorCondition::AND,
        bool $startGroupFilter = false,
        bool $endGroupFilter = false
    ): static {
        // Valida o nome do campo
        $field = $this->validateIdentifier($field);

        $start  = $startGroupFilter ? "(" : "";
        $end    = $endGroupFilter   ? ")" : "";

        // Caso seja IN, o $value deve ser array
        if (stripos($logicalOperator, "in") !== false) {
            if (!is_array($value)) {
                throw new Exception("Para operadores IN, o valor precisa ser um array.");
            }
            $inValue = "(";
            foreach ($value as $data) {
                $inValue .= "{$this->setBind($data)},";
            }
            $inValue = rtrim($inValue, ",") . ")";

            $filter = " " . $operatorCondition->name . " " . $start . $field .
                      " " . $logicalOperator . " " . $inValue . $end;
            $this->filters[] = $filter;
        } elseif (stripos($logicalOperator, "is") !== false) {
            $filter = " " . $operatorCondition->name . " " . $start . $field .
                      " " . $logicalOperator . " {$value} " . $end;
            $this->filters[] = $filter;
        } else {
            $filter = " " . $operatorCondition->name . " " . $start . $field .
                      " " . $logicalOperator . " {$this->setBind($value)} " . $end;
            $this->filters[] = $filter;
        }

        return $this;
    }

    /**
     * Adiciona uma ordenação (ORDER BY).
     */
    public function addOrder(Raw|string $column, OrderCondition $order = OrderCondition::DESC): static
    {
        // Valida o nome da coluna
        $column = $this->validateIdentifier($column);

        if ($this->hasOrder) {
            $this->order[] .= "," . $column . " " . $order->name;
        } else {
            $this->order[] = " ORDER BY " . $column . " " . $order->name;
        }

        $this->hasOrder = true;

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
        // Valida cada coluna
        $validatedColumns = array_map(function($col) {
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
        string $logicalOperator,
        mixed $value,
        OperatorCondition $operatorCondition = OperatorCondition::AND,
        bool $startGroupFilter = false,
        bool $endGroupFilter = false
    ): static {
        // Valida o nome do campo
        $field = $this->validateIdentifier($field);

        $start  = $startGroupFilter ? "(" : "";
        $end    = $endGroupFilter   ? ")" : "";

        // Caso seja IN, o $value deve ser array
        if (stripos($logicalOperator, "in") !== false) {
            if (!is_array($value)) {
                throw new Exception("Para operadores IN, o valor precisa ser um array.");
            }
            $inValue = "(";
            foreach ($value as $data) {
                $inValue .= "{$this->setBind($data)},";
            }
            $inValue = rtrim($inValue, ",") . ")";

            if ($this->hasHaving) {
                $filter = " " . $operatorCondition->name . " " . $start . $field .
                          " " . $logicalOperator . " " . $inValue . $end;
            } else {
                $filter = " HAVING " . $start . $field .
                          " " . $logicalOperator . " " . $inValue . $end;
            }
            $this->having[] = $filter;
        } else {
            if ($this->hasHaving) {
                $filter = " " . $operatorCondition->name . " " . $start . $field .
                          " " . $logicalOperator . " {$this->setBind($value)} " . $end;
            } else {
                $filter = " HAVING " . $start . $field .
                          " " . $logicalOperator . " {$this->setBind($value)} " . $end;
            }
            $this->having[] = $filter;
        }

        $this->hasHaving = true;
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
        string $logicalOperator = '='
    ): static {
        // Valida os identificadores
        $table         = $this->validateIdentifier($table);
        $columnTable   = $this->validateIdentifier($columnTable);
        $columnRelation = $this->validateIdentifier($columnRelation);

        $typeJoin = strtoupper(trim($typeJoin));
        $valid = ["LEFT", "RIGHT", "INNER", "OUTER", "FULL OUTER", "LEFT OUTER", "RIGHT OUTER"];

        if (!in_array($typeJoin, $valid)) {
            throw new Exception("Tabela: {$this->table} - Tipo de join inválido: {$typeJoin}");
        }

        $join = " " . $typeJoin . " JOIN " . $table . " ON " .
                $columnTable . $logicalOperator . $columnRelation . " ";
        $this->joins[] = $join;

        return $this;
    }
}
