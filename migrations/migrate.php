<?php

namespace app\db\migrations;

use app\db\transactionManeger;

class migrate{

   public static function execute(bool $recreate){
      try{

         transactionManeger::init();

         transactionManeger::beginTransaction();
         
         $tableFiles = scandir(dirname(dirname(__DIR__)).DIRECTORY_SEPARATOR."models");
         
         $tablesWithForeignKeys = [];
         $allTableInstances = [];
         
         foreach ($tableFiles as $tableFile) {
            $className = 'app\\models\\' . str_replace(".php", "", $tableFile);

            if (class_exists($className) && method_exists($className, "table") && is_subclass_of($className,"app\db\abstract\model")) {

               $tableInstance = $className::table();
               $allTableInstances[] = $className;
               if ($tableInstance->hasForeignKey()) {
                  if (!$tableInstance->exists()) {
                     $tablesWithForeignKeys[] = $tableInstance;
                  } else {
                     echo "Migrando ".$tableInstance->getTable().PHP_EOL.PHP_EOL;
                     $tableInstance->execute($recreate);
                     if(method_exists($className, "seed"))
                        $className::seed();
                  }
               } else {
                  echo "Migrando ".$tableInstance->getTable().PHP_EOL.PHP_EOL;
                  $tableInstance->execute($recreate);
                  if(method_exists($className, "seed"))
                     $className::seed();
               }
            }
         }
         
         if (!empty($tablesWithForeignKeys)) {
            $dependenciesResolved = false;
         
            while (!$dependenciesResolved) {
               $unresolvedTables = self::resolveDependencies($tablesWithForeignKeys,$recreate);
               
               if (empty($unresolvedTables)) {
                  $dependenciesResolved = true;
               } else {
                  $tablesWithForeignKeys = $unresolvedTables;
               }
            }
         }

         transactionManeger::commit();
      }
      catch(\Exception $e){
         transactionManeger::rollBack();
         echo $e->getMessage();
      }
   }

   private static function resolveDependencies(array $tablesWithForeignKeys,bool $recreate = false){
      $resolvedTables = [];
     
      foreach ($tablesWithForeignKeys as $table) {
         
         $dependentClasses = $table->getForeignKeyTablesClasses();
         
         $unresolvedDependencies = [];
         foreach ($dependentClasses as $dependentClass) {
            if (!$dependentClass->exists()) {
               $unresolvedDependencies[] = $dependentClass;
            } else {
               $dependentClass->execute($recreate);
               $className = self::getClassbyTableName($dependentClass->getTable());
               if(method_exists($className, "seed"))
                  $className::seed();
            }
         }
   
         if (empty($unresolvedDependencies) && !$table->exists()) {
            echo "Migrando ".$table->getTable().PHP_EOL.PHP_EOL;
            $table->execute($recreate);
            $className = self::getClassbyTableName($table->getTable());
            if(method_exists($className, "seed"))
               $className::seed();
            $resolvedTables[] = $table;
         } else {
            $resolvedTables = array_merge($resolvedTables, $unresolvedDependencies);
         }
      }
   
      return $resolvedTables;
   }

   private static function getClassbyTableName(string $tableName):string
   {

      $className = 'app\\db\\models';

      $tableNameModified = strtolower(str_replace("_"," ",$tableName));

      if(class_exists($className.$tableNameModified) && property_exists($className.str_replace(" ","",$tableNameModified), "table")){
         return $className.$tableName;
      }
      if(class_exists($className.ucfirst($tableNameModified)) && property_exists($className.str_replace(" ","",ucfirst($tableNameModified)), "table")){
         return $className.ucfirst($tableName);
      }
      if(class_exists($className.ucwords($tableNameModified)) && property_exists($className.str_replace(" ","",ucwords($tableNameModified)), "table")){
         return $className.ucwords($tableName);
      }

      $tableFiles = scandir(dirname(dirname(__DIR__)).DIRECTORY_SEPARATOR."models");
      
      foreach ($tableFiles as $tableFile) {
         $className .= str_replace(".php", "", $tableFile);
      
         if (class_exists($className) && property_exists($className, "table") && $className::table == $tableName) {
            return $className;
         }
      }

      return "";
   }
}

?>