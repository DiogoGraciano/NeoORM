<?php

namespace Diogodg\Neoorm\Migrations\Driver;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\Migrations\Interface\Table;
use Diogodg\Neoorm\Migrations\Column;
use Diogodg\Neoorm\Migrations\SchemaReader;
use Diogodg\Neoorm\Migrations\SchemaTracker;
use Diogodg\Neoorm\Migrations\SchemaComparator;
use Diogodg\Neoorm\Migrations\SchemaExtractor;
use Exception;

/**
 * Classe base para criação do banco de dados PostgreSQL.
 */
class TablePgsql implements Table
{
    /**
     * Nome da tabela.
     *
     * @var string
     */
    private string $table;

    /**
     * pdo.
     *
     * @var PDO
     */
    private \PDO $pdo;

    /**
     * Colunas.
     *
     * @var array
     */
    private array $columns = [];

    /**
     * index.
     *
     * @var array
     */
    private array $indexs = [];

    /**
     * primary.
     *
     * @var array
     */
    private array $primary = [];

    /**
     * isAutoIncrement.
     *
     * @var bool
     */
    private bool $isAutoIncrement = false;

    /**
     * tabela tem foreningKey
     *
     * @var bool
     */
    private bool $hasForeingKey = false;

    /**
     * array de classes das tabelas fk
     *
     * @var array
     */
    private array $foreningTables = [];

    /**
     * array de com os comandos sql
     *
     * @var array
     */
    private array $foreningKeySql = [];

    /**
     * array de colunas da tabela que tem fk
     *
     * @var array
     */
    private array $foreningColumns = [];

    /**
     * constraints customizadas
     *
     * @var array
     */
    private array $constraints = [];

    /**
     * Nome da tabela.
     *
     * @var string
     */
    private string $dbname = "";

    /**
     * Schema reader para acessar tabelas de rastreamento
     *
     * @var SchemaReader
     */
    private SchemaReader $schemaReader;

    /**
     * Schema tracker para rastrear mudanças
     *
     * @var SchemaTracker
     */
    private SchemaTracker $schemaTracker;

    /**
     * Schema comparator para gerar comandos SQL
     *
     * @var SchemaComparator
     */
    private SchemaComparator $schemaComparator;

    /**
     * Schema extractor para extrair informações
     *
     * @var SchemaExtractor
     */
    private SchemaExtractor $schemaExtractor;

    function __construct(string $table, string $engine = "InnoDB", string $collate = "utf8mb4_general_ci", string $comment = "")
    {
        // Inicia a Conexão
        $this->pdo = connection::getConnection();

        $this->dbname = Config::getDbName();

        $this->schemaReader = new SchemaReader();
        $this->schemaTracker = new SchemaTracker();
        $this->schemaComparator = new SchemaComparator($this->schemaTracker);
        $this->schemaExtractor = new SchemaExtractor();

        if (!$this->validateName($this->table = strtolower(trim($table)))) {
            throw new Exception("Nome é invalido");
        }
    }

    public function addColumn(column $column)
    {
        $column = $column->getColumn();

        $column->columnSql = ["{$column->name} {$column->type} {$column->null} {$column->default}", $column->unique, " "];

        $this->columns[$column->name] = $column;

        if ($column->primary) {
            $this->primary[] = $column->name;
            $this->columns = array_reverse($this->columns, true);
        }

        return $this;
    }

    public function addForeignKey(string $foreignTable, string $column = "id", string $foreignColumn = "id", string $onDelete = "RESTRICT"): self
    {
        $onDeleteOptions = [
            'CASCADE',
            'SET NULL',
            'SET DEFAULT',
            'RESTRICT',
            'NO ACTION'
        ];

        if (!in_array(strtoupper($onDelete), $onDeleteOptions)) {
            throw new Exception("onDelete na ForeingKey {$foreignTable}.{$foreignColumn} invalido para tabela: " . $this->table);
        }

        $this->hasForeingKey = true;
        $this->foreningTables[$foreignColumn] = $foreignTable;
        $this->foreningColumns[$foreignColumn] = $column;
        $this->foreningKeySql[] = " ALTER TABLE {$this->table} ADD CONSTRAINT 
                                    " . $this->table . "_" . $column . "_" . $foreignTable . "_" . $foreignColumn . " 
                                    FOREIGN KEY ({$column}) REFERENCES {$foreignTable} 
                                    ({$foreignColumn}) ON DELETE {$onDelete};";

        return $this;
    }

    public function isAutoIncrement(): self
    {
        $this->isAutoIncrement = true;
        return $this;
    }

    public function getAutoIncrement(): bool
    {
        return $this->isAutoIncrement;
    }

    public function addIndex(string $name, array $columns): self
    {
        if (count($columns) < 2) {
            throw new Exception("Numero de colunas tem que ser maior que 1");
        }
        if ($this->columns) {

            $name = strtolower($name);

            $tableColumns = array_keys($this->columns);
            $columnsFinal = [];
            foreach ($columns as $column) {
                $column = strtolower(trim($column));
                if ($this->validateName($column) && in_array($column, $tableColumns))
                    $columnsFinal[] = $column;
                else
                    throw new Exception("Coluna é invalida: " . $column);
            }
            $this->indexs[$name]["columns"] = $columnsFinal;
            $this->indexs[$name]["sql"] = "CREATE INDEX {$name} ON {$this->table} (" . implode(",", $columnsFinal) . ");";

            return $this;
        } else {
            throw new Exception("É preciso ter pelo menos uma coluna para adicionar o um index");
        }
    }

    public function addConstraint(string $name, string $type, array $columns): self
    {
        $name = strtolower(trim($name));
        $type = strtoupper(trim($type));
        
        if (!$this->validateName($name)) {
            throw new Exception("Nome da constraint é inválido: " . $name);
        }

        if (empty($columns)) {
            throw new Exception("É necessário especificar pelo menos uma coluna para a constraint");
        }

        // Valida se as colunas existem na tabela
        if ($this->columns) {
            $tableColumns = array_keys($this->columns);
            foreach ($columns as $column) {
                $column = strtolower(trim($column));
                if (!in_array($column, $tableColumns)) {
                    throw new Exception("Coluna não encontrada na tabela: " . $column);
                }
            }
        }

        $columnsList = implode(",", array_map('strtolower', array_map('trim', $columns)));

        switch ($type) {
            case 'CHECK':
                throw new Exception("Para constraints CHECK, use addColumn() com validação personalizada");
                
            case 'UNIQUE':
                $constraintSql = "ALTER TABLE {$this->table} ADD CONSTRAINT {$name} UNIQUE ({$columnsList});";
                break;
                
            case 'PRIMARY KEY':
                $constraintSql = "ALTER TABLE {$this->table} ADD CONSTRAINT {$name} PRIMARY KEY ({$columnsList});";
                break;
                
            case 'FOREIGN KEY':
                throw new Exception("Para constraints FOREIGN KEY, use addForeignKey()");
                
            case 'EXCLUSION':
                throw new Exception("Para constraints EXCLUSION, use o método create() diretamente com SQL personalizado");
                
            default:
                throw new Exception("Tipo de constraint não suportado: " . $type);
        }

        $this->constraints[$name] = [
            'type' => $type,
            'columns' => $columns,
            'sql' => $constraintSql
        ];

        return $this;
    }

    public function create()
    {
        try {
            // Extrai o schema atual da tabela
            $currentSchema = $this->schemaExtractor->extractTableSchema($this);
            
            // Executa a criação da tabela
            $this->executeCreateTable();
            
            // Salva o schema nas tabelas de rastreamento
            $this->schemaTracker->saveTableSchema(
                $this->table,
                $currentSchema['table'],
                $currentSchema['columns'],
                $currentSchema['indexes'],
                $currentSchema['constraints'],
                $currentSchema['foreign_keys']
            );
            
        } catch (Exception $e) {
            throw new Exception("Erro ao criar tabela {$this->table}: " . $e->getMessage());
        }
    }

    /**
     * Executa a criação física da tabela
     */
    private function executeCreateTable(): void
    {
        $sql = "SET session_replication_role = 'replica'; DROP TABLE IF EXISTS {$this->table};CREATE TABLE IF NOT EXISTS {$this->table}(";
        foreach ($this->columns as $column) {
            $sql .= implode(",", array_filter($column->columnSql));
        }

        $sql = trim($sql);

        if ($this->primary) {
            $sql .= "PRIMARY KEY (" . implode(",", $this->primary) . ")";
        }

        $sql .= ");";

        if ($this->primary && $this->isAutoIncrement) {
            $sql .= "ALTER TABLE {$this->table} ALTER " . implode(",", $this->primary) . " ADD GENERATED ALWAYS AS IDENTITY;";
        }

        foreach ($this->indexs as $index) {
            $sql .= $index["sql"];
        }

        foreach ($this->constraints as $constraint) {
            $sql .= $constraint["sql"];
        }

        $sql = str_replace(",)", ")", $sql) . " SET session_replication_role = 'origin';";

        $instructions = explode(";", $sql);
        foreach ($instructions as $query) {
            if ($query) {
                $this->pdo->exec($query);
            }
        }
    }

    public function addForeignKeytoTable()
    {
        foreach ($this->foreningKeySql as $sql) {
            $this->pdo->exec($sql);
        }
    }

    public function update()
    {
        try {
            // Se a tabela não existe fisicamente, cria ela
            if (!$this->exists()) {
                return $this->create();
            }

            // Extrai o schema atual da definição da tabela
            $currentSchema = $this->schemaExtractor->extractTableSchema($this);
            
            // Compara com o schema salvo e gera comandos SQL
            $sqlCommands = $this->schemaComparator->compareAndGenerateSQL($this->table, $currentSchema);
            
            // Executa os comandos SQL gerados
            if (!empty($sqlCommands)) {
                $this->executeSqlCommands($sqlCommands);
            }
            
            // Atualiza o schema salvo
            $this->schemaTracker->saveTableSchema(
                $this->table,
                $currentSchema['table'],
                $currentSchema['columns'],
                $currentSchema['indexes'],
                $currentSchema['constraints'],
                $currentSchema['foreign_keys']
            );
            
        } catch (Exception $e) {
            throw new Exception("Erro ao atualizar tabela {$this->table}: " . $e->getMessage());
        }
    }

    /**
     * Executa uma lista de comandos SQL
     */
    private function executeSqlCommands(array $sqlCommands): void
    {
        foreach ($sqlCommands as $sql) {
            if (trim($sql)) {
                try {
                    $this->pdo->exec($sql);
                } catch (\PDOException $e) {
                    throw new Exception("Erro ao executar SQL: {$sql}. Erro: " . $e->getMessage());
                }
            }
        }
    }

    public function hasForeignKey(): bool
    {
        return $this->hasForeingKey;
    }

    public function getForeignKeyTables(): array
    {
        return $this->foreningTables;
    }

    public function getTable(): string
    {
        return $this->table;
    }

    public function getColumns(): array
    {
        return $this->columns;
    }

    public function getEngine(): ?string
    {
        return null;
    }

    public function getCollation(): ?string
    {
        return null;
    }

    public function getComment(): ?string
    {
        return null;
    }

    public function getIndexes(): array
    {
        return $this->indexs;
    }

    public function getConstraints(): array
    {
        return $this->constraints;
    }

    public function exists(): bool
    {
        return $this->schemaReader->tableExists($this->table);
    }

    private function validateName($name)
    {
        // Expressão regular para verificar se o nome da tabela contém apenas caracteres permitidos
        $regex = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

        // Verifica se o nome da tabela corresponde à expressão regular
        if (preg_match($regex, $name)) {
            return true; // Nome da tabela é válido
        } else {
            return false; // Nome da tabela é inválido
        }
    }
}