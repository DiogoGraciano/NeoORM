<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen\Emitter;

use Diogodg\Neoorm\Codegen\CodeWriter;
use Diogodg\Neoorm\Codegen\GeneratedFile;
use Diogodg\Neoorm\Codegen\Naming;
use Diogodg\Neoorm\Codegen\Plan\TablePlan;

/**
 * O ponto de entrada: `Tables::users()`.
 *
 * Um import só para o arquivo inteiro, em vez de um por tabela, e zero edição nos
 * arquivos de model.
 *
 * `MAP` e `for()` existem por causa de `Model::ref()`. Um `Model::ref()` genérico não tem
 * como devolver o tipo concreto sozinho — o retorno concreto vem do `@extends Model<X>`
 * anotado no model, e o objeto concreto vem daqui, procurado pelo nome da tabela. A
 * alternativa seria o model fazer `use` de algo em `Generated/`, e aí apagar o diretório
 * impediria o próprio `generate:types` de recriá-lo: o model fatalaria antes de ser lido.
 */
final class RegistryEmitter
{
    /**
     * @param list<TablePlan> $plans
     */
    public function emit(array $plans, string $namespace): GeneratedFile
    {
        $code = CodeWriter::header($namespace, [
            'Diogodg\Neoorm\Query\Table',
            'InvalidArgumentException',
        ]);
        $code .= "\n";
        $code .= CodeWriter::docblock([
            'As tabelas do schema, tipadas.',
            '',
            '```php',
            'use ' . $namespace . '\\Tables;',
            '',
            '$u = Tables::users();',
            '$db->select()->from($u)->where(eq($u->email, $email))->all();',
            '```',
        ]);
        $code .= "final class Tables\n{\n";
        $code .= CodeWriter::docblock(
            [
                'Nome de tabela => classe de referência. Usado por `Model::ref()`.',
                '',
                '@var array<string,class-string<Table<object>>>',
            ],
            '    ',
        );
        $code .= "    public const MAP = [\n";

        foreach ($plans as $plan) {
            $code .= "        '{$plan->table->name}' => {$plan->tableClass}::class,\n";
        }

        $code .= "    ];\n\n";
        $code .= "    private function __construct()\n    {\n    }\n";

        $code .= "\n";
        $code .= CodeWriter::docblock(
            [
                'A referência de uma tabela pelo NOME dela.',
                '',
                'É o caminho de `Model::ref()`, que sabe o nome da tabela (a constante do model)',
                'e não a classe gerada. Quem escreve consulta à mão prefere o método nomeado',
                'abaixo: ele devolve o tipo concreto sem depender de anotação nenhuma.',
                '',
                '@return Table<object>',
            ],
            '    ',
        );
        $code .= "    public static function for(string \$table, ?string \$alias = null): Table\n";
        $code .= "    {\n";
        $code .= "        \$class = self::MAP[strtolower(\$table)] ?? null;\n\n";
        $code .= "        if (\$class === null) {\n";
        $code .= "            throw new InvalidArgumentException(\n";
        $code .= "                \"Nenhuma tabela gerada chamada '{\$table}'. \"\n";
        $code .= "                . 'Se o model é novo, rode: vendor/bin/neoorm generate:types',\n";
        $code .= "            );\n";
        $code .= "        }\n\n";
        $code .= "        return new \$class(\$alias);\n";
        $code .= "    }\n";

        foreach ($plans as $plan) {
            $method = Naming::registryMethod($plan->table->name);

            $code .= "\n    public static function {$method}(?string \$alias = null): {$plan->tableClass}\n";
            $code .= "    {\n        return new {$plan->tableClass}(\$alias);\n    }\n";
        }

        return new GeneratedFile('Tables.php', $code . "}\n");
    }
}
