<?php

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;

/**
 * Classe responsável por ler informações do schema usando as tabelas de rastreamento
 * em vez de consultas diretas ao information_schema ou tabelas específicas do banco
 */
class SchemaReader
{
    private \PDO $pdo;
    private string $driver;

    public function __construct()
    {
        $this->pdo = Connection::getConnection();
        $this->driver = Config::getDriver();
    }

    /**
     * Verifica se uma tabela existe fisicamente no banco de dados
     */
    public function tableExists(string $tableName): bool
    {
        if ($this->driver === 'mysql') {
            $sql = "SELECT TABLE_NAME FROM information_schema.tables WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([Config::getDbName(), $tableName]);
        } else {
            $sql = "SELECT table_name FROM information_schema.tables WHERE table_catalog = ? AND table_name = ? LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([Config::getDbName(), $tableName]);
        }

        return $stmt->rowCount() > 0;
    }

    /**
     * Obtém informações das colunas de uma tabela usando as tabelas de rastreamento
     */
    public function getTableColumns(string $tableName): array
    {
        $sql = "SELECT * FROM " . $this->getTableName('_schema_columns') . " WHERE table_name = ? ORDER BY position";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Obtém informações da tabela usando as tabelas de rastreamento
     */
    public function getTableInfo(string $tableName): ?array
    {
        $sql = "SELECT * FROM " . $this->getTableName('_schema_tables') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Obtém informações dos índices usando as tabelas de rastreamento
     */
    public function getTableIndexes(string $tableName): array
    {
        $sql = "SELECT * FROM " . $this->getTableName('_schema_indexes') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        $indexes = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $indexes[$row['index_name']] = [
                'columns' => json_decode($row['columns'], true),
                'unique' => $row['is_unique']
            ];
        }
        
        return $indexes;
    }

    /**
     * Obtém informações das constraints usando as tabelas de rastreamento
     */
    public function getTableConstraints(string $tableName): array
    {
        $sql = "SELECT * FROM " . $this->getTableName('_schema_constraints') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        $constraints = [];
        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $constraints[$row['constraint_name']] = [
                'type' => $row['constraint_type'],
                'columns' => json_decode($row['columns'], true)
            ];
        }
        
        return $constraints;
    }

    /**
     * Obtém informações das foreign keys usando as tabelas de rastreamento
     */
    public function getTableForeignKeys(string $tableName): array
    {
        $sql = "SELECT * FROM " . $this->getTableName('_schema_foreign_keys') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Obtém nomes de constraints específicas (para compatibilidade com código existente)
     */
    public function getConstraintNames(string $tableName, array $types = ['UNIQUE', 'CHECK']): array
    {
        $placeholders = str_repeat('?,', count($types) - 1) . '?';
        $sql = "SELECT constraint_name FROM " . $this->getTableName('_schema_constraints') . " 
                WHERE table_name = ? AND constraint_type IN ({$placeholders})";
        
        $params = array_merge([$tableName], $types);
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Obtém nomes de índices de uma tabela
     */
    public function getIndexNames(string $tableName): array
    {
        $sql = "SELECT index_name FROM " . $this->getTableName('_schema_indexes') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    /**
     * Obtém colunas de um índice específico
     */
    public function getIndexColumns(string $tableName, string $indexName): array
    {
        $sql = "SELECT columns FROM " . $this->getTableName('_schema_indexes') . " 
                WHERE table_name = ? AND index_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName, $indexName]);
        
        $result = $stmt->fetchColumn();
        return $result ? json_decode($result, true) : [];
    }

    /**
     * Obtém nome de foreign key para uma coluna específica
     */
    public function getForeignKeyName(string $tableName, string $columnName): ?string
    {
        $sql = "SELECT constraint_name FROM " . $this->getTableName('_schema_foreign_keys') . " 
                WHERE table_name = ? AND column_name = ? LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName, $columnName]);
        
        return $stmt->fetchColumn() ?: null;
    }

    /**
     * Converte dados das tabelas de rastreamento para formato compatível com código existente
     */
    public function getColumnsTableCompatible(string $tableName): array
    {
        $columns = $this->getTableColumns($tableName);
        $compatible = [];

        foreach ($columns as $column) {
            if ($this->driver === 'mysql') {
                $compatible[] = [
                    'TABLE_SCHEMA' => Config::getDbName(),
                    'TABLE_NAME' => $tableName,
                    'COLUMN_NAME' => $column['column_name'],
                    'COLUMN_TYPE' => $column['data_type'] . ($column['column_size'] ? "({$column['column_size']})" : ''),
                    'COLUMN_KEY' => $this->getColumnKey($column),
                    'IS_NULLABLE' => $column['is_nullable'] ? 'YES' : 'NO',
                    'COLUMN_DEFAULT' => $column['column_default'],
                    'COLUMN_COMMENT' => $column['comment']
                ];
            } else {
                $compatible[] = [
                    'column_name' => $column['column_name'],
                    'data_type' => $column['data_type'],
                    'character_maximum_length' => $column['column_size'],
                    'numeric_format' => $column['column_size'],
                    'is_nullable' => $column['is_nullable'] ? 'YES' : 'NO',
                    'column_default' => $column['column_default'],
                    'constraint_name' => null,
                    'constraint_type' => null
                ];
            }
        }

        return $compatible;
    }

    /**
     * Obtém informações da tabela em formato compatível
     */
    public function getTableInformationCompatible(string $tableName): array
    {
        $tableInfo = $this->getTableInfo($tableName);
        
        if (!$tableInfo) {
            return [];
        }

        if ($this->driver === 'mysql') {
            return [(object)[
                'ENGINE' => $tableInfo['engine'],
                'TABLE_COLLATION' => $tableInfo['collation'],
                'AUTO_INCREMENT' => $tableInfo['auto_increment'],
                'TABLE_COMMENT' => $tableInfo['comment']
            ]];
        }

        return [$tableInfo];
    }

    /**
     * Determina a chave da coluna (PRI, UNI, MUL) para compatibilidade MySQL
     */
    private function getColumnKey(array $column): string
    {
        if ($column['is_primary']) {
            return 'PRI';
        }
        
        if ($column['is_unique']) {
            return 'UNI';
        }

        // Verifica se é foreign key
        $fkName = $this->getForeignKeyName($column['table_name'], $column['column_name']);
        if ($fkName) {
            return 'MUL';
        }

        return '';
    }

    /**
     * Obtém o nome da tabela com escape adequado para o driver
     */
    private function getTableName(string $tableName): string
    {
        return $this->driver === 'mysql' ? "`{$tableName}`" : $tableName;
    }

    /**
     * Verifica se as tabelas de rastreamento existem
     */
    public function trackingTablesExist(): bool
    {
        try {
            $sql = "SELECT COUNT(*) FROM " . $this->getTableName('_schema_tables') . " LIMIT 1";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            return true;
        } catch (\Exception $e) {
            return false;
        }
    }
} 