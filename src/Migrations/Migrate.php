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
    * Atenção: em MySQL, comandos DDL provocam commit implícito, então a transação
    * aberta aqui só protege de fato os seeds e os bancos que suportam DDL
    * transacional (PostgreSQL). Uma migração interrompida em MySQL pode deixar o
    * schema parcialmente aplicado.
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

         // Cria as tabelas de rastreamento do schema antes de qualquer migração
         $this->createSchemaTrackingTables();

         connection::beginTransaction();

         $pathModel = Config::getPathModel();

         if (!$pathModel || !is_dir($pathModel)) {
            throw new Exception("Diretório de models não encontrado: '{$pathModel}'. Configure PATH_MODEL no .env.");
         }

         $tableFiles = scandir($pathModel);

         if ($tableFiles === false) {
            throw new Exception("Não foi possível ler o diretório de models: {$pathModel}");
         }

         // Ordem estável para que a migração seja reprodutível entre ambientes
         sort($tableFiles, SORT_STRING);

         $allTableInstances = [];

         foreach ($tableFiles as $tableFile) {

            if ($tableFile === '.' || $tableFile === '..' || !str_ends_with(strtolower($tableFile), '.php')) {
               continue;
            }

            $className = $this->getClassNameFromFile($tableFile);

            if ($this->isValidModelClass($className)) {
               $tableInstance = $className::table();
               if (!$tableInstance->exists()) {
                  $tableInstance->create();
                  echo "Criando " . $tableInstance->getTable() . PHP_EOL;
               } else {
                  $tableInstance->update();
                  echo "Atualizando " . $tableInstance->getTable() . PHP_EOL;
               }

               $allTableInstances[] = $tableInstance;

               if (method_exists($className, "seed")) {
                  $className::seed();
               }
            }
         }

         // FKs são aplicadas depois de todas as tabelas existirem, e valem tanto
         // para as criadas agora quanto para as que já existiam.
         foreach ($allTableInstances as $instance) {
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

   /**
    * Cria as tabelas de rastreamento do schema
    */
   private function createSchemaTrackingTables(): void
   {
      try {
         echo "Criando tabelas de rastreamento do schema..." . PHP_EOL;
         
         $schemaTracker = new SchemaTracker();
         $schemaTracker->createTrackingTables();
         
         echo "Tabelas de rastreamento criadas com sucesso!" . PHP_EOL;
      } catch (\Exception $e) {
         echo "Erro ao criar tabelas de rastreamento: " . $e->getMessage() . PHP_EOL;
         throw new Exception("Falha ao criar tabelas de rastreamento do schema: " . $e->getMessage());
      }
   }

   public function recreateDatabase()
   {
      // O banco não pode ser removido enquanto houver sessão aberta nele.
      Connection::close();

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

      $database = $this->quoteDatabaseName(Config::getDbName());

      try {
         $pdo->exec("DROP DATABASE IF EXISTS " . $database);
         $pdo->exec("CREATE DATABASE " . $database);
      } catch (\PDOException $e) {
         throw new Exception("Erro ao recriar o banco de dados: " . $e->getMessage(), 0, $e);
      }
   }

   /**
    * Valida e delimita o nome do banco, que não pode ser parametrizado pelo PDO.
    */
   private function quoteDatabaseName(string $name): string
   {
      if (!preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
         throw new Exception("Nome de banco de dados inválido: {$name}");
      }

      return Config::getDriver() === 'mysql' ? "`{$name}`" : "\"{$name}\"";
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