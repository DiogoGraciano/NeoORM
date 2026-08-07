<?php

namespace Diogodg\Neoorm;

final class Config
{
    /**
     * Valores lidos do arquivo .env, carregados uma única vez.
     *
     * @var array<string,string>|null
     */
    private static ?array $fileEnv = null;

    private function __construct(){
    }

    /**
     * Carrega o .env do projeto, se ainda não tiver sido carregado.
     *
     * Os valores de $_ENV/$_SERVER têm precedência sobre o arquivo, para que
     * variáveis de ambiente (container, CI, phpunit.xml) possam sobrescrever.
     */
    private static function init(): void
    {
        if (self::$fileEnv !== null) {
            return;
        }

        $path = self::findEnvFile();

        if ($path === null) {
            self::$fileEnv = [];
            return;
        }

        // INI_SCANNER_RAW preserva senhas com #, $, aspas e outros caracteres
        // que o parser padrão do parse_ini_file interpretaria.
        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);

        self::$fileEnv = $parsed === false ? [] : $parsed;
    }

    /**
     * Procura o .env subindo a árvore de diretórios a partir deste arquivo.
     * Funciona tanto instalado em vendor/ quanto com o pacote em path repository.
     */
    private static function findEnvFile(): ?string
    {
        $dir = __DIR__;

        for ($i = 0; $i < 8; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . '.env';

            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    /**
     * Lê uma configuração, com precedência: $_ENV > $_SERVER > arquivo .env.
     */
    private static function get(string $key, string $default = ""): string
    {
        self::init();

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? self::$fileEnv[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Limpa o cache do .env. Útil em testes que trocam a configuração.
     */
    public static function reset(): void
    {
        self::$fileEnv = null;
    }

    public static function getDriver():string
    {
        return self::get("DRIVER");
    }

    public static function getHost():string
    {
        return self::get("DBHOST");
    }

    public static function getPort():string
    {
        return self::get("DBPORT");
    }

    public static function getDbName():string
    {
        return self::get("DBNAME");
    }

    public static function getCharset():string
    {
        return self::get("DBCHARSET");
    }

    public static function getUser():string
    {
        return self::get("DBUSER");
    }

    public static function getPassword():string
    {
        return self::get("DBPASSWORD");
    }

    public static function getPathModel():string
    {
        return self::get("PATH_MODEL");
    }

    public static function getModelNamespace():string
    {
        return self::get("MODEL_NAMESPACE");
    }
}
