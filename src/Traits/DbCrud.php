<?php

namespace Diogodg\Neoorm\Traits;

use Exception;

/**
 * Trait para as operações de (INSERT, UPDATE, DELETE).
 */
trait DbCrud
{
    /**
     * Salva ou Atualiza um registro na tabela.
     */
    protected function store(): bool
    {
        try {
            // Mapear colunas da tabela
            foreach ($this->columns as $col) {
                $columnsDb[$col] = true;
            }

            if ($this->object && !isset($this->object[0])) {
                $objectFilter = array_intersect_key($this->object, $columnsDb);

                // Verifica se o campo de PK (primeira coluna do array $this->columns) está setado
                if (
                    !isset($objectFilter[$this->columns[0]]) ||
                    empty($objectFilter[$this->columns[0]])
                ) {
                    // Se a tabela for auto-increment, removemos a PK do INSERT
                    if ($this->class::table()->getAutoIncrement()) {
                        unset($objectFilter[$this->columns[0]]);
                    } else {
                        $nextId = $this->getlastIdBd() + 1;
                        $this->object[$this->columns[0]] = $objectFilter[$this->columns[0]] = $nextId;
                    }

                    // Montagem do INSERT
                    $sql_instruction = "INSERT INTO {$this->table} (";
                    $keysBD   = implode(",", array_keys($objectFilter));
                    $valuesBD = "";

                    foreach ($objectFilter as $key => $data) {
                        $valuesBD .= "{$this->setBind($data)},";
                    }
                    $keysBD   = rtrim($keysBD, ",");
                    $valuesBD = rtrim($valuesBD, ",");

                    $sql_instruction .= $keysBD . ") VALUES (" . $valuesBD . ");";
                } else {
                    // UPDATE
                    $sql_instruction = "UPDATE {$this->table} SET ";
                    foreach ($objectFilter as $key => $data) {
                        if ($key === $this->columns[0]) {
                            continue; // não atualiza a PK
                        }
                        $sql_instruction .= "{$key} = {$this->setBind($data)},";
                    }
                    $sql_instruction = rtrim($sql_instruction, ",") . " WHERE ";

                    // Se existem filtros, usamos no WHERE
                    if ($this->filters) {
                        $sql_instruction .= implode(' ', array_map(function ($filter, $i) {
                            return $i === 0 ? substr($filter, 4) : $filter;
                        }, $this->filters, array_keys($this->filters)));
                    } else {
                        $sql_instruction .= "{$this->columns[0]} = {$this->setBind($objectFilter[$this->columns[0]])}";
                    }
                }

                $this->executeSql($sql_instruction);

                if ($this->class::table()->getAutoIncrement()) {
                    $this->object[$this->columns[0]] = $this->pdo->lastInsertId();
                }

                return true;
            }
            throw new Exception("Objeto não está setado para a Tabela: {$this->table}");
        } catch (Exception $e) {
            throw new Exception("Tabela: {$this->table} - " . $e->getMessage());
        }
    }

    /**
     * Salva um registro na tabela com múltiplas chaves primárias.
     */
    protected function storeMutiPrimary(): bool
    {
        try {
            foreach ($this->columns as $col) {
                $columnsDb[$col] = true;
            }

            if ($this->object) {
                $objectFilter = array_intersect_key($this->object, $columnsDb);

                $sql_instruction = "INSERT INTO {$this->table} (";
                $keysBD   = implode(",", array_keys($objectFilter));
                $valuesBD = "";

                foreach ($objectFilter as $data) {
                    $valuesBD .= "?,{$this->setBind($data)}";
                }
                $keysBD   = rtrim($keysBD, ",");
                $valuesBD = rtrim($valuesBD, ",");

                $sql_instruction .= $keysBD . ") VALUES (" . $valuesBD . ");";
                $this->executeSql($sql_instruction);

                return true;
            }
        } catch (Exception $e) {
            throw new Exception("Tabela: {$this->table} - " . $e->getMessage());
        }

        throw new Exception("Objeto não está setado para a Tabela: {$this->table}");
    }

    /**
     * Deleta um registro a partir de um ID.
     */
    protected function delete(string|int $id): bool
    {
        try {
            if ($id) {
                $sql = "DELETE FROM {$this->table} WHERE {$this->columns[0]} = {$this->setBind($id)}";
                $this->executeSql($sql);
                return true;
            }
            throw new Exception("ID precisa ser informado para excluir.");
        } catch (Exception $e) {
            throw new Exception("Tabela: {$this->table} - " . $e->getMessage());
        }
    }

    /**
     * Deleta registros com base em filtros configurados.
     */
    protected function deleteByFilter(): bool
    {
        try {
            $sql = "DELETE FROM {$this->table}";

            if ($this->filters) {
                $sql .= " WHERE " . implode(' ', array_map(function ($filter, $i) {
                    return $i === 0 ? substr($filter, 4) : $filter;
                }, $this->filters, array_keys($this->filters)));
            } else {
                throw new Exception("Filtros devem ser informados para deleteByFilter.");
            }

            $this->executeSql($sql);
            return true;
        } catch (Exception $e) {
            throw new Exception("Tabela: {$this->table} - " . $e->getMessage());
        }
    }
}
