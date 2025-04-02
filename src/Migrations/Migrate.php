<?php

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Connection;

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
      if (!$_ENV) {
         if (\file_exists('.env'))
            $_ENV = parse_ini_file('.env');
         elseif (\file_exists('../.env'))
            $_ENV = parse_ini_file('../.env');
         elseif (\file_exists('../../.env'))
            $_ENV = parse_ini_file('../../.env');
         elseif (\file_exists('../../../.env'))
            $_ENV = parse_ini_file('../../../.env');
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
         $sql = $pdo->prepare("DROP DATABASE IF EXISTS ".$_ENV['DBNAME']);
         $sql->execute();

         $sql = $pdo->prepare("CREATE DATABASE ".$_ENV['DBNAME']);
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
      return class_exists($className) &&
         method_exists($className, "table") &&
         is_subclass_of($className, "Diogodg\\Neoorm\\Abstract\\Model");
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
      return $_ENV["MODEL_NAMESPACE"] . "\\" . str_replace(".php", "", $tableFile);
   }
}
