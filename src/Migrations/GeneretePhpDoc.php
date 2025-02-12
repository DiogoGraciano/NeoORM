<?php

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Abstract\Model;

class GeneretePhpDoc
{
    public function execute()
    {
        $modelPath = $_ENV["PATH_MODEL"];
        $namespace = $_ENV["MODEL_NAMESPACE"];

        if (!is_dir($modelPath)) {
            throw new \RuntimeException("Diretório de modelos não encontrado: $modelPath");
        }

        $tableFiles = scandir($modelPath);
        
        foreach ($tableFiles as $tableFile) {
            if (!str_ends_with($tableFile, '.php')) {
                continue; // Ignora arquivos que não são PHP
            }

            $className = $this->getClassNameFromFile($tableFile, $namespace);
            $filePath = $modelPath . DIRECTORY_SEPARATOR . $tableFile;

            if (!class_exists($className)) {
                require_once $filePath; // Garante que a classe seja carregada
            }

            if (!class_exists($className)) {
                echo "Classe não encontrada: $className\n";
                continue;
            }

            try {
                $reflectionClass = new \ReflectionClass($className);

                if (!$reflectionClass->isSubclassOf(Model::class)) {
                    continue;
                }

                if (!$reflectionClass->hasMethod('table')) {
                    echo "Classe $className não possui o método table().\n";
                    continue;
                }

                $tableMethod = $reflectionClass->getMethod('table');
                if (!$tableMethod->isStatic()) {
                    echo "Método table() em $className não é estático.\n";
                    continue;
                }

                $tableInstance = $tableMethod->invoke(null);

                $phpDoc = "/**\n";
                foreach ($tableInstance->getColumns() as $column) {
                    $columnName = $column->name;
                    $columnType = match (preg_replace('/\([^)]*\)/', '',strtoupper($column->type))) {
                        'INT','INTEGER' => 'int',
                        'VARCHAR', 'TEXT' => 'string',
                        'DECIMAL', 'FLOAT', 'DOUBLE' => 'float',
                        default => 'mixed'
                    };

                    $phpDoc .= " * @property $columnType \$$columnName {$column->comment}\n";
                }

                $phpDoc .= " * @method self|array get(mixed \$value = \"\", string \$column = \"id\", int \$limit = 1)\n";
                $phpDoc .= " * @method array getAll()\n";
                $phpDoc .= " * @method static void setLastCount(Db \$db)\n";
                $phpDoc .= " * @method static int getLastCount(string \$method)\n";
                $phpDoc .= " * @method bool remove()\n";
                $phpDoc .= " */";

                // Lê o conteúdo original do arquivo
                $codigo = file_get_contents($filePath);

                // Remove PHPDocs antigos, se existirem
                $codigo = preg_replace('/^\/\*\*[\s\S]+?\*\/\n?/m', '', $codigo, 1);

                // Insere o novo PHPDoc antes da namespace
                $codigo = preg_replace('/(final\s+class\s+\w+|abstract\s+class\s+\w+|class\s+\w+)/', "$phpDoc\n$1", $codigo, 1);

                // Salva o arquivo com as anotações atualizadas
                file_put_contents($filePath, $codigo);
                
                echo "PHPDoc atualizado para: $className\n";

            } catch (\Exception $e) {
                echo "Erro ao processar $className: " . $e->getMessage() . "\n";
            }
        }
    }

    /**
     * Obtém o nome completo da classe a partir do arquivo
     *
     * @param string $tableFile
     * @param string $namespace
     * @return string
     */
    private function getClassNameFromFile(string $tableFile, string $namespace): string
    {
        return $namespace . "\\" . str_replace(".php", "", $tableFile);
    }
}