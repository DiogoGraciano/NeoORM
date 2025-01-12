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
   public static function execute(bool $recreate): void
   {
      try {
         $env = self::loadEnv();
         connection::beginTransaction();

         $tableFiles = scandir($env["PATH_MODEL"]);
         $tablesWithForeignKeys = [];
         $allTableInstances = [];

         foreach ($tableFiles as $tableFile) {
            $className = self::getClassNameFromFile($env, $tableFile);

            if (self::isValidModelClass($className)) {
               $tableInstance = $className::table();
               $allTableInstances[] = $className;

               if ($tableInstance->hasForeignKey()) {
                  if (!$tableInstance->exists()) {
                     $tablesWithForeignKeys[] = $tableInstance;
                  } else {
                     self::migrateTable($tableInstance, $className, $recreate);
                  }
               } else {
                  self::migrateTable($tableInstance, $className, $recreate);
               }
            }
         }

         self::resolveTableDependencies($tablesWithForeignKeys, $recreate);
         connection::commit();
      } catch (\Exception $e) {
         connection::rollBack();
         echo "Erro durante a migração: " . $e->getMessage() . PHP_EOL;
         throw $e;
      }
   }

   /**
    * Carrega o arquivo de configuração .env
    *
    * @return array
    */
   private static function loadEnv(): array
   {
      return parse_ini_file('.env');
   }

   /**
    * Verifica se uma classe é válida como modelo
    *
    * @param string $className
    * @return bool
    */
   private static function isValidModelClass(string $className): bool
   {
      return class_exists($className) &&
         method_exists($className, "table") &&
         is_subclass_of($className, "diogodg\\neoorm\\abstract\\model");
   }

   /**
    * Realiza a migração e execução de seeds para uma tabela
    *
    * @param object $tableInstance
    * @param string $className
    * @param bool $recreate
    * @return void
    */
   private static function migrateTable(object $tableInstance, string $className, bool $recreate): void
   {
      echo "Migrando " . $tableInstance->getTable() . PHP_EOL;
      $tableInstance->execute($recreate);

      if (method_exists($className, "seed")) {
         $className::seed();
      }
   }

   /**
    * Resolve dependências entre tabelas com chaves estrangeiras
    *
    * @param array $tablesWithForeignKeys
    * @param bool $recreate
    * @return void
    */
   private static function resolveTableDependencies(array $tablesWithForeignKeys, bool $recreate): void
   {
      $dependenciesResolved = false;

      while (!$dependenciesResolved) {
         $unresolvedTables = [];

         foreach ($tablesWithForeignKeys as $table) {
            $dependentClasses = $table->getForeignKeyTablesClasses();
            $unresolvedDependencies = [];

            foreach ($dependentClasses as $dependentClass) {
               if (!$dependentClass->exists()) {
                  $unresolvedDependencies[] = $dependentClass;
               } else {
                  $dependentClassName = self::getClassByTableName($dependentClass->getTable());
                  self::migrateTable($dependentClass, $dependentClassName, $recreate);
               }
            }

            if (empty($unresolvedDependencies) && !$table->exists()) {
               $className = self::getClassByTableName($table->getTable());
               self::migrateTable($table, $className, $recreate);
            } else {
               $unresolvedTables = array_merge($unresolvedTables, $unresolvedDependencies);
            }
         }

         $dependenciesResolved = empty($unresolvedTables);
         $tablesWithForeignKeys = $unresolvedTables;
      }
   }

   /**
    * Obtém o nome completo da classe a partir do arquivo
    *
    * @param array $env
    * @param string $tableFile
    * @return string
    */
   private static function getClassNameFromFile(array $env, string $tableFile): string
   {
      return $env["MODEL_NAMESPACE"] . "\\" . str_replace(".php", "", $tableFile);
   }

   /**
    * Obtém a classe correspondente a uma tabela pelo nome
    *
    * @param string $tableName
    * @return string
    */
   private static function getClassByTableName(string $tableName): string
   {
      $env = self::loadEnv();
      $modelNamespace = $env["MODEL_NAMESPACE"];

      // Verifica possíveis variações de nomes
      $possibleClassNames = [
         $modelNamespace . "\\" . ucfirst($tableName),
         $modelNamespace . "\\" . ucwords(str_replace("_", " ", $tableName)),
         $modelNamespace . "\\" . strtolower($tableName),
      ];

      foreach ($possibleClassNames as $className) {
         if (class_exists($className) && defined("$className::table")) {
            if (constant("$className::table") === $tableName) {
               return $className;
            }
         }
      }

      // Procura em todos os arquivos do diretório de modelos
      $tableFiles = scandir($env["PATH_MODEL"]);
      foreach ($tableFiles as $tableFile) {
         $className = $modelNamespace . "\\" . str_replace(".php", "", $tableFile);

         if (self::isValidModelClass($className) && defined("$className::table") && constant("$className::table") === $tableName) {
            return $className;
         }
      }

      throw new \Exception("Classe correspondente à tabela '$tableName' não encontrada.");
   }
}
