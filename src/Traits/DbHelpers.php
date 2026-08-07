<?php

namespace Diogodg\Neoorm\Traits;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Definitions\Raw;
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
        $this->bindCounter       = 0;
    }


    /**
     * Valida se o identificador é seguro (apenas letras, números, underscore e ponto são permitidos).
     *
     * Um {@see Raw} passa direto, sem validação: ele é a via de escape explícita do ORM
     * e a responsabilidade pelo conteúdo é de quem o constrói. Nunca monte um Raw a
     * partir de entrada do usuário.
     */
    private function validateIdentifier(Raw|string $identifier): string
    {
        if ($identifier instanceof Raw) {
            return $identifier->getSql();
        }

        if (!preg_match('/^[a-zA-Z0-9_.]+$/', $identifier)) {
            throw new Exception("Identificador inválido: " . $identifier);
        }
        return $identifier;
    }

    /**
     * Monta a cláusula WHERE a partir dos filtros acumulados.
     * Retorna string vazia quando não há filtros.
     */
    private function buildWhereClause(): string
    {
        return $this->buildConditionClause($this->filters, " WHERE ");
    }

    /**
     * Monta a cláusula HAVING a partir dos filtros acumulados.
     */
    private function buildHavingClause(): string
    {
        return $this->buildConditionClause($this->having, " HAVING ");
    }

    /**
     * Monta a cláusula ORDER BY.
     */
    private function buildOrderClause(): string
    {
        return $this->order ? " ORDER BY " . implode(",", $this->order) : "";
    }

    /**
     * Junta uma lista de condições, omitindo o conector do primeiro elemento.
     *
     * @param array<int,array{condition:string,sql:string}> $conditions
     */
    private function buildConditionClause(array $conditions, string $prefix): string
    {
        if (!$conditions) {
            return "";
        }

        $sql = "";
        foreach ($conditions as $i => $condition) {
            $sql .= $i === 0 ? $condition['sql'] : " " . $condition['condition'] . " " . $condition['sql'];
        }

        return $prefix . $sql;
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
    public function getColumns():array
    {
        return $this->columns;
    }

    /**
     * Tenta deduzir a classe model correspondente ao nome da tabela.
     */
    private static function getClassbyTableName(string $tableName): string
    {
        $namespace = Config::getModelNamespace() ?: 'App\\Models';
        $namespace = rtrim($namespace, '\\') . '\\';

        $tableNameModified = strtolower(str_replace("_", " ", $tableName));

        // studly_case: "schedule_user" => "ScheduleUser"
        $candidates = [
            $namespace . str_replace(" ", "", ucwords($tableNameModified)),
            $namespace . ucfirst($tableName),
            $namespace . $tableName,
        ];

        foreach ($candidates as $candidate) {
            if (class_exists($candidate) && constant($candidate . '::table') === $tableName) {
                return $candidate;
            }
        }

        return "";
    }

    /**
     * Registra um valor para bind e devolve o placeholder correspondente.
     *
     * Placeholders são sequenciais dentro da query em construção, o que torna
     * colisão impossível e o SQL gerado legível em debug.
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

        $placeholder = "p" . $this->bindCounter++;

        $this->valuesBind[$placeholder] = [$value, $param];

        return ":".$placeholder;
    }

    /**
     * Aplica os binds acumulados a um statement preparado.
     */
    private function applyBinds(PDOStatement $stmt): void
    {
        foreach ($this->valuesBind as $key => [$value, $type]) {
            $stmt->bindValue(":" . $key, $value, $type);
        }
    }

    /**
     * Executa a instrução SQL (INSERT, UPDATE, DELETE, SELECT).
     */
    private function executeSql(string $sql_instruction): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql_instruction);

        $this->applyBinds($stmt);

        if ($this->debug) {
            $stmt->debugDumpParams();
        }

        $stmt->execute();

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
            $primaryKey = $this->validateIdentifier($this->columns[0]);

            $sql = $this->pdo->prepare(
                "SELECT {$primaryKey} FROM {$this->table}
                 ORDER BY {$primaryKey} DESC LIMIT 1"
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
    public function setObjectNull(): static
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
