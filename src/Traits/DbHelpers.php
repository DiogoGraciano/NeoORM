<?php

namespace Diogodg\Neoorm\Traits;

use Exception;
use PDO;
use PDOStatement;

/**
 * Trait com métodos de apoio (internos) da classe Db.
*/
trait DbHelpers{

    /**
     * Limpa/Reseta alguns arrays de propriedades (joins, filters etc.) após cada execução.
     */
    private function clean(): void
    {
        $this->joins             = [];
        $this->having            = [];
        $this->limit             = [];
        $this->group             = [];
        $this->order             = [];
        $this->filters           = [];
        $this->valuesBind        = [];
        $this->hasOrder          = false;
    }

    /**
     * Retorna as colunas da tabela.
     */
    private function getColumnTable(): void
    {
        // Se a classe não foi passada ou não existe, tentamos “deduzir” pelo nome da tabela
        if (!$this->class || !class_exists($this->class)) {
            $this->class = $this->getClassbyTableName($this->table);
        }

        if ($this->class && class_exists($this->class) && method_exists($this->class, "table")) {
            $this->columns = array_keys($this->class::table()->getColumns());
            return;
        }

        throw new Exception("Erro ao recuperar colunas para a tabela: {$this->table}");
    }

    /**
     * Retorna as colunas da tabela.
    */
    protected function getColumns():array
    {
        return $this->columns;
    }

    /**
     * Tenta deduzir a classe model correspondente ao nome da tabela.
     */
    private static function getClassbyTableName(string $tableName): string
    {
        // Exemplo simplificado de “dedução”
        $className = 'App\\Models';

        $tableNameModified = strtolower(str_replace("_", " ", $tableName));

        // Aqui, há várias tentativas
        if (
            class_exists($className . $tableNameModified) &&
            property_exists($className . str_replace(" ", "", $tableNameModified), "table")
        ) {
            return $className . $tableName;
        }
        if (
            class_exists($className . ucfirst($tableNameModified)) &&
            property_exists($className . str_replace(" ", "", ucfirst($tableNameModified)), "table")
        ) {
            return $className . ucfirst($tableName);
        }
        if (
            class_exists($className . ucwords($tableNameModified)) &&
            property_exists($className . str_replace(" ", "", ucwords($tableNameModified)), "table")
        ) {
            return $className . ucwords($tableName);
        }

        // Se nada funcionou, pode varrer arquivos:
        $tableFiles = scandir(dirname(__DIR__) . DIRECTORY_SEPARATOR . "tables");
        foreach ($tableFiles as $tableFile) {
            $tryClassName = $className . "\\" . str_replace(".php", "", $tableFile);
            if (
                class_exists($tryClassName) &&
                property_exists($tryClassName, "table") &&
                $tryClassName::table == $tableName
            ) {
                return $tryClassName;
            }
        }

        return "";
    }

    /**
     * Define os valores de bind (parâmetros) para o PDO.
     */
    private function setBind(mixed $value):string
    {
        if (is_int($value)) {
            $param = PDO::PARAM_INT;
        } elseif (is_bool($value)) {
            $param = PDO::PARAM_BOOL;
        } elseif (is_null($value)) {
            $param = PDO::PARAM_NULL;
        } else {
            $param = PDO::PARAM_STR;
        }

        $md5 = md5(microtime(true)+rand());

        $this->valuesBind[$md5] = [$value, $param];

        return ":".$md5;
    }

    /**
     * Executa a instrução SQL (INSERT, UPDATE, DELETE, SELECT).
     */
    private function executeSql(string $sql_instruction): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql_instruction);

        if ($this->debug) {
            $stmt->debugDumpParams();
        }

        if ($this->valuesBind) {
            foreach ($this->valuesBind as $key => $data) {
                $stmt->bindParam($key, $data[0], $data[1]);
            }
        }

        $stmt->execute();

        if ($this->debug) {
            $stmt->debugDumpParams();
        }

        // Ao final, limpamos para não poluir a próxima query
        $this->clean();

        return $stmt;
    }

    /**
     * Retorna o último ID presente na tabela (considerando a coluna PK).
     */
    private function getlastIdBd(): int
    {
        try {
            $sql = $this->pdo->prepare(
                "SELECT {$this->columns[0]} FROM {$this->table} 
                 ORDER BY {$this->columns[0]} DESC LIMIT 1"
            );
            $sql->execute();

            if ($sql->rowCount() > 0) {
                $rows = $sql->fetchAll(PDO::FETCH_COLUMN, 0);
                return (int) $rows[0];
            }
            return 0;
        } catch (Exception $e) {
            throw new Exception("Tabela: {$this->table} - " . $e->getMessage());
        }
    }

    /**
     * Inicializa o array de objetos com campos nulos,
     * limpa a montagem de query e devolve $this para chain.
     */
    protected function setObjectNull(): static
    {
        $this->object = [];
        foreach ($this->columns as $column) {
            $this->object[$column] = null;
        }

        // “Limpa” tudo para garantir que não haja lixo de queries passadas
        $this->clean();
        return $this;
    }
}
