<?php

declare(strict_types=1);

namespace Tests\Support;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base dos testes que precisam de um servidor de verdade.
 *
 * O driver vem do arquivo de configuração do PHPUnit (phpunit-pgsql.xml ou
 * phpunit-mysql.xml), nunca de troca em tempo de execução. O tearDown afirma
 * que o driver continua o mesmo: se algum teste mexer nos estáticos de Config
 * ou Connection e não restaurar, a falha aparece no teste que causou, e não
 * dezenas de casos adiante — que é o modo de falha que a ordem aleatória de
 * execução torna impossível de reproduzir.
 */
abstract class DatabaseTestCase extends TestCase
{
    private string $expectedDriver = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->expectedDriver = Config::getDriver();

        if ($this->expectedDriver === '') {
            self::markTestSkipped('Sem DRIVER configurado: rode com phpunit-pgsql.xml ou phpunit-mysql.xml.');
        }
    }

    protected function tearDown(): void
    {
        $this->assertSame(
            $this->expectedDriver,
            Config::getDriver(),
            'O driver vazou entre testes. Quem trocar Config/Connection precisa restaurar no finally.',
        );

        parent::tearDown();
    }

    protected function pdo(): PDO
    {
        return Connection::getConnection();
    }

    protected function driver(): string
    {
        return Config::getDriver();
    }

    protected function isPgsql(): bool
    {
        return $this->driver() === 'pgsql';
    }

    protected function isMysql(): bool
    {
        return $this->driver() === 'mysql';
    }
}
