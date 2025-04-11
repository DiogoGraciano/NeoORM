<?php

namespace Diogodg\Neoorm;

use Exception;

final class Config
{
    private function __construct(){
    }

    private static function init()
    {
        if (!$_ENV && !($_ENV = parse_ini_file(dirname(__DIR__, 4).".env"))) {
            throw new Exception('$_ENV not set');
        }
    }

    public static function getDriver():string
    {
        self::init();
        return isset($_ENV["DRIVER"])?$_ENV["DRIVER"]:"";
    }

    public static function getHost():string
    {
        self::init();
        return isset($_ENV["DBHOST"])?$_ENV["DBHOST"]:"";
    }

    public static function getPort():string
    {
        self::init();
        return isset($_ENV["DBPORT"])?$_ENV["DBPORT"]:"";
    }

    public static function getDbName():string
    {
        self::init();
        return isset($_ENV["DBNAME"])?$_ENV["DBNAME"]:"";
    }

    public static function getCharset():string
    {
        self::init();
        return isset($_ENV["DBCHARSET"])?$_ENV["DBCHARSET"]:"";
    }

    public static function getUser():string
    {
        self::init();
        return isset($_ENV["DBUSER"])?$_ENV["DBUSER"]:"";
    }

    public static function getPassword():string
    {
        self::init();
        return isset($_ENV["DBPASSWORD"])?$_ENV["DBPASSWORD"]:"";
    }

    public static function getPathModel():string
    {
        self::init();
        return isset(Config::getPathModel())?Config::getPathModel():"";
    }

    public static function getModelNamespace():string
    {
        self::init();
        return isset($_ENV["MODEL_NAMESPACE"])?$_ENV["MODEL_NAMESPACE"]:"";
    }
}
