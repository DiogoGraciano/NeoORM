<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\Migrations\SchemaTracker;
use Diogodg\Neoorm\Migrations\SchemaComparator;
use Diogodg\Neoorm\Migrations\SchemaExtractor;
use Diogodg\Neoorm\Migrations\SchemaReader;
use Diogodg\Neoorm\Migrations\Driver\TableMysql;
use Diogodg\Neoorm\Migrations\Driver\TablePgsql;
use Diogodg\Neoorm\Migrations\Column;
use Diogodg\Neoorm\Migrations\Migrate;
use Exception;

class MigrationsTest extends TestCase
{
    private SchemaTracker $schemaTracker;
    private SchemaComparator $schemaComparator;
    private SchemaExtractor $schemaExtractor;
    private SchemaReader $schemaReader;
    private \PDO $pdo;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->pdo = Connection::getConnection();
        $this->schemaTracker = new SchemaTracker();
        $this->schemaComparator = new SchemaComparator($this->schemaTracker);
        $this->schemaExtractor = new SchemaExtractor();
        $this->schemaReader = new SchemaReader();
    }

    /**
     * Testa a criação das tabelas de rastreamento do schema
     */
    public function testCreateSchemaTrackingTables(): void
    {
        // Limpa as tabelas de rastreamento se existirem
        $this->cleanupTrackingTables();
        
        // Cria as tabelas de rastreamento
        $this->schemaTracker->createTrackingTables();
        
        // Verifica se as tabelas foram criadas
        $this->assertTrue($this->schemaReader->trackingTablesExist());
        
        // Verifica estatísticas iniciais
        $stats = $this->schemaTracker->getTrackingStats();
        $this->assertEquals(0, $stats['tables_tracked']);
        $this->assertEquals(0, $stats['total_columns']);
        $this->assertEquals(0, $stats['total_indexes']);
        $this->assertEquals(0, $stats['total_constraints']);
        $this->assertEquals(0, $stats['total_foreign_keys']);
    }

    /**
     * Testa a criação de uma tabela MySQL com rastreamento
     */
    public function testCreateMysqlTableWithTracking(): void
    {
        if (Config::getDriver() !== 'mysql') {
            $this->markTestSkipped('Teste específico para MySQL');
        }

        $tableName = 'test_mysql_table';
        $this->cleanupTestTable($tableName);

        // Cria uma tabela de teste
        $table = new TableMysql($tableName, 'InnoDB', 'utf8mb4_general_ci', 'Tabela de teste');
        
        $table->addColumn((new Column('id', 'INT'))->isPrimary()->setDefault(null, true))
              ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull())
              ->addColumn((new Column('email', 'VARCHAR', '255'))->isUnique())
              ->addColumn((new Column('created_at', 'TIMESTAMP'))->setDefault('CURRENT_TIMESTAMP', true));

        $table->isAutoIncrement();
        $table->create();

        // Verifica se a tabela existe
        $this->assertTrue($table->exists());
        
        // Verifica se foi salva no rastreamento
        $this->assertTrue($this->schemaTracker->tableExistsInTracking($tableName));
        
        // Verifica o schema salvo
        $savedSchema = $this->schemaTracker->getSavedTableSchema($tableName);
        $this->assertNotNull($savedSchema['table']);
        $this->assertCount(4, $savedSchema['columns']);
        
        $this->cleanupTestTable($tableName);
    }

    /**
     * Testa a criação de uma tabela PostgreSQL com rastreamento
     */
    public function testCreatePgsqlTableWithTracking(): void
    {
        if (Config::getDriver() !== 'pgsql') {
            $this->markTestSkipped('Teste específico para PostgreSQL');
        }

        $tableName = 'test_pgsql_table';
        $this->cleanupTestTable($tableName);

        // Cria uma tabela de teste
        $table = new TablePgsql($tableName);
        
        $table->addColumn((new Column('id', 'SERIAL'))->isPrimary())
              ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull())
              ->addColumn((new Column('email', 'VARCHAR', '255'))->isUnique())
              ->addColumn((new Column('created_at', 'TIMESTAMP'))->setDefault('CURRENT_TIMESTAMP', true));

        $table->create();

        // Verifica se a tabela existe
        $this->assertTrue($table->exists());
        
        // Verifica se foi salva no rastreamento
        $this->assertTrue($this->schemaTracker->tableExistsInTracking($tableName));
        
        // Verifica o schema salvo
        $savedSchema = $this->schemaTracker->getSavedTableSchema($tableName);
        $this->assertNotNull($savedSchema['table']);
        $this->assertCount(4, $savedSchema['columns']);
        
        $this->cleanupTestTable($tableName);
    }

    /**
     * Testa a atualização de uma tabela com mudanças de schema
     */
    public function testTableSchemaUpdate(): void
    {
        $tableName = 'test_update_table';
        $this->cleanupTestTable($tableName);

        // Cria tabela inicial
        $tableClass = Config::getDriver() === 'mysql' ? TableMysql::class : TablePgsql::class;
        $table = new $tableClass($tableName);
        
        $table->addColumn((new Column('id', Config::getDriver() === 'mysql' ? 'INT' : 'SERIAL'))->isPrimary())
              ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull());

        if (Config::getDriver() === 'mysql') {
            $table->isAutoIncrement();
        }

        $table->create();

        // Modifica a tabela adicionando uma nova coluna
        $table = new $tableClass($tableName);
        $table->addColumn((new Column('id', Config::getDriver() === 'mysql' ? 'INT' : 'SERIAL'))->isPrimary())
              ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull())
              ->addColumn((new Column('email', 'VARCHAR', '255'))->isUnique())
              ->addColumn((new Column('age', 'INT'))->setDefault(null));

        if (Config::getDriver() === 'mysql') {
            $table->isAutoIncrement();
        }

        $table->update();

        // Verifica se as mudanças foram aplicadas
        $savedSchema = $this->schemaTracker->getSavedTableSchema($tableName);
        $this->assertCount(4, $savedSchema['columns']);
        
        // Verifica se as novas colunas existem
        $columnNames = array_column($savedSchema['columns'], 'column_name');
        $this->assertContains('email', $columnNames);
        $this->assertContains('age', $columnNames);
        
        $this->cleanupTestTable($tableName);
    }

    /**
     * Testa a extração de schema de uma tabela
     */
    public function testSchemaExtraction(): void
    {
        $tableName = 'test_extraction_table';
        $this->cleanupTestTable($tableName);

        $tableClass = Config::getDriver() === 'mysql' ? TableMysql::class : TablePgsql::class;
        $table = new $tableClass($tableName);
        
        $table->addColumn((new Column('id', Config::getDriver() === 'mysql' ? 'INT' : 'SERIAL'))->isPrimary())
              ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull())
              ->addColumn((new Column('email', 'VARCHAR', '255'))->isUnique());

        if (Config::getDriver() === 'mysql') {
            $table->isAutoIncrement();
        }

        // Extrai o schema
        $extractedSchema = $this->schemaExtractor->extractTableSchema($table);

        // Verifica a estrutura extraída
        $this->assertArrayHasKey('table', $extractedSchema);
        $this->assertArrayHasKey('columns', $extractedSchema);
        $this->assertArrayHasKey('indexes', $extractedSchema);
        $this->assertArrayHasKey('constraints', $extractedSchema);
        $this->assertArrayHasKey('foreign_keys', $extractedSchema);

        // Verifica as colunas extraídas
        $this->assertCount(3, $extractedSchema['columns']);
        
        $columnNames = array_column($extractedSchema['columns'], 'name');
        $this->assertContains('id', $columnNames);
        $this->assertContains('name', $columnNames);
        $this->assertContains('email', $columnNames);
        
        $this->cleanupTestTable($tableName);
    }

    /**
     * Testa a comparação de schemas e geração de comandos SQL
     */
    public function testSchemaComparison(): void
    {
        $tableName = 'test_comparison_table';
        $this->cleanupTestTable($tableName);

        $tableClass = Config::getDriver() === 'mysql' ? TableMysql::class : TablePgsql::class;
        
        // Cria tabela inicial
        $table = new $tableClass($tableName);
        $table->addColumn((new Column('id', Config::getDriver() === 'mysql' ? 'INT' : 'SERIAL'))->isPrimary())
              ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull());

        if (Config::getDriver() === 'mysql') {
            $table->isAutoIncrement();
        }

        $table->create();

        // Modifica a definição da tabela
        $table = new $tableClass($tableName);
        $table->addColumn((new Column('id', Config::getDriver() === 'mysql' ? 'INT' : 'SERIAL'))->isPrimary())
              ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull())
              ->addColumn((new Column('email', 'VARCHAR', '255'))->isUnique())
              ->addColumn((new Column('status', 'VARCHAR', '50'))->setDefault('active'));

        if (Config::getDriver() === 'mysql') {
            $table->isAutoIncrement();
        }

        // Extrai o novo schema
        $currentSchema = $this->schemaExtractor->extractTableSchema($table);
        
        // Compara e gera comandos SQL
        $sqlCommands = $this->schemaComparator->compareAndGenerateSQL($tableName, $currentSchema);

        // Verifica se comandos SQL foram gerados
        $this->assertNotEmpty($sqlCommands);
        
        // Verifica se contém comandos para adicionar colunas
        $sqlString = implode(' ', $sqlCommands);
        $this->assertStringContainsString('ADD COLUMN', $sqlString);
        
        $this->cleanupTestTable($tableName);
    }

    /**
     * Testa a adição de índices e constraints
     */
    public function testIndexesAndConstraints(): void
    {
        $tableName = 'test_indexes_table';
        $this->cleanupTestTable($tableName);

        $tableClass = Config::getDriver() === 'mysql' ? TableMysql::class : TablePgsql::class;
        $table = new $tableClass($tableName);
        
        $table->addColumn((new Column('id', Config::getDriver() === 'mysql' ? 'INT' : 'SERIAL'))->isPrimary())
              ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull())
              ->addColumn((new Column('email', 'VARCHAR', '255'))->isUnique())
              ->addColumn((new Column('category', 'VARCHAR', '100')))
              ->addColumn((new Column('status', 'VARCHAR', '50')));

        if (Config::getDriver() === 'mysql') {
            $table->isAutoIncrement();
        }

        // Adiciona índice composto
        $table->addIndex('idx_category_status', ['category', 'status']);
        
        // Adiciona constraint
        $table->addConstraint('unique_name_email', 'UNIQUE', ['name', 'email']);

        $table->create();

        // Verifica se os índices e constraints foram salvos
        $savedSchema = $this->schemaTracker->getSavedTableSchema($tableName);
        
        // Verifica índices
        $this->assertNotEmpty($savedSchema['indexes']);
        
        // Verifica constraints
        $this->assertNotEmpty($savedSchema['constraints']);
        
        $this->cleanupTestTable($tableName);
    }

    /**
     * Testa foreign keys
     */
    public function testForeignKeys(): void
    {
        $parentTable = 'test_parent_table';
        $childTable = 'test_child_table';
        
        $this->cleanupTestTable($childTable);
        $this->cleanupTestTable($parentTable);

        $tableClass = Config::getDriver() === 'mysql' ? TableMysql::class : TablePgsql::class;
        
        // Cria tabela pai
        $parent = new $tableClass($parentTable);
        $parent->addColumn((new Column('id', Config::getDriver() === 'mysql' ? 'INT' : 'SERIAL'))->isPrimary())
               ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull());

        if (Config::getDriver() === 'mysql') {
            $parent->isAutoIncrement();
        }

        $parent->create();

        // Cria tabela filha com foreign key
        $child = new $tableClass($childTable);
        $child->addColumn((new Column('id', Config::getDriver() === 'mysql' ? 'INT' : 'SERIAL'))->isPrimary())
              ->addColumn((new Column('parent_id', 'INT'))->isNotNull())
              ->addColumn((new Column('description', 'TEXT')));

        if (Config::getDriver() === 'mysql') {
            $child->isAutoIncrement();
        }

        $child->addForeignKey($parentTable, 'parent_id', 'id', 'CASCADE');
        $child->create();

        // Verifica se a foreign key foi salva
        $savedSchema = $this->schemaTracker->getSavedTableSchema($childTable);
        $this->assertNotEmpty($savedSchema['foreign_keys']);
        
        $fk = $savedSchema['foreign_keys'][0];
        $this->assertEquals('parent_id', $fk['column_name']);
        $this->assertEquals($parentTable, $fk['referenced_table']);
        $this->assertEquals('id', $fk['referenced_column']);
        
        $this->cleanupTestTable($childTable);
        $this->cleanupTestTable($parentTable);
    }

    /**
     * Testa a execução completa de migrations
     */
    public function testFullMigrationExecution(): void
    {
        // Verifica se as tabelas de rastreamento existem
        $this->assertTrue($this->schemaReader->trackingTablesExist());
        
        // Executa as migrations
        $migrate = new Migrate();
        
        // Não recria o banco para não afetar outros testes
        $migrate->execute(false);
        
        // Verifica estatísticas após migrations
        $stats = $this->schemaTracker->getTrackingStats();
        $this->assertGreaterThan(0, $stats['tables_tracked']);
        $this->assertGreaterThan(0, $stats['total_columns']);
    }

    /**
     * Testa a remoção de tabela do rastreamento
     */
    public function testRemoveTableFromTracking(): void
    {
        $tableName = 'test_remove_table';
        $this->cleanupTestTable($tableName);

        $tableClass = Config::getDriver() === 'mysql' ? TableMysql::class : TablePgsql::class;
        $table = new $tableClass($tableName);
        
        $table->addColumn((new Column('id', Config::getDriver() === 'mysql' ? 'INT' : 'SERIAL'))->isPrimary())
              ->addColumn((new Column('name', 'VARCHAR', '255'))->isNotNull());

        if (Config::getDriver() === 'mysql') {
            $table->isAutoIncrement();
        }

        $table->create();

        // Verifica se está no rastreamento
        $this->assertTrue($this->schemaTracker->tableExistsInTracking($tableName));

        // Remove do rastreamento
        $this->schemaTracker->removeTableFromTracking($tableName);

        // Verifica se foi removida
        $this->assertFalse($this->schemaTracker->tableExistsInTracking($tableName));
        
        $this->cleanupTestTable($tableName);
    }

    /**
     * Limpa uma tabela de teste
     */
    private function cleanupTestTable(string $tableName): void
    {
        try {
            // Remove do rastreamento
            $this->schemaTracker->removeTableFromTracking($tableName);
            
            // Remove a tabela física
            $sql = "DROP TABLE IF EXISTS " . (Config::getDriver() === 'mysql' ? "`{$tableName}`" : $tableName);
            $this->pdo->exec($sql);
        } catch (Exception $e) {
            // Ignora erros de limpeza
        }
    }

    /**
     * Limpa as tabelas de rastreamento
     */
    private function cleanupTrackingTables(): void
    {
        $tables = ['_schema_foreign_keys', '_schema_constraints', '_schema_indexes', '_schema_columns', '_schema_tables'];
        
        foreach ($tables as $table) {
            try {
                $tableName = Config::getDriver() === 'mysql' ? "`{$table}`" : $table;
                $this->pdo->exec("DROP TABLE IF EXISTS {$tableName}");
            } catch (Exception $e) {
                // Ignora erros de limpeza
            }
        }
    }

    /**
     * Limpa após todos os testes
     */
    protected function tearDown(): void
    {
        // Limpa tabelas de teste que possam ter sobrado
        $testTables = [
            'test_mysql_table',
            'test_pgsql_table', 
            'test_update_table',
            'test_extraction_table',
            'test_comparison_table',
            'test_indexes_table',
            'test_parent_table',
            'test_child_table',
            'test_remove_table'
        ];

        foreach ($testTables as $table) {
            $this->cleanupTestTable($table);
        }

        parent::tearDown();
    }
} 