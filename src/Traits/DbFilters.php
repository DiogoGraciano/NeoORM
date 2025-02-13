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
                $this->setBind($data);
                $inValue .= "?,";
            }
            $inValue = rtrim($inValue, ",") . ")";

            $filter = " " . $operatorCondition->name . " " . $start . $field .
                      " " . $logicalOperator . " " . $inValue . $end;
            $this->filters[] = $filter;
        } else {
            $this->setBind($value);
            $filter = " " . $operatorCondition->name . " " . $start . $field .
                      " " . $logicalOperator . " ? " . $end;
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
            $this->propertys[] = "," . $column . " " . $order;
        } else {
            $this->propertys[] = " ORDER BY " . $column . " " . $order;
        }

        $this->hasOrder = true;
        return $this;
    }

    /**
     * Adiciona cláusula LIMIT.
     */
    protected function addLimit(int $limitIni, int $limitFim = 0): static
    {
        $this->setBind($limitIni, true);

        if ($limitFim) {
            $this->propertys[] = " LIMIT ?,?";
            $this->setBind($limitFim, true);
        } else {
            $this->propertys[] = " LIMIT ?";
        }

        return $this;
    }

    /**
     * Adiciona um OFFSET.
     */
    protected function addOffset(int $offset): static
    {
        $this->propertys[] = " OFFSET ?";
        $this->setBind($offset, true);

        return $this;
    }

    /**
     * Adiciona um GROUP BY.
     */
    protected function addGroup(...$columns): static
    {
        $this->propertys[] = " GROUP BY " . implode(",", $columns);
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
