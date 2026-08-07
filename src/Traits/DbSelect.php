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
    public function asArray(): static
    {
        $this->asArray = true;
        return $this;
    }

    /**
     * Seleciona todos os registros da tabela.
     */
    public function selectAll(): array
    {
        $sql = "SELECT * FROM " . $this->table;
        $sql .= implode('', $this->joins);
        $sql .= $this->buildWhereClause();
        $sql .= implode('', $this->group);
        $sql .= $this->buildHavingClause();
        $sql .= $this->buildOrderClause();
        $sql .= implode('', $this->limit);

        return $this->selectInstruction($sql);
    }

    /**
     * Seleciona registros com base em colunas específicas.
     *
     * Cada coluna pode ser um identificador ou um par [coluna, alias]; ambos são validados.
     */
    public function selectColumns(...$columns): array
    {
        $validatedColumns = array_map(function ($col) {
            if (is_array($col)) {
                if (count($col) !== 2) {
                    throw new Exception("Alias de coluna precisa ser um array [coluna, alias].");
                }

                [$column, $alias] = array_values($col);

                return $this->validateIdentifier($column) . " as " . $this->validateIdentifier($alias);
            }

            return $this->validateIdentifier($col);
        }, $columns);

        $sql = "SELECT " . implode(",", $validatedColumns) . " FROM " . $this->table;
        $sql .= implode('', $this->joins);
        $sql .= $this->buildWhereClause();
        $sql .= implode('', $this->group);
        $sql .= $this->buildHavingClause();
        $sql .= $this->buildOrderClause();
        $sql .= implode('', $this->limit);

        return $this->selectInstruction($sql);
    }

    /**
     * Conta os registros de acordo com os filtros/joins definidos.
     */
    public function count(bool $clean = false): int
    {
        try {
            // Se houver GROUP BY, precisamos contar os grupos únicos
            if (!empty($this->group)) {
                $sql = 'SELECT COUNT(*) FROM (SELECT 1 FROM ' . $this->table;
                $sql .= implode('', $this->joins);
                $sql .= $this->buildWhereClause();
                $sql .= implode('', $this->group);
                $sql .= $this->buildHavingClause();
                $sql .= ') as grouped_count';
            } else {
                $sql = 'SELECT count(*) FROM ' . $this->table;
                $sql .= implode('', $this->joins);
                $sql .= $this->buildWhereClause();
                $sql .= $this->buildHavingClause();
            }

            $stmt = $this->pdo->prepare($sql);

            $this->applyBinds($stmt);

            if ($this->debug) {
                $stmt->debugDumpParams();
            }

            $stmt->execute();

            if($clean){
                $this->clean();
            }

            $count = $stmt->fetchAll(PDO::FETCH_COLUMN, 0);

            return isset($count[0]) ? (int) $count[0] : 0;
        } catch (Exception $e) {
            throw new Exception("Tabela: {$this->table} Erro ao executar count: " . $e->getMessage());
        }
    }

    /**
     * Executa de fato a instrução SELECT (auxiliar usada por selectAll e selectColumns).
     */
    public function selectInstruction(string $sql_instruction): array
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
