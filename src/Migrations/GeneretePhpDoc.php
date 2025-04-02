<?php

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Abstract\Model;

class GeneretePhpDoc
{
    public function execute()
    {
        $modelPath = $_ENV["PATH_MODEL"];

        if (!is_dir($modelPath)) {
            throw new \RuntimeException("Model dir not found: $modelPath");
        }

        $tableFiles = scandir($modelPath);

        foreach ($tableFiles as $tableFile) {
    
            if (!str_ends_with($tableFile, '.php')) {
                continue;
            }

            $className = $this->getClassNameFromFile($tableFile);

            $filePath  = $modelPath . DIRECTORY_SEPARATOR . $tableFile;

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

                // Monta o novo bloco de comentários
                $phpDoc = "/**\n";
                foreach ($tableInstance->getColumns() as $column) {
                    $columnName = $column->name;
                    $columnType = match (preg_replace('/\([^)]*\)/', '', strtoupper($column->type))) {
                        'INT', 'INTEGER' => 'int',
                        'VARCHAR', 'TEXT' => 'string',
                        'DECIMAL', 'FLOAT', 'DOUBLE' => 'float',
                        default => 'mixed'
                    };
                    $phpDoc .= " * @property {$columnType} \${$columnName} {$column->comment}\n";
                }

                // Métodos auxiliares
                $phpDoc .= " * @method self|array get(mixed \$value = \"\", string \$column = \"id\", int \$limit = 1)\n";
                $phpDoc .= " * @method array getAll()\n";
                $phpDoc .= " * @method static void setLastCount(Db \$db)\n";
                $phpDoc .= " * @method static int getLastCount(string \$method)\n";
                $phpDoc .= " * @method bool remove()\n";
                $phpDoc .= " */";

                // Lê o conteúdo original do arquivo
                $codigo = file_get_contents($filePath);

                // 1) Remove o bloco de comentários PHPDoc antigo, **sem** remover a quebra de linha seguinte.
                $codigo = preg_replace(
                    '/^\/\*\*[\s\S]+?\*\/\s*/m',
                    '',
                    $codigo,
                    1
                );

                // 2) Injeta o novo PHPDoc imediatamente acima da definição de class (final, abstract ou normal).
                //    Aqui inserimos uma quebra de linha entre " */" e "class".
                $codigo = preg_replace(
                    '/(final\s+class\s+\w+|abstract\s+class\s+\w+|class\s+\w+)/',
                    $phpDoc . "\n$1",
                    $codigo,
                    1
                );

                // 3) Salva o arquivo com as anotações atualizadas
                file_put_contents($filePath, $codigo);

                echo "PHPDoc atualizado para: $className\n";
            } catch (\Exception $e) {
                echo "Erro ao processar $className: " . $e->getMessage() . "\n";
            }
        }
    }

    private function getClassNameFromFile(string $tableFile): string
    {
        return $_ENV["MODEL_NAMESPACE"]."\\".str_replace(".php", "", $tableFile);
    }
}
