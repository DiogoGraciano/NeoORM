<?php

declare(strict_types=1);

namespace Tests\Unit;

use Diogodg\Neoorm\DatabaseConfig;
use InvalidArgumentException;
use PDO;
use Tests\Support\UnitTestCase;

/**
 * DatabaseConfig é o valor que substitui a leitura de Config estático dentro do
 * sistema de migrações. Construí-lo não pode abrir conexão — a suíte unitária
 * inteira depende disso, e UnitTestCase falha se algo abrir socket.
 */
final class DatabaseConfigTest extends UnitTestCase
{
    private function pgsql(): DatabaseConfig
    {
        return new DatabaseConfig(
            driver: 'pgsql',
            host: 'postgres',
            port: '5432',
            database: 'test_neoorm',
            user: 'postgres',
            password: 'postgres',
        );
    }

    private function mysql(): DatabaseConfig
    {
        return new DatabaseConfig(
            driver: 'mysql',
            host: 'mysql',
            port: '3306',
            database: 'test_neoorm',
            user: 'root',
            password: 'root',
        );
    }

    public function testBuildingItOpensNoConnection(): void
    {
        $this->pgsql();
        $this->mysql();

        $this->assertFalse(\Diogodg\Neoorm\Connection::isOpen());
    }

    public function testUnsupportedDriverIsRejectedAtConstruction(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/sqlite/');

        new DatabaseConfig('sqlite', 'localhost', '0', 'db', 'user', '');
    }

    public function testEmptyDatabaseNameIsRejected(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/DBNAME/');

        new DatabaseConfig('pgsql', 'localhost', '5432', '', 'user', '');
    }

    public function testMysqlDsnCarriesTheCharsetAndPostgresDoesNot(): void
    {
        $this->assertSame(
            'mysql:host=mysql;port=3306;dbname=test_neoorm;charset=utf8mb4',
            $this->mysql()->dsn(),
        );

        $this->assertSame(
            'pgsql:host=postgres;port=5432;dbname=test_neoorm',
            $this->pgsql()->dsn(),
        );
    }

    /**
     * O DSN administrativo não pode depender do banco existir: é ele que cria e
     * derruba o banco. No MySQL isso é simplesmente omitir dbname; no
     * PostgreSQL é preciso conectar em algum banco, e `postgres` é o de
     * manutenção.
     */
    public function testServerDsnDoesNotDependOnTheTargetDatabaseExisting(): void
    {
        $this->assertSame('mysql:host=mysql;port=3306;charset=utf8mb4', $this->mysql()->serverDsn());
        $this->assertSame('pgsql:host=postgres;port=5432;dbname=postgres', $this->pgsql()->serverDsn());
    }

    public function testEffectiveSchemaIsTheDatabaseOnMysqlAndPublicByDefaultOnPostgres(): void
    {
        $this->assertSame('test_neoorm', $this->mysql()->effectiveSchema());
        $this->assertSame('public', $this->pgsql()->effectiveSchema());
    }

    public function testEffectiveSchemaHonoursAnExplicitPostgresSchema(): void
    {
        $config = new DatabaseConfig('pgsql', 'postgres', '5432', 'db', 'u', 'p', schema: 'app');

        $this->assertSame('app', $config->effectiveSchema());
    }

    public function testWithDatabaseReturnsACopyAndLeavesTheOriginalAlone(): void
    {
        $original = $this->pgsql();
        $copy = $original->withDatabase('outro');

        $this->assertSame('outro', $copy->database);
        $this->assertSame('test_neoorm', $original->database);
        $this->assertNotSame($original, $copy);
    }

    /**
     * Sem emulação o driver envia os parâmetros separados da query. O runner de
     * migrações depende disso, então a opção não pode variar por chamador.
     */
    public function testPdoOptionsDisableStatementEmulation(): void
    {
        $options = DatabaseConfig::pdoOptions();

        $this->assertFalse($options[PDO::ATTR_EMULATE_PREPARES]);
        $this->assertSame(PDO::ERRMODE_EXCEPTION, $options[PDO::ATTR_ERRMODE]);
        $this->assertFalse($options[PDO::ATTR_STRINGIFY_FETCHES]);
        $this->assertSame(PDO::FETCH_ASSOC, $options[PDO::ATTR_DEFAULT_FETCH_MODE]);
    }

    public function testMysqlPdoOptionsCountMatchedRows(): void
    {
        if (!defined('PDO::MYSQL_ATTR_FOUND_ROWS')) {
            $this->markTestSkipped('pdo_mysql não está carregado.');
        }

        $this->assertTrue(DatabaseConfig::pdoOptions('mysql')[PDO::MYSQL_ATTR_FOUND_ROWS]);
        $this->assertArrayNotHasKey(PDO::MYSQL_ATTR_FOUND_ROWS, DatabaseConfig::pdoOptions('pgsql'));
    }
}
