<?php

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use Exception;

class Migrate
{
   /**
    * Executa as migrações e seeds de todas as tabelas
    *
    * @param bool $recreate Indica se as tabelas devem ser recriadas
    * @return void
    * @throws \Exception
    */
   public function execute(bool $recreate):void
   {
      try {

         if ($recreate) {
            $this->recreateDatabase();
         }

         connection::beginTransaction();

         $tableFiles = scandir(Config::getPathModel());
         $allCreatedTableInstances = [];

         foreach ($tableFiles as $tableFile) {

            if ($tableFile === '.' || $tableFile === '..' || !str_ends_with(strtolower($tableFile), '.php')) {
               continue;
            }

            $className = $this->getClassNameFromFile($tableFile);

            if ($this->isValidModelClass($className)) {
               $tableInstance = $className::table();
               if (!$tableInstance->exists()) {
                  $tableInstance->create();
                  $allCreatedTableInstances[] = $tableInstance;
                  echo "Criando " . $tableInstance->getTable() . PHP_EOL;
               } else {
                  $tableInstance->update();
                  echo "Atualizando " . $tableInstance->getTable() . PHP_EOL;
               }

               if (method_exists($className, "seed")) {
                  $className::seed();
               }
            }
         }

         foreach ($allCreatedTableInstances as $instance) {
            $instance->addForeignKeytoTable();
            echo "Adicionando FK " . $instance->getTable() . PHP_EOL;
         }

         connection::commit();
      } catch (\Exception $e) {
         connection::rollBack();
         echo "Erro durante a migração: " . $e->getMessage() . PHP_EOL;
         throw $e;
      }
   }

   public function recreateDatabase()
   {
      if (Config::getDriver() == "mysql") {
         $dsn = sprintf(
            Config::getDriver() . ':host=%s;port=%s;charset=%s',
            Config::getHost(),
            Config::getPort(),
            Config::getCharset()
         );
      } else {
         $dsn = sprintf(
            Config::getDriver() . ':host=%s;port=%s',
            Config::getHost(),
            Config::getPort()
         );
      }
      $pdo = new \PDO($dsn, Config::getUser(), Config::getPassword());
      $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

      try {
         $sql = $pdo->prepare("DROP DATABASE IF EXISTS " . Config::getDbName());
         $sql->execute();

         $sql = $pdo->prepare("CREATE DATABASE " . Config::getDbName());
         $sql->execute();
      } catch (\PDOException $e) {
         echo "Erro ao criar banco de dados: " . $e->getMessage();
      }
   }

   private function isValidModelClass(string $className): bool
   {
      try {
         $reflection = new \ReflectionClass($className);

         $baseModelClass = "Diogodg\\Neoorm\\Abstract\\Model";
         if (!$reflection->isSubclassOf($baseModelClass)) {
            return false;
         }

         if (!$reflection->hasMethod('table')) {
            return false;
         }

         $tableMethod = $reflection->getMethod('table');
         if (!$tableMethod->isStatic()) {
            return false;
         }
         return true;
      } catch (\ReflectionException | \Throwable $e) {
         return false;
      } 
   }

   private function getClassNameFromFile(string $tableFile): string
   {
      return Config::getModelNamespace()."\\".str_replace(".php", "", $tableFile);
   }
}