<?php

declare(strict_types=1);

namespace Tests\Unit;

use Diogodg\Neoorm\Config;
use Tests\Support\UnitTestCase;

/**
 * Config::findEnvFile() sobe oito diretórios procurando um .env. Instalada em
 * vendor/ é isso que faz a descoberta funcionar; rodando a suíte da própria
 * biblioteca, a busca alcança diretórios fora do repositório, e um .env alheio
 * apontaria os testes de integração para um banco de verdade — que eles apagam.
 * NEOORM_DISABLE_DOTENV existe para fechar essa porta, e estes casos provam que
 * ela de fato fecha.
 */
final class ConfigTest extends UnitTestCase
{
    /** @var array<string,string|null> */
    private array $previous = [];

    private string $envFile = '';

    private bool $createdEnvFile = false;

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['NEOORM_DISABLE_DOTENV', 'DRIVER', 'DBHOST', 'DBNAME'] as $key) {
            $this->previous[$key] = $_ENV[$key] ?? null;
        }

        $this->envFile = \dirname(__DIR__, 2) . '/.env';
    }

    protected function tearDown(): void
    {
        if ($this->createdEnvFile && \is_file($this->envFile)) {
            \unlink($this->envFile);
        }

        $this->createdEnvFile = false;

        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        Config::reset();

        parent::tearDown();
    }

    public function testTheSuiteRunsWithDotenvDiscoveryDisabled(): void
    {
        $this->assertSame('1', $_ENV['NEOORM_DISABLE_DOTENV'] ?? null);
    }

    public function testEnvironmentValuesStillWinWhenDotenvIsDisabled(): void
    {
        $_ENV['DRIVER'] = 'pgsql';
        Config::reset();

        $this->assertSame('pgsql', Config::getDriver());
    }

    public function testMissingKeysReadAsAnEmptyString(): void
    {
        unset($_ENV['DBHOST'], $_SERVER['DBHOST']);
        Config::reset();

        $this->assertSame('', Config::getHost());
    }

    public function testDotenvFileIsIgnoredWhileTheFlagIsOn(): void
    {
        $this->writeEnvFile("DBNAME=vindo_do_arquivo\n");

        unset($_ENV['DBNAME'], $_SERVER['DBNAME']);
        $_ENV['NEOORM_DISABLE_DOTENV'] = '1';
        Config::reset();

        $this->assertSame('', Config::getDbName(), 'com a flag ligada o .env não pode ser lido');
    }

    public function testDotenvFileIsReadWhenTheFlagIsOff(): void
    {
        $this->writeEnvFile("DBNAME=vindo_do_arquivo\n");

        unset($_ENV['DBNAME'], $_SERVER['DBNAME']);
        $_ENV['NEOORM_DISABLE_DOTENV'] = '0';
        Config::reset();

        // Este é o contraponto do caso anterior: sem ele, "não leu o .env"
        // passaria mesmo se a leitura estivesse quebrada por outro motivo.
        $this->assertSame('vindo_do_arquivo', Config::getDbName());
    }

    /**
     * `NEOORM_DISABLE_DOTENV=false` tem que significar "faça a busca", não
     * "desligue" — tratar a mera presença da variável como verdadeiro é o erro
     * clássico e desligaria a descoberta em produção para quem escrevesse isso.
     */
    public function testFalseyFlagValuesLeaveDiscoveryOn(): void
    {
        $this->writeEnvFile("DBNAME=vindo_do_arquivo\n");

        foreach (['0', 'false', 'off', 'no', ''] as $falsey) {
            unset($_ENV['DBNAME'], $_SERVER['DBNAME']);
            $_ENV['NEOORM_DISABLE_DOTENV'] = $falsey;
            Config::reset();

            $this->assertSame('vindo_do_arquivo', Config::getDbName(), "valor falsey: '{$falsey}'");
        }
    }

    public function testTruthyFlagValuesTurnDiscoveryOff(): void
    {
        $this->writeEnvFile("DBNAME=vindo_do_arquivo\n");

        foreach (['1', 'true', 'on', 'yes'] as $truthy) {
            unset($_ENV['DBNAME'], $_SERVER['DBNAME']);
            $_ENV['NEOORM_DISABLE_DOTENV'] = $truthy;
            Config::reset();

            $this->assertSame('', Config::getDbName(), "valor truthy: '{$truthy}'");
        }
    }

    /**
     * Nunca sobrescreve um .env existente: se houver um, o caso é pulado em vez
     * de destruir a configuração local de quem está rodando a suíte.
     */
    private function writeEnvFile(string $contents): void
    {
        if (\is_file($this->envFile)) {
            self::markTestSkipped('Já existe um .env na raiz do repositório; o caso não vai sobrescrevê-lo.');
        }

        \file_put_contents($this->envFile, $contents);
        $this->createdEnvFile = true;
    }
}
