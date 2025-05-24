<?php

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Migrations\Interface\Table;
use Exception;

/**
 * Classe responsável por extrair informações do schema atual das tabelas definidas
 */
class SchemaExtractor
{
    /**
     * Extrai o schema completo de uma instância de tabela
     */
    public function extractTableSchema(Table $tableInstance): array
    {
        $tableName = $tableInstance->getTable();
        $columns = $tableInstance->getColumns();
        
        return [
            'table' => $this->extractTableInfo($tableInstance),
            'columns' => $this->extractColumnsInfo($columns),
            'indexes' => $this->extractIndexesInfo($tableInstance),
            'constraints' => $this->extractConstraintsInfo($tableInstance),
            'foreign_keys' => $this->extractForeignKeysInfo($tableInstance)
        ];
    }

    /**
     * Extrai informações básicas da tabela
     */
    private function extractTableInfo(Table $tableInstance): array
    {
        $tableInfo = [
            'name' => $tableInstance->getTable(),
            'auto_increment' => $tableInstance->getAutoIncrement(),
            'engine' => null,
            'collation' => null,
            'comment' => null
        ];

        // Usa métodos getter se disponíveis, senão usa reflexão
        try {
            if (method_exists($tableInstance, 'getEngine')) {
                $tableInfo['engine'] = $tableInstance->getEngine();
            } else {
                // Fallback para reflexão (apenas MySQL)
                $reflection = new \ReflectionClass($tableInstance);
                if ($reflection->hasProperty('engine')) {
                    $engineProperty = $reflection->getProperty('engine');
                    $engineProperty->setAccessible(true);
                    $tableInfo['engine'] = $engineProperty->getValue($tableInstance) ?: 'InnoDB';
                }
            }

            if (method_exists($tableInstance, 'getCollation')) {
                $tableInfo['collation'] = $tableInstance->getCollation();
            } else {
                // Fallback para reflexão (apenas MySQL)
                $reflection = new \ReflectionClass($tableInstance);
                if ($reflection->hasProperty('collate')) {
                    $collateProperty = $reflection->getProperty('collate');
                    $collateProperty->setAccessible(true);
                    $tableInfo['collation'] = $collateProperty->getValue($tableInstance) ?: 'utf8mb4_general_ci';
                }
            }

            if (method_exists($tableInstance, 'getComment')) {
                $tableInfo['comment'] = $tableInstance->getComment();
            } else {
                // Fallback para reflexão (apenas MySQL)
                $reflection = new \ReflectionClass($tableInstance);
                if ($reflection->hasProperty('comment')) {
                    $commentProperty = $reflection->getProperty('comment');
                    $commentProperty->setAccessible(true);
                    $tableInfo['comment'] = $commentProperty->getValue($tableInstance) ?: '';
                }
            }
        } catch (\Exception $e) {
            // Ignora erros e mantém valores null
        }

        return $tableInfo;
    }

    /**
     * Extrai informações das colunas
     */
    private function extractColumnsInfo(array $columns): array
    {
        $columnsInfo = [];
        
        foreach ($columns as $columnName => $column) {
            $columnInfo = [
                'name' => $columnName,
                'type' => $this->extractColumnType($column),
                'size' => $this->extractColumnSize($column),
                'nullable' => $this->extractColumnNullable($column),
                'default' => $this->extractColumnDefault($column),
                'primary' => $this->extractColumnPrimary($column),
                'unique' => $this->extractColumnUnique($column),
                'comment' => $this->extractColumnComment($column)
            ];
            
            $columnsInfo[] = $columnInfo;
        }
        
        return $columnsInfo;
    }

    /**
     * Extrai o tipo da coluna
     */
    private function extractColumnType($column): string
    {
        if (is_object($column) && property_exists($column, 'type')) {
            return $column->type;
        }
        
        return 'VARCHAR'; // Valor padrão
    }

    /**
     * Extrai o tamanho da coluna
     */
    private function extractColumnSize($column): ?string
    {
        if (is_object($column) && property_exists($column, 'size')) {
            return $column->size;
        }
        
        return null;
    }

    /**
     * Extrai se a coluna aceita NULL
     */
    private function extractColumnNullable($column): bool
    {
        if (is_object($column) && property_exists($column, 'null')) {
            return empty($column->null) || $column->null !== 'NOT NULL';
        }
        
        return true; // Valor padrão
    }

    /**
     * Extrai o valor padrão da coluna
     */
    private function extractColumnDefault($column): ?string
    {
        if (is_object($column) && property_exists($column, 'defaultValue')) {
            return $column->defaultValue;
        }
        
        return null;
    }

    /**
     * Extrai se a coluna é chave primária
     */
    private function extractColumnPrimary($column): bool
    {
        if (is_object($column) && property_exists($column, 'primary')) {
            return !empty($column->primary);
        }
        
        return false;
    }

    /**
     * Extrai se a coluna é única
     */
    private function extractColumnUnique($column): bool
    {
        if (is_object($column) && property_exists($column, 'unique')) {
            return !empty($column->unique);
        }
        
        return false;
    }

    /**
     * Extrai o comentário da coluna
     */
    private function extractColumnComment($column): string
    {
        if (is_object($column) && property_exists($column, 'commentValue')) {
            return $column->commentValue ?: '';
        }
        
        return '';
    }

    /**
     * Extrai informações dos índices
     */
    private function extractIndexesInfo(Table $tableInstance): array
    {
        $indexes = [];
        
        try {
            if (method_exists($tableInstance, 'getIndexes')) {
                $indexsData = $tableInstance->getIndexes();
            } else {
                // Fallback para reflexão
                $reflection = new \ReflectionClass($tableInstance);
                if ($reflection->hasProperty('indexs')) {
                    $indexsProperty = $reflection->getProperty('indexs');
                    $indexsProperty->setAccessible(true);
                    $indexsData = $indexsProperty->getValue($tableInstance);
                } else {
                    return [];
                }
            }
            
            if (is_array($indexsData)) {
                foreach ($indexsData as $indexName => $indexInfo) {
                    $indexes[] = [
                        'name' => $indexName,
                        'columns' => $indexInfo['columns'] ?? [],
                        'type' => 'INDEX'
                    ];
                }
            }
        } catch (\Exception $e) {
            // Ignora erros e retorna array vazio
        }
        
        return $indexes;
    }

    /**
     * Extrai informações das constraints
     */
    private function extractConstraintsInfo(Table $tableInstance): array
    {
        $constraints = [];
        
        try {
            if (method_exists($tableInstance, 'getConstraints')) {
                $constraintsData = $tableInstance->getConstraints();
            } else {
                // Fallback para reflexão
                $reflection = new \ReflectionClass($tableInstance);
                if ($reflection->hasProperty('constraints')) {
                    $constraintsProperty = $reflection->getProperty('constraints');
                    $constraintsProperty->setAccessible(true);
                    $constraintsData = $constraintsProperty->getValue($tableInstance);
                } else {
                    return [];
                }
            }
            
            if (is_array($constraintsData)) {
                foreach ($constraintsData as $constraintName => $constraintInfo) {
                    $constraints[] = [
                        'name' => $constraintName,
                        'type' => $constraintInfo['type'] ?? 'UNKNOWN',
                        'columns' => $constraintInfo['columns'] ?? []
                    ];
                }
            }
        } catch (\Exception $e) {
            // Ignora erros e retorna array vazio
        }
        
        return $constraints;
    }

    /**
     * Extrai informações das foreign keys
     */
    private function extractForeignKeysInfo(Table $tableInstance): array
    {
        $foreignKeys = [];
        
        try {
            $reflection = new \ReflectionClass($tableInstance);
            
            // Extrai informações das foreign keys
            if ($reflection->hasProperty('foreningKeySql') && 
                $reflection->hasProperty('foreningTables') && 
                $reflection->hasProperty('foreningColumns')) {
                
                $foreningTablesProperty = $reflection->getProperty('foreningTables');
                $foreningTablesProperty->setAccessible(true);
                $foreningTables = $foreningTablesProperty->getValue($tableInstance);
                
                $foreningColumnsProperty = $reflection->getProperty('foreningColumns');
                $foreningColumnsProperty->setAccessible(true);
                $foreningColumns = $foreningColumnsProperty->getValue($tableInstance);
                
                if (is_array($foreningTables) && is_array($foreningColumns)) {
                    foreach ($foreningTables as $foreignColumn => $foreignTable) {
                        $localColumn = $foreningColumns[$foreignColumn] ?? $foreignColumn;
                        
                        $constraintName = $tableInstance->getTable() . '_' . $localColumn . '_' . $foreignTable . '_' . $foreignColumn;
                        
                        $foreignKeys[] = [
                            'constraint_name' => $constraintName,
                            'column_name' => $localColumn,
                            'referenced_table' => $foreignTable,
                            'referenced_column' => $foreignColumn,
                            'on_delete' => 'RESTRICT', // Valor padrão
                            'on_update' => 'RESTRICT'  // Valor padrão
                        ];
                    }
                }
            }
        } catch (\Exception $e) {
            // Ignora erros de reflexão
        }
        
        return $foreignKeys;
    }

    /**
     * Extrai informações de uma coluna específica para comparação
     */
    public function extractColumnForComparison($column): array
    {
        return [
            'name' => $column->name ?? '',
            'type' => $this->extractColumnType($column),
            'size' => $this->extractColumnSize($column),
            'nullable' => $this->extractColumnNullable($column),
            'default' => $this->extractColumnDefault($column),
            'primary' => $this->extractColumnPrimary($column),
            'unique' => $this->extractColumnUnique($column),
            'comment' => $this->extractColumnComment($column)
        ];
    }

    /**
     * Normaliza o tipo de dados para comparação entre drivers
     */
    public function normalizeDataType(string $type, string $driver): string
    {
        $type = strtoupper(trim($type));
        
        // Mapeamento de tipos entre MySQL e PostgreSQL
        $typeMapping = [
            'mysql' => [
                'INTEGER' => 'INT',
                'BOOLEAN' => 'TINYINT',
                'DOUBLE PRECISION' => 'DOUBLE'
            ],
            'pgsql' => [
                'INT' => 'INTEGER',
                'TINYINT' => 'SMALLINT',
                'DOUBLE' => 'DOUBLE PRECISION',
                'DATETIME' => 'TIMESTAMP'
            ]
        ];
        
        if (isset($typeMapping[$driver][$type])) {
            return $typeMapping[$driver][$type];
        }
        
        return $type;
    }

    /**
     * Extrai informações completas de uma tabela para debug
     */
    public function debugTableSchema(Table $tableInstance): array
    {
        $reflection = new \ReflectionClass($tableInstance);
        $properties = $reflection->getProperties();
        
        $debug = [
            'class' => get_class($tableInstance),
            'table_name' => $tableInstance->getTable(),
            'properties' => []
        ];
        
        foreach ($properties as $property) {
            $property->setAccessible(true);
            $value = $property->getValue($tableInstance);
            
            $debug['properties'][$property->getName()] = [
                'type' => gettype($value),
                'value' => is_object($value) ? get_class($value) : $value
            ];
        }
        
        return $debug;
    }
} 