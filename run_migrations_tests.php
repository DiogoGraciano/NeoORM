<?php

/**
 * Script para executar testes de migrations
 * 
 * Este script configura o ambiente e executa os testes específicos
 * para as funcionalidades de migrations do NeoORM.
 */

require_once 'vendor/autoload.php';

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use Tests\MigrationsTest;

// Configuração do ambiente de teste
echo "=== Configurando ambiente de teste para Migrations ===" . PHP_EOL;

// Configurações do banco de dados para teste
Config::setDriver($_ENV['DB_DRIVER'] ?? 'mysql');
Config::setHost($_ENV['DB_HOST'] ?? 'localhost');
Config::setPort($_ENV['DB_PORT'] ?? '3306');
Config::setDbName($_ENV['DB_NAME'] ?? 'test_neoorm_migrations');
Config::setUser($_ENV['DB_USER'] ?? 'root');
Config::setPassword($_ENV['DB_PASSWORD'] ?? '');
Config::setCharset($_ENV['DB_CHARSET'] ?? 'utf8mb4');
Config::setPathModel('./tests/app/models');
Config::setModelNamespace('Tests\\App\\Models');

echo "Driver: " . Config::getDriver() . PHP_EOL;
echo "Host: " . Config::getHost() . PHP_EOL;
echo "Database: " . Config::getDbName() . PHP_EOL;
echo PHP_EOL;

// Função para executar um teste específico
function runTest($testClass, $testMethod, $description) {
    echo "=== {$description} ===" . PHP_EOL;
    
    try {
        $test = new $testClass();
        $test->setUp();
        
        $result = $test->$testMethod();
        
        echo "✅ PASSOU: {$description}" . PHP_EOL;
        return true;
    } catch (Exception $e) {
        echo "❌ FALHOU: {$description}" . PHP_EOL;
        echo "Erro: " . $e->getMessage() . PHP_EOL;
        echo "Arquivo: " . $e->getFile() . ":" . $e->getLine() . PHP_EOL;
        return false;
    } finally {
        echo PHP_EOL;
    }
}

// Função para simular assertions básicas
function assertTrue($condition, $message = '') {
    if (!$condition) {
        throw new Exception("Assertion failed: {$message}");
    }
}

function assertEquals($expected, $actual, $message = '') {
    if ($expected !== $actual) {
        throw new Exception("Assertion failed: Expected '{$expected}', got '{$actual}'. {$message}");
    }
}

function assertNotNull($value, $message = '') {
    if ($value === null) {
        throw new Exception("Assertion failed: Value should not be null. {$message}");
    }
}

function assertCount($expectedCount, $array, $message = '') {
    $actualCount = count($array);
    if ($expectedCount !== $actualCount) {
        throw new Exception("Assertion failed: Expected count {$expectedCount}, got {$actualCount}. {$message}");
    }
}

function assertContains($needle, $haystack, $message = '') {
    if (!in_array($needle, $haystack)) {
        throw new Exception("Assertion failed: Array does not contain '{$needle}'. {$message}");
    }
}

function assertNotEmpty($value, $message = '') {
    if (empty($value)) {
        throw new Exception("Assertion failed: Value should not be empty. {$message}");
    }
}

function assertStringContainsString($needle, $haystack, $message = '') {
    if (strpos($haystack, $needle) === false) {
        throw new Exception("Assertion failed: String does not contain '{$needle}'. {$message}");
    }
}

function assertArrayHasKey($key, $array, $message = '') {
    if (!array_key_exists($key, $array)) {
        throw new Exception("Assertion failed: Array does not have key '{$key}'. {$message}");
    }
}

function assertGreaterThan($expected, $actual, $message = '') {
    if ($actual <= $expected) {
        throw new Exception("Assertion failed: {$actual} is not greater than {$expected}. {$message}");
    }
}

function assertFalse($condition, $message = '') {
    if ($condition) {
        throw new Exception("Assertion failed: Expected false. {$message}");
    }
}

// Adiciona as funções de assertion ao escopo global
$GLOBALS['assertTrue'] = 'assertTrue';
$GLOBALS['assertEquals'] = 'assertEquals';
$GLOBALS['assertNotNull'] = 'assertNotNull';
$GLOBALS['assertCount'] = 'assertCount';
$GLOBALS['assertContains'] = 'assertContains';
$GLOBALS['assertNotEmpty'] = 'assertNotEmpty';
$GLOBALS['assertStringContainsString'] = 'assertStringContainsString';
$GLOBALS['assertArrayHasKey'] = 'assertArrayHasKey';
$GLOBALS['assertGreaterThan'] = 'assertGreaterThan';
$GLOBALS['assertFalse'] = 'assertFalse';

// Lista de testes para executar
$tests = [
    ['testCreateSchemaTrackingTables', 'Criação das tabelas de rastreamento'],
    ['testCreateMysqlTableWithTracking', 'Criação de tabela MySQL com rastreamento'],
    ['testCreatePgsqlTableWithTracking', 'Criação de tabela PostgreSQL com rastreamento'],
    ['testTableSchemaUpdate', 'Atualização de schema de tabela'],
    ['testSchemaExtraction', 'Extração de schema'],
    ['testSchemaComparison', 'Comparação de schemas'],
    ['testIndexesAndConstraints', 'Índices e constraints'],
    ['testForeignKeys', 'Foreign keys'],
    ['testRemoveTableFromTracking', 'Remoção de tabela do rastreamento'],
    ['testFullMigrationExecution', 'Execução completa de migrations']
];

// Executa os testes
$passed = 0;
$failed = 0;

echo "=== Iniciando testes de Migrations ===" . PHP_EOL . PHP_EOL;

foreach ($tests as [$method, $description]) {
    if (runTest(MigrationsTest::class, $method, $description)) {
        $passed++;
    } else {
        $failed++;
    }
}

// Relatório final
echo "=== Relatório Final ===" . PHP_EOL;
echo "✅ Testes que passaram: {$passed}" . PHP_EOL;
echo "❌ Testes que falharam: {$failed}" . PHP_EOL;
echo "📊 Total de testes: " . ($passed + $failed) . PHP_EOL;

if ($failed > 0) {
    echo PHP_EOL . "⚠️  Alguns testes falharam. Verifique os erros acima." . PHP_EOL;
    exit(1);
} else {
    echo PHP_EOL . "🎉 Todos os testes passaram!" . PHP_EOL;
    exit(0);
} 