<?php

namespace Diogodg\Neoorm\Traits;

use Diogodg\Neoorm\Config;
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
            // Mapeia as colunas e valida cada uma delas
            $columnsDb = [];
            $safeColumns = [];
            foreach ($this->columns as $col) {
                $safeCol = $this->validateIdentifier($col);
                $columnsDb[$safeCol] = true;
                $safeColumns[] = $safeCol;
            }

            if ($this->object && !isset($this->object[0])) {
                // Filtra apenas as chaves que estão definidas na tabela
                $objectFilter = array_intersect_key($this->object, $columnsDb);

                // Verifica se o campo de PK (primeira coluna) está setado
                $primaryKey = $this->validateIdentifier($this->columns[0]);
                if (
                    !isset($objectFilter[$primaryKey]) ||
                    empty($objectFilter[$primaryKey])
                ) {
                    // Se a tabela for auto-increment, removemos a PK do INSERT
                    if ($this->class::table()->getAutoIncrement()) {
                        unset($objectFilter[$primaryKey]);
                    } else {
                        $nextId = $this->getlastIdBd() + 1;
                        $this->object[$primaryKey] = $objectFilter[$primaryKey] = $nextId;
                    }

                    // Montagem do INSERT
                    $sql_instruction = "INSERT INTO {$this->table} (";
                    $keysBD   = implode(",", array_map([$this, 'validateIdentifier'], array_keys($objectFilter)));
                    $valuesBD = "";

                    foreach ($objectFilter as $data) {
                        $valuesBD .= "{$this->setBind($data)},";
                    }
                    $valuesBD = rtrim($valuesBD, ",");

                    $sql_instruction .= $keysBD . ") VALUES (" . $valuesBD . ");";
                } else {
                    // Montagem do UPDATE
                    $sql_instruction = "UPDATE {$this->table} SET ";
                    foreach ($objectFilter as $key => $data) {
                        // Não atualiza a chave primária
                        if ($this->validateIdentifier($key) === $primaryKey) {
                            continue;
                        }
                        $sql_instruction .= "{$this->validateIdentifier($key)} = {$this->setBind($data)},";
                    }
                    $sql_instruction = rtrim($sql_instruction, ",") . " WHERE ";

                    // Se existirem filtros, usa-os no WHERE
                    if ($this->filters) {
                        $sql_instruction .= implode(' ', array_map(function ($filter, $i) {
                            return $i === 0 ? substr($filter, 4) : $filter;
                        }, $this->filters, array_keys($this->filters)));
                    } else {
                        $sql_instruction .= "{$primaryKey} = {$this->setBind($objectFilter[$primaryKey])}";
                    }
                }

                if(Config::getDriver() != 'mysql'){
                    $sql_instruction .= " RETURNING {$primaryKey}";
                }

                $stmt = $this->executeSql($sql_instruction);

                if ($this->class::table()->getAutoIncrement()) {
                    if(Config::getDriver() === 'mysql'){
                        $this->object[$primaryKey] = $this->pdo->lastInsertId();
                    }
                    else{
                        $this->object[$primaryKey] = $stmt->fetchColumn();
                    }
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
            // Valida a tabela e as colunas
            $columnsDb = [];
            foreach ($this->columns as $col) {
                $columnsDb[$this->validateIdentifier($col)] = true;
            }

            if ($this->object) {
                $objectFilter = array_intersect_key($this->object, $columnsDb);

                $sql_instruction = "INSERT INTO {$this->table} (";
                $keysBD   = implode(",", array_map([$this, 'validateIdentifier'], array_keys($objectFilter)));
                $valuesBD = "";

                foreach ($objectFilter as $data) {
                    $valuesBD .= "{$this->setBind($data)},";
                }
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
                $sql = "DELETE FROM {$this->table} WHERE {$this->validateIdentifier($this->columns[0])} = {$this->setBind($id)}";
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