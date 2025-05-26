<?php

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use Exception;

/**
 * Classe responsável por rastrear mudanças no schema do banco de dados
 * usando tabelas auxiliares para detectar automaticamente alterações
 */
class SchemaTracker
{
    private \PDO $pdo;
    private string $dbname;
    private string $driver;

    public function __construct()
    {
        $this->pdo = Connection::getConnection();
        $this->dbname = Config::getDbName();
        $this->driver = Config::getDriver();
        
        $this->createTrackerTables();
    }

    /**
     * Cria as tabelas auxiliares para rastreamento do schema
     */
    private function createTrackerTables(): void
    {
        $this->createTableSchemaTable();
        $this->createColumnSchemaTable();
        $this->createIndexSchemaTable();
        $this->createConstraintSchemaTable();
        $this->createForeignKeySchemaTable();
    }

    /**
     * Método público para criar as tabelas de rastreamento
     */
    public function createTrackingTables(): void
    {
        $this->createTrackerTables();
    }

    /**
     * Cria a tabela para rastrear informações das tabelas
     */
    private function createTableSchemaTable(): void
    {
        if ($this->driver === 'mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS `_schema_tables` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `table_name` VARCHAR(255) NOT NULL UNIQUE,
                `engine` VARCHAR(50) DEFAULT 'InnoDB',
                `collation_name` VARCHAR(100) DEFAULT 'utf8mb4_general_ci',
                `comment` TEXT,
                `auto_increment` BOOLEAN DEFAULT FALSE,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                `schema_hash` VARCHAR(64) NOT NULL
            ) ENGINE=InnoDB COLLATE=utf8mb4_general_ci";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS _schema_tables (
                id SERIAL PRIMARY KEY,
                table_name VARCHAR(255) NOT NULL UNIQUE,
                engine VARCHAR(50) DEFAULT 'InnoDB',
                collation_name VARCHAR(100) DEFAULT 'utf8mb4_general_ci',
                comment TEXT,
                auto_increment BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                schema_hash VARCHAR(64) NOT NULL
            )";
        }
        
        $this->pdo->exec($sql);
    }

    /**
     * Cria a tabela para rastrear informações das colunas
     */
    private function createColumnSchemaTable(): void
    {
        if ($this->driver === 'mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS `_schema_columns` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `table_name` VARCHAR(255) NOT NULL,
                `column_name` VARCHAR(255) NOT NULL,
                `data_type` VARCHAR(100) NOT NULL,
                `column_size` VARCHAR(50),
                `is_nullable` BOOLEAN DEFAULT TRUE,
                `column_default` TEXT,
                `is_primary` BOOLEAN DEFAULT FALSE,
                `is_unique` BOOLEAN DEFAULT FALSE,
                `comment` TEXT,
                `position` INT NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_table_column` (`table_name`, `column_name`),
                INDEX `idx_table_name` (`table_name`)
            ) ENGINE=InnoDB COLLATE=utf8mb4_general_ci";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS _schema_columns (
                id SERIAL PRIMARY KEY,
                table_name VARCHAR(255) NOT NULL,
                column_name VARCHAR(255) NOT NULL,
                data_type VARCHAR(100) NOT NULL,
                column_size VARCHAR(50),
                is_nullable BOOLEAN DEFAULT TRUE,
                column_default TEXT,
                is_primary BOOLEAN DEFAULT FALSE,
                is_unique BOOLEAN DEFAULT FALSE,
                comment TEXT,
                position INT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (table_name, column_name)
            )";
            
            $this->pdo->exec($sql);
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_schema_columns_table_name ON _schema_columns (table_name)");
        }
        
        if ($this->driver === 'mysql') {
            $this->pdo->exec($sql);
        }
    }

    /**
     * Cria a tabela para rastrear informações dos índices
     */
    private function createIndexSchemaTable(): void
    {
        if ($this->driver === 'mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS `_schema_indexes` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `table_name` VARCHAR(255) NOT NULL,
                `index_name` VARCHAR(255) NOT NULL,
                `columns` JSON NOT NULL,
                `is_unique` BOOLEAN DEFAULT FALSE,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_table_index` (`table_name`, `index_name`),
                INDEX `idx_table_name` (`table_name`)
            ) ENGINE=InnoDB COLLATE=utf8mb4_general_ci";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS _schema_indexes (
                id SERIAL PRIMARY KEY,
                table_name VARCHAR(255) NOT NULL,
                index_name VARCHAR(255) NOT NULL,
                columns JSONB NOT NULL,
                is_unique BOOLEAN DEFAULT FALSE,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (table_name, index_name)
            )";
            
            $this->pdo->exec($sql);
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_schema_indexes_table_name ON _schema_indexes (table_name)");
        }
        
        if ($this->driver === 'mysql') {
            $this->pdo->exec($sql);
        }
    }

    /**
     * Cria a tabela para rastrear constraints
     */
    private function createConstraintSchemaTable(): void
    {
        if ($this->driver === 'mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS `_schema_constraints` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `table_name` VARCHAR(255) NOT NULL,
                `constraint_name` VARCHAR(255) NOT NULL,
                `constraint_type` VARCHAR(50) NOT NULL,
                `columns` JSON NOT NULL,
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_table_constraint` (`table_name`, `constraint_name`),
                INDEX `idx_table_name` (`table_name`)
            ) ENGINE=InnoDB COLLATE=utf8mb4_general_ci";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS _schema_constraints (
                id SERIAL PRIMARY KEY,
                table_name VARCHAR(255) NOT NULL,
                constraint_name VARCHAR(255) NOT NULL,
                constraint_type VARCHAR(50) NOT NULL,
                columns JSONB NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (table_name, constraint_name)
            )";
            
            $this->pdo->exec($sql);
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_schema_constraints_table_name ON _schema_constraints (table_name)");
        }
        
        if ($this->driver === 'mysql') {
            $this->pdo->exec($sql);
        }
    }

    /**
     * Cria a tabela para rastrear foreign keys
     */
    private function createForeignKeySchemaTable(): void
    {
        if ($this->driver === 'mysql') {
            $sql = "CREATE TABLE IF NOT EXISTS `_schema_foreign_keys` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `table_name` VARCHAR(255) NOT NULL,
                `constraint_name` VARCHAR(255) NOT NULL,
                `column_name` VARCHAR(255) NOT NULL,
                `referenced_table` VARCHAR(255) NOT NULL,
                `referenced_column` VARCHAR(255) NOT NULL,
                `on_delete` VARCHAR(50) DEFAULT 'RESTRICT',
                `on_update` VARCHAR(50) DEFAULT 'RESTRICT',
                `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY `unique_table_fk` (`table_name`, `constraint_name`),
                INDEX `idx_table_name` (`table_name`)
            ) ENGINE=InnoDB COLLATE=utf8mb4_general_ci";
        } else {
            $sql = "CREATE TABLE IF NOT EXISTS _schema_foreign_keys (
                id SERIAL PRIMARY KEY,
                table_name VARCHAR(255) NOT NULL,
                constraint_name VARCHAR(255) NOT NULL,
                column_name VARCHAR(255) NOT NULL,
                referenced_table VARCHAR(255) NOT NULL,
                referenced_column VARCHAR(255) NOT NULL,
                on_delete VARCHAR(50) DEFAULT 'RESTRICT',
                on_update VARCHAR(50) DEFAULT 'RESTRICT',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE (table_name, constraint_name)
            )";
            
            $this->pdo->exec($sql);
            $this->pdo->exec("CREATE INDEX IF NOT EXISTS idx_schema_fks_table_name ON _schema_foreign_keys (table_name)");
        }
        
        if ($this->driver === 'mysql') {
            $this->pdo->exec($sql);
        }
    }

    /**
     * Salva o schema atual de uma tabela nas tabelas de rastreamento
     */
    public function saveTableSchema(string $tableName, array $tableData, array $columns, array $indexes = [], array $constraints = [], array $foreignKeys = []): void
    {
        $this->saveTableInfo($tableName, $tableData);
        $this->saveColumnsInfo($tableName, $columns);
        $this->saveIndexesInfo($tableName, $indexes);
        $this->saveConstraintsInfo($tableName, $constraints);
        $this->saveForeignKeysInfo($tableName, $foreignKeys);
    }

    /**
     * Salva informações da tabela
     */
    private function saveTableInfo(string $tableName, array $tableData): void
    {
        $schemaHash = $this->generateSchemaHash($tableData);
        
        $sql = "INSERT INTO " . $this->getTableName('_schema_tables') . " 
                (table_name, engine, collation_name, comment, auto_increment, schema_hash) 
                VALUES (?, ?, ?, ?, ?, ?)
                ON " . ($this->driver === 'mysql' ? 'DUPLICATE KEY UPDATE' : 'CONFLICT (table_name) DO UPDATE SET') . "
                engine = ?, collation_name = ?, comment = ?, auto_increment = ?, schema_hash = ?, updated_at = " . 
                ($this->driver === 'mysql' ? 'CURRENT_TIMESTAMP' : 'CURRENT_TIMESTAMP');
        
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $tableName,
            $tableData['engine'] ?? 'InnoDB',
            $tableData['collation'] ?? 'utf8mb4_general_ci',
            $tableData['comment'] ?? '',
            !empty($tableData['auto_increment']) ? 1 : 0,
            $schemaHash,
            $tableData['engine'] ?? 'InnoDB',
            $tableData['collation'] ?? 'utf8mb4_general_ci',
            $tableData['comment'] ?? '',
            !empty($tableData['auto_increment']) ? 1 : 0,
            $schemaHash
        ]);
    }

    /**
     * Salva informações das colunas
     */
    private function saveColumnsInfo(string $tableName, array $columns): void
    {
        // Remove colunas antigas
        $sql = "DELETE FROM " . $this->getTableName('_schema_columns') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);

        // Insere colunas atuais
        $position = 1;
        foreach ($columns as $column) {
            $sql = "INSERT INTO " . $this->getTableName('_schema_columns') . " 
                    (table_name, column_name, data_type, column_size, is_nullable, column_default, 
                     is_primary, is_unique, comment, position) 
                    VALUES (:table_name, :column_name, :data_type, :column_size, :is_nullable, :column_default, 
                            :is_primary, :is_unique, :comment, :position)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'table_name' => $tableName,
                'column_name' => $column['name'],
                'data_type' => $column['type'],
                'column_size' => $column['size'] ?: null,
                'is_nullable' => !empty($column['is_nullable']) ? 1 : 0,
                'column_default' => $column['default'] ?: null,
                'is_primary' => !empty($column['primary']) ? 1 : 0,
                'is_unique' => !empty($column['unique']) ? 1 : 0,
                'comment' => $column['comment'] ?: null,
                'position' => $position++
            ]);
        }
    }

    /**
     * Salva informações dos índices
     */
    private function saveIndexesInfo(string $tableName, array $indexes): void
    {
        // Remove índices antigos
        $sql = "DELETE FROM " . $this->getTableName('_schema_indexes') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);

        // Insere índices atuais
        foreach ($indexes as $indexName => $indexData) {
            $sql = "INSERT INTO " . $this->getTableName('_schema_indexes') . " 
                    (table_name, index_name, columns, is_unique) 
                    VALUES (:table_name, :index_name, :columns, :is_unique)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                'table_name' => $tableName,
                'index_name' => $indexName,
                'columns' => json_encode($indexData['columns']),
                'is_unique' => !empty($indexData['unique']) ? 1 : 0
            ]);
        }
    }

    /**
     * Salva informações das constraints
     */
    private function saveConstraintsInfo(string $tableName, array $constraints): void
    {
        // Remove constraints antigas
        $sql = "DELETE FROM " . $this->getTableName('_schema_constraints') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);

        // Insere constraints atuais
        foreach ($constraints as $constraintName => $constraintData) {
            $sql = "INSERT INTO " . $this->getTableName('_schema_constraints') . " 
                    (table_name, constraint_name, constraint_type, columns) 
                    VALUES (?, ?, ?, ?)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $tableName,
                $constraintName,
                $constraintData['type'],
                json_encode($constraintData['columns'])
            ]);
        }
    }

    /**
     * Salva informações das foreign keys
     */
    private function saveForeignKeysInfo(string $tableName, array $foreignKeys): void
    {
        // Remove foreign keys antigas
        $sql = "DELETE FROM " . $this->getTableName('_schema_foreign_keys') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);

        // Insere foreign keys atuais
        foreach ($foreignKeys as $fkData) {
            $sql = "INSERT INTO " . $this->getTableName('_schema_foreign_keys') . " 
                    (table_name, constraint_name, column_name, referenced_table, referenced_column, on_delete, on_update) 
                    VALUES (?, ?, ?, ?, ?, ?, ?)";
            
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $tableName,
                $fkData['constraint_name'],
                $fkData['column_name'],
                $fkData['referenced_table'],
                $fkData['referenced_column'],
                $fkData['on_delete'] ?? 'RESTRICT',
                $fkData['on_update'] ?? 'RESTRICT'
            ]);
        }
    }

    /**
     * Obtém o schema salvo de uma tabela
     */
    public function getSavedTableSchema(string $tableName): array
    {
        return [
            'table' => $this->getSavedTableInfo($tableName),
            'columns' => $this->getSavedColumnsInfo($tableName),
            'indexes' => $this->getSavedIndexesInfo($tableName),
            'constraints' => $this->getSavedConstraintsInfo($tableName),
            'foreign_keys' => $this->getSavedForeignKeysInfo($tableName)
        ];
    }

    /**
     * Obtém informações salvas da tabela
     */
    private function getSavedTableInfo(string $tableName): ?array
    {
        $sql = "SELECT * FROM " . $this->getTableName('_schema_tables') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        $result = $stmt->fetch(\PDO::FETCH_ASSOC);
        return $result ?: null;
    }

    /**
     * Obtém informações salvas das colunas
     */
    private function getSavedColumnsInfo(string $tableName): array
    {
        $sql = "SELECT * FROM " . $this->getTableName('_schema_columns') . " WHERE table_name = ? ORDER BY position";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Obtém informações salvas dos índices
     */
    private function getSavedIndexesInfo(string $tableName): array
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
     * Obtém informações salvas das constraints
     */
    private function getSavedConstraintsInfo(string $tableName): array
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
     * Obtém informações salvas das foreign keys
     */
    private function getSavedForeignKeysInfo(string $tableName): array
    {
        $sql = "SELECT * FROM " . $this->getTableName('_schema_foreign_keys') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        return $stmt->fetchAll(\PDO::FETCH_ASSOC);
    }

    /**
     * Verifica se uma tabela existe no rastreamento
     */
    public function tableExistsInTracking(string $tableName): bool
    {
        $sql = "SELECT COUNT(*) FROM " . $this->getTableName('_schema_tables') . " WHERE table_name = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$tableName]);
        
        return $stmt->fetchColumn() > 0;
    }

    /**
     * Remove uma tabela do rastreamento
     */
    public function removeTableFromTracking(string $tableName): void
    {
        $tables = ['_schema_tables', '_schema_columns', '_schema_indexes', '_schema_constraints', '_schema_foreign_keys'];
        
        foreach ($tables as $table) {
            $sql = "DELETE FROM " . $this->getTableName($table) . " WHERE table_name = ?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$tableName]);
        }
    }

    /**
     * Gera um hash do schema para detectar mudanças
     */
    private function generateSchemaHash(array $data): string
    {
        return hash('sha256', serialize($data));
    }

    /**
     * Obtém o nome da tabela com escape adequado para o driver
     */
    private function getTableName(string $tableName): string
    {
        return $this->driver === 'mysql' ? "`{$tableName}`" : $tableName;
    }

    /**
     * Obtém estatísticas das tabelas de rastreamento
     */
    public function getTrackingStats(): array
    {
        $stats = [
            'tables_tracked' => 0,
            'total_columns' => 0,
            'total_indexes' => 0,
            'total_constraints' => 0,
            'total_foreign_keys' => 0
        ];

        try {
            // Conta tabelas rastreadas
            $sql = "SELECT COUNT(*) FROM " . $this->getTableName('_schema_tables');
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $stats['tables_tracked'] = (int)$stmt->fetchColumn();

            // Conta colunas
            $sql = "SELECT COUNT(*) FROM " . $this->getTableName('_schema_columns');
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $stats['total_columns'] = (int)$stmt->fetchColumn();

            // Conta índices
            $sql = "SELECT COUNT(*) FROM " . $this->getTableName('_schema_indexes');
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $stats['total_indexes'] = (int)$stmt->fetchColumn();

            // Conta constraints
            $sql = "SELECT COUNT(*) FROM " . $this->getTableName('_schema_constraints');
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $stats['total_constraints'] = (int)$stmt->fetchColumn();

            // Conta foreign keys
            $sql = "SELECT COUNT(*) FROM " . $this->getTableName('_schema_foreign_keys');
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute();
            $stats['total_foreign_keys'] = (int)$stmt->fetchColumn();

        } catch (\Exception $e) {
            // Se houver erro, retorna estatísticas zeradas
        }

        return $stats;
    }
} 