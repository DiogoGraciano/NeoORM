<?php

namespace Diogodg\Neoorm\Migrations;

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
   public function execute(bool $recreate): void
   {
      try {

         if ($recreate) {
            $this->recreateDatabase();
         }

         connection::beginTransaction();

         $tableFiles = scandir($_ENV["PATH_MODEL"]);
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
      if (empty($_ENV)) {
         throw new Exception('$_ENV should be set');
      }

      if ($_ENV["DRIVER"] == "mysql") {
         $dsn = sprintf(
            $_ENV["DRIVER"] . ':host=%s;port=%s;charset=%s',
            $_ENV["DBHOST"],
            $_ENV["DBPORT"],
            $_ENV["DBCHARSET"]
         );
      } else {
         $dsn = sprintf(
            $_ENV["DRIVER"] . ':host=%s;port=%s',
            $_ENV["DBHOST"],
            $_ENV["DBPORT"]
         );
      }
      $pdo = new \PDO($dsn, $_ENV["DBUSER"], $_ENV["DBPASSWORD"]);
      $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

      try {
         $sql = $pdo->prepare("DROP DATABASE IF EXISTS " . $_ENV['DBNAME']);
         $sql->execute();

         $sql = $pdo->prepare("CREATE DATABASE " . $_ENV['DBNAME']);
         $sql->execute();
      } catch (\PDOException $e) {
         echo "Erro ao criar banco de dados: " . $e->getMessage();
      }
   }

   /**
    * Verifica se uma classe é válida como modelo
    *
    * @param string $className
    * @return bool
    */
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
      } catch (\ReflectionException $e) {
         return false;
      } catch (\Throwable $e) {
         return false;
      }
   }

   /**
    * Obtém o nome completo da classe a partir do arquivo
    *
    * @param array $env
    * @param string $tableFile
    * @return string
    */
   private function getClassNameFromFile(string $tableFile): string
   {
      return $_ENV["MODEL_NAMESPACE"] . str_replace(".php", "", $tableFile);
   }
}
