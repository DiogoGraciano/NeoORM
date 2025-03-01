<?php

namespace Diogodg\Neoorm\Traits;

use Exception;
use PDO;
/**
 * Trait que agrupa métodos de SELECT/consulta.
 */
trait DbSelect
{
    /**
     * Retorna um array com os dados internos (coluna => valor).
     */
    public function getArrayData(): array
    {
        return $this->object;
    }

    /**
     * Marca que o resultado deve ser retornado em array associativo.
     */
    protected function asArray(): static
    {
        $this->asArray = true;
        return $this;
    }

    /**
     * Seleciona todos os registros da tabela.
     */
    protected function selectAll(): array
    {
        $sql = "SELECT * FROM " . $this->table;
        $sql .= implode('', $this->joins);

        if ($this->filters) {
            $sql .= " WHERE " . implode(' ', array_map(function($filter, $i) {
                return $i === 0 ? substr($filter, 4) : $filter;
            }, $this->filters, array_keys($this->filters)));
        }
        $sql .= implode('', $this->join);
        $sql .= implode('', $this->group);
        $sql .= implode('', $this->having);
        $sql .= implode('', $this->order);
        $sql .= implode('', $this->limit);

        return $this->selectInstruction($sql);
    }

    /**
     * Seleciona registros com base em colunas específicas.
     */
    protected function selectColumns(...$columns): array
    {
        $sql = "SELECT " . implode(",", $columns) . " FROM " . $this->table;
        $sql .= implode('', $this->joins);

        if ($this->filters) {
            $sql .= " WHERE " . implode(' ', array_map(function($filter, $i) {
                return $i === 0 ? substr($filter, 4) : $filter;
            }, $this->filters, array_keys($this->filters)));
        }
        $sql .= implode('', $this->join);
        $sql .= implode('', $this->group);
        $sql .= implode('', $this->having);
        $sql .= implode('', $this->order);
        $sql .= implode('', $this->limit);

        return $this->selectInstruction($sql);
    }

    /**
     * Conta os registros de acordo com os filtros/joins definidos.
     */
    protected function count(): int
    {
        try {
            $sql = 'SELECT count(*) FROM ' . $this->table;
            $sql .= implode('', $this->joins);

            if ($this->filters) {
                $sql .= " WHERE " . implode(' ', array_map(function($filter, $i) {
                    return $i === 0 ? substr($filter, 4) : $filter;
                }, $this->filters, array_keys($this->filters)));
            }
            $sql .= implode('', $this->group);
            $sql .= implode('', $this->having);

            $stmt = $this->executeSql($sql);

            $count = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

            return isset($count[0]) ? (int) $count[0] : 0;
        } catch (Exception $e) {
            throw new Exception("Tabela: {$this->table} Erro ao executar count: " . $e->getMessage());
        }
    }

    /**
     * Executa de fato a instrução SELECT (auxiliar usada por selectAll e selectColumns).
     */
    protected function selectInstruction(string $sql_instruction): array
    {
        try {
            $sql = $this->executeSql($sql_instruction);

            $rows = [];
            if ($sql->rowCount() > 0) {
                if ($this->asArray === false) {
                    $rows = $sql->fetchAll(
                        PDO::FETCH_CLASS | PDO::FETCH_PROPS_LATE,
                        get_class($this),
                        [$this->table]
                    );
                } else {
                    $rows = $sql->fetchAll(PDO::FETCH_ASSOC);
                }
            }

            return $rows;
        } catch (Exception $e) {
            throw new Exception("Tabela: {$this->table} - " . $e->getMessage());
        }
    }
}
