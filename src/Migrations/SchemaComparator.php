<?php

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Config;
use Exception;

/**
 * Classe responsável por comparar schemas e gerar comandos SQL automaticamente
 */
class SchemaComparator
{
    private string $driver;
    private SchemaTracker $tracker;

    public function __construct(SchemaTracker $tracker)
    {
        $this->driver = Config::getDriver();
        $this->tracker = $tracker;
    }

    /**
     * Compara o schema atual com o salvo e retorna os comandos SQL necessários
     */
    public function compareAndGenerateSQL(string $tableName, array $currentSchema): array
    {
        $savedSchema = $this->tracker->getSavedTableSchema($tableName);
        $sqlCommands = [];

        if (!$savedSchema['table']) {
            // Tabela nova - não precisa de comparação
            return [];
        }

        // Compara informações da tabela
        $sqlCommands = array_merge($sqlCommands, $this->compareTableInfo($tableName, $currentSchema['table'], $savedSchema['table']));

        // Compara colunas
        $sqlCommands = array_merge($sqlCommands, $this->compareColumns($tableName, $currentSchema['columns'], $savedSchema['columns']));

        // Compara índices
        $sqlCommands = array_merge($sqlCommands, $this->compareIndexes($tableName, $currentSchema['indexes'], $savedSchema['indexes']));

        // Compara constraints
        $sqlCommands = array_merge($sqlCommands, $this->compareConstraints($tableName, $currentSchema['constraints'], $savedSchema['constraints']));

        // Compara foreign keys
        $sqlCommands = array_merge($sqlCommands, $this->compareForeignKeys($tableName, $currentSchema['foreign_keys'], $savedSchema['foreign_keys']));

        return $sqlCommands;
    }

    /**
     * Compara informações da tabela (engine, collation, etc.)
     */
    private function compareTableInfo(string $tableName, array $current, array $saved): array
    {
        $sqlCommands = [];

        if ($this->driver === 'mysql') {
            if (($current['engine'] ?? 'InnoDB') !== $saved['engine']) {
                $sqlCommands[] = "ALTER TABLE `{$tableName}` ENGINE = " . ($current['engine'] ?? 'InnoDB');
            }

            if (($current['collation'] ?? 'utf8mb4_general_ci') !== $saved['collation_name']) {
                $sqlCommands[] = "ALTER TABLE `{$tableName}` COLLATE = " . ($current['collation_name'] ?? 'utf8mb4_general_ci');
            }

            if (($current['comment'] ?? '') !== $saved['comment']) {
                $sqlCommands[] = "ALTER TABLE `{$tableName}` COMMENT = '" . ($current['comment'] ?? '') . "'";
            }
        }

        return $sqlCommands;
    }

    /**
     * Compara colunas e gera comandos SQL
     */
    private function compareColumns(string $tableName, array $currentColumns, array $savedColumns): array
    {
        $sqlCommands = [];
        $savedColumnsByName = [];
        
        // Indexa colunas salvas por nome
        foreach ($savedColumns as $column) {
            $savedColumnsByName[$column['column_name']] = $column;
        }

        // Verifica colunas removidas
        foreach ($savedColumns as $savedColumn) {
            $found = false;
            foreach ($currentColumns as $currentColumn) {
                if ($currentColumn['name'] === $savedColumn['column_name']) {
                    $found = true;
                    break;
                }
            }
            
            if (!$found) {
                $sqlCommands[] = $this->generateDropColumnSQL($tableName, $savedColumn['column_name']);
            }
        }

        // Verifica colunas adicionadas ou modificadas
        foreach ($currentColumns as $currentColumn) {
            $savedColumn = $savedColumnsByName[$currentColumn['name']] ?? null;

            if (!$savedColumn) {
                // Coluna nova
                $sqlCommands[] = $this->generateAddColumnSQL($tableName, $currentColumn);
            } else {
                // Verifica se a coluna foi modificada
                if ($this->columnChanged($currentColumn, $savedColumn)) {
                    $sqlCommands[] = $this->generateModifyColumnSQL($tableName, $currentColumn);
                }
            }
        }

        return $sqlCommands;
    }

    /**
     * Verifica se uma coluna foi modificada
     */
    private function columnChanged(array $current, array $saved): bool
    {
        $fieldsToCompare = [
            'type' => 'data_type',
            'size' => 'column_size',
            'nullable' => 'is_nullable',
            'default' => 'column_default',
            'primary' => 'is_primary',
            'unique' => 'is_unique',
            'comment' => 'comment'
        ];

        if (isset($current['primary']) && $current['primary']) {
            $current['nullable'] = false;
        }

        if(Config::getDriver() === 'pgsql') {
            unset($fieldsToCompare['comment']);
        }

        foreach ($fieldsToCompare as $currentField => $savedField) {
            $currentValue = $current[$currentField] ?? null;
            $savedValue = $saved[$savedField] ?? null;

            // Normaliza valores booleanos
            if (in_array($currentField, ['nullable', 'primary', 'unique'])) {
                $currentValue = (bool)$currentValue;
                $savedValue = (bool)$savedValue;
            }

            if ($currentValue !== $savedValue) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gera SQL para adicionar coluna
     */
    private function generateAddColumnSQL(string $tableName, array $column): string
    {
        $tableName = $this->escapeTableName($tableName);
        $columnDef = $this->buildColumnDefinition($column);

        if ($this->driver === 'mysql') {
            return "ALTER TABLE {$tableName} ADD COLUMN {$columnDef}";
        } else {
            return "ALTER TABLE {$tableName} ADD COLUMN {$columnDef}";
        }
    }

    /**
     * Gera SQL para modificar coluna
     */
    private function generateModifyColumnSQL(string $tableName, array $column): string
    {
        $tableName = $this->escapeTableName($tableName);
        
        if ($this->driver === 'mysql') {
            $columnDef = $this->buildColumnDefinition($column);
            return "ALTER TABLE {$tableName} MODIFY COLUMN {$columnDef}";
        } else {
            // PostgreSQL requer comandos separados para diferentes alterações
            $commands = [];
            $columnName = $column['name'];
            
            $commands[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} TYPE {$column['type']}";
            
            if (isset($column['primary']) && $column['primary']) {
                $commands[] = "ALTER TABLE {$tableName} ADD PRIMARY KEY ({$columnName})";
            }

            if (isset($column['nullable']) && !(isset($column['primary']) && $column['primary'])) {
                if ($column['nullable']) {
                    $commands[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} DROP NOT NULL";
                } else {
                    $commands[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} SET NOT NULL";
                }
            }
            
            if (isset($column['default'])) {
                if ($column['default'] !== null) {
                    $commands[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} SET DEFAULT {$column['default']}";
                } else {
                    $commands[] = "ALTER TABLE {$tableName} ALTER COLUMN {$columnName} DROP DEFAULT";
                }
            }
            
            return implode('; ', $commands);
        }
    }

    /**
     * Gera SQL para remover coluna
     */
    private function generateDropColumnSQL(string $tableName, string $columnName): string
    {
        $tableName = $this->escapeTableName($tableName);
        return "ALTER TABLE {$tableName} DROP COLUMN {$columnName}";
    }

    /**
     * Constrói a definição de uma coluna
     */
    private function buildColumnDefinition(array $column): string
    {
        $definition = "{$column['name']} {$column['type']}";

        if (isset($column['size']) && $column['size']) {
            $definition = "{$column['name']} {$column['type']}({$column['size']})";
        }

        if (isset($column['nullable']) && !$column['nullable']) {
            $definition .= " NOT NULL";
        }

        if (isset($column['default']) && $column['default'] !== null) {
            if (is_string($column['default'])) {
                $definition .= " DEFAULT '{$column['default']}'";
            } else {
                $definition .= " DEFAULT {$column['default']}";
            }
        }

        if ($this->driver === 'mysql' && isset($column['comment']) && $column['comment']) {
            $definition .= " COMMENT '{$column['comment']}'";
        }

        return $definition;
    }

    /**
     * Compara índices
     */
    private function compareIndexes(string $tableName, array $currentIndexes, array $savedIndexes): array
    {
        $sqlCommands = [];

        // Remove índices que não existem mais
        foreach ($savedIndexes as $indexName => $savedIndex) {
            if (!isset($currentIndexes[$indexName])) {
                $sqlCommands[] = $this->generateDropIndexSQL($tableName, $indexName);
            }
        }

        // Adiciona novos índices ou modifica existentes
        foreach ($currentIndexes as $indexName => $currentIndex) {
            $savedIndex = $savedIndexes[$indexName] ?? null;

            if (!$savedIndex || $this->indexChanged($currentIndex, $savedIndex)) {
                if ($savedIndex) {
                    // Remove o índice antigo primeiro
                    $sqlCommands[] = $this->generateDropIndexSQL($tableName, $indexName);
                }
                // Adiciona o novo índice
                $sqlCommands[] = $this->generateCreateIndexSQL($tableName, $indexName, $currentIndex);
            }
        }

        return $sqlCommands;
    }

    /**
     * Verifica se um índice foi modificado
     */
    private function indexChanged(array $current, array $saved): bool
    {
        return json_encode($current['columns']) !== json_encode($saved['columns']) ||
               ($current['unique'] ?? false) !== ($saved['unique'] ?? false);
    }

    /**
     * Gera SQL para criar índice
     */
    private function generateCreateIndexSQL(string $tableName, string $indexName, array $index): string
    {
        $tableName = $this->escapeTableName($tableName);
        $columns = implode(', ', $index['columns']);
        $unique = ($index['unique'] ?? false) ? 'UNIQUE ' : '';

        return "CREATE {$unique}INDEX {$indexName} ON {$tableName} ({$columns})";
    }

    /**
     * Gera SQL para remover índice
     */
    private function generateDropIndexSQL(string $tableName, string $indexName): string
    {
        if ($this->driver === 'mysql') {
            $tableName = $this->escapeTableName($tableName);
            return "ALTER TABLE {$tableName} DROP INDEX {$indexName}";
        } else {
            return "DROP INDEX IF EXISTS {$indexName}";
        }
    }

    /**
     * Compara constraints
     */
    private function compareConstraints(string $tableName, array $currentConstraints, array $savedConstraints): array
    {
        $sqlCommands = [];

        // Remove constraints que não existem mais
        foreach ($savedConstraints as $constraintName => $savedConstraint) {
            if (!isset($currentConstraints[$constraintName])) {
                $sqlCommands[] = $this->generateDropConstraintSQL($tableName, $constraintName);
            }
        }

        // Adiciona novas constraints ou modifica existentes
        foreach ($currentConstraints as $constraintName => $currentConstraint) {
            $savedConstraint = $savedConstraints[$constraintName] ?? null;

            if (!$savedConstraint || $this->constraintChanged($currentConstraint, $savedConstraint)) {
                if ($savedConstraint) {
                    // Remove a constraint antiga primeiro
                    $sqlCommands[] = $this->generateDropConstraintSQL($tableName, $constraintName);
                }
                // Adiciona a nova constraint
                $sqlCommands[] = $this->generateAddConstraintSQL($tableName, $constraintName, $currentConstraint);
            }
        }

        return $sqlCommands;
    }

    /**
     * Verifica se uma constraint foi modificada
     */
    private function constraintChanged(array $current, array $saved): bool
    {
        return $current['type'] !== $saved['type'] ||
               json_encode($current['columns']) !== json_encode($saved['columns']);
    }

    /**
     * Gera SQL para adicionar constraint
     */
    private function generateAddConstraintSQL(string $tableName, string $constraintName, array $constraint): string
    {
        $tableName = $this->escapeTableName($tableName);
        $columns = implode(', ', $constraint['columns']);

        return "ALTER TABLE {$tableName} ADD CONSTRAINT {$constraintName} {$constraint['type']} ({$columns})";
    }

    /**
     * Gera SQL para remover constraint
     */
    private function generateDropConstraintSQL(string $tableName, string $constraintName): string
    {
        $tableName = $this->escapeTableName($tableName);
        
        if ($this->driver === 'mysql') {
            return "ALTER TABLE {$tableName} DROP CONSTRAINT {$constraintName}";
        } else {
            return "ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS \"{$constraintName}\"";
        }
    }

    /**
     * Compara foreign keys
     */
    private function compareForeignKeys(string $tableName, array $currentForeignKeys, array $savedForeignKeys): array
    {
        $sqlCommands = [];

        // Indexa foreign keys salvas por constraint name
        $savedFksByName = [];
        foreach ($savedForeignKeys as $fk) {
            $savedFksByName[$fk['constraint_name']] = $fk;
        }

        // Indexa foreign keys atuais por constraint name
        $currentFksByName = [];
        foreach ($currentForeignKeys as $fk) {
            $currentFksByName[$fk['constraint_name']] = $fk;
        }

        // Remove foreign keys que não existem mais
        foreach ($savedFksByName as $constraintName => $savedFk) {
            if (!isset($currentFksByName[$constraintName])) {
                $sqlCommands[] = $this->generateDropForeignKeySQL($tableName, $constraintName);
            }
        }

        // Adiciona novas foreign keys ou modifica existentes
        foreach ($currentFksByName as $constraintName => $currentFk) {
            $savedFk = $savedFksByName[$constraintName] ?? null;

            if (!$savedFk || $this->foreignKeyChanged($currentFk, $savedFk)) {
                if ($savedFk) {
                    // Remove a foreign key antiga primeiro
                    $sqlCommands[] = $this->generateDropForeignKeySQL($tableName, $constraintName);
                }
                // Adiciona a nova foreign key
                $sqlCommands[] = $this->generateAddForeignKeySQL($tableName, $currentFk);
            }
        }

        return $sqlCommands;
    }

    /**
     * Verifica se uma foreign key foi modificada
     */
    private function foreignKeyChanged(array $current, array $saved): bool
    {
        $fieldsToCompare = ['column_name', 'referenced_table', 'referenced_column', 'on_delete', 'on_update'];

        foreach ($fieldsToCompare as $field) {
            if (($current[$field] ?? null) !== ($saved[$field] ?? null)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Gera SQL para adicionar foreign key
     */
    private function generateAddForeignKeySQL(string $tableName, array $foreignKey): string
    {
        $tableName = $this->escapeTableName($tableName);
        
        return "ALTER TABLE {$tableName} ADD CONSTRAINT {$foreignKey['constraint_name']} " .
               "FOREIGN KEY ({$foreignKey['column_name']}) " .
               "REFERENCES {$foreignKey['referenced_table']} ({$foreignKey['referenced_column']}) " .
               "ON DELETE {$foreignKey['on_delete']} ON UPDATE {$foreignKey['on_update']}";
    }

    /**
     * Gera SQL para remover foreign key
     */
    private function generateDropForeignKeySQL(string $tableName, string $constraintName): string
    {
        $tableName = $this->escapeTableName($tableName);
        
        if ($this->driver === 'mysql') {
            return "ALTER TABLE {$tableName} DROP FOREIGN KEY {$constraintName}";
        } else {
            return "ALTER TABLE {$tableName} DROP CONSTRAINT IF EXISTS \"{$constraintName}\"";
        }
    }

    /**
     * Escapa o nome da tabela conforme o driver
     */
    private function escapeTableName(string $tableName): string
    {
        return $this->driver === 'mysql' ? "`{$tableName}`" : $tableName;
    }
} 