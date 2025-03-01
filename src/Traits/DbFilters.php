<?php

namespace Diogodg\Neoorm\Traits;

use Diogodg\Neoorm\Enums\OperatorCondition;
use Exception;

/**
 * Trait para manipulação de filtros, ordenações, agrupamentos e joins.
 */
trait DbFilters
{
    /**
     * Ativa o modo debug (exibe binds e parâmetros).
     */
    protected function setDebug(): static
    {
        $this->debug = true;
        return $this;
    }

    /**
     * Adiciona um filtro WHERE.
     */
    protected function addFilter(
        string $field,
        string $logicalOperator,
        mixed $value,
        OperatorCondition $operatorCondition = OperatorCondition::AND,
        bool $startGroupFilter = false,
        bool $endGroupFilter = false
    ): static {
        $start  = $startGroupFilter ? "(" : "";
        $end    = $endGroupFilter   ? ")" : "";

        // Caso seja IN, o $value deve ser array
        if (str_contains(strtolower($logicalOperator), "in")) {
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
        }elseif (str_contains(strtolower($logicalOperator), "is")){
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
    protected function addOrder(string $column, string $order = "DESC"): static
    {
        if ($this->hasOrder) {
            $this->order[] = "," . $column . " " . $order;
        } else {
            $this->order[] = " ORDER BY " . $column . " " . $order;
        }

        $this->hasOrder = true;
        return $this;
    }

    /**
     * Adiciona cláusula LIMIT.
     */
    protected function addLimit(int $limitIni, int $limitFim = 0): static
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
    protected function addOffset(int $offset): static
    {
        $this->limit[] = " OFFSET {$this->setBind($offset)}";
        return $this;
    }

    /**
     * Adiciona um GROUP BY.
     */
    protected function addGroup(...$columns): static
    {
        $this->group[] = " GROUP BY " . implode(",", $columns);
        return $this;
    }

    /**
     * Adiciona um filtro WHERE.
     */
    protected function addHaving(
        string $field,
        string $logicalOperator,
        mixed $value,
        OperatorCondition $operatorCondition = OperatorCondition::AND,
        bool $startGroupFilter = false,
        bool $endGroupFilter = false
    ): static {
        $start  = $startGroupFilter ? "(" : "";
        $end    = $endGroupFilter   ? ")" : "";

        // Caso seja IN, o $value deve ser array
        if (str_contains(strtolower($logicalOperator), "in")) {
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
            $this->having[] = $filter;
        } else {
            $filter = " " . $operatorCondition->name . " " . $start . $field .
                      " " . $logicalOperator . " {$this->setBind($value)} " . $end;
            $this->having[] = $filter;
        }

        return $this;
    }

    /**
     * Adiciona um JOIN (INNER, LEFT, RIGHT etc).
     */
    protected function addJoin(
        string $table,
        string $columnTable,
        string $columnRelation,
        string $typeJoin = "INNER",
        string $logicalOperator = '='
    ): static {
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
