<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use ReflectionClass;

/**
 * Confere que o `@extends Model<XTable>` de cada model aponta para a tabela certa.
 *
 * A anotação é o que dá tipo concreto a `Model::ref()`, e ela é um COMENTÁRIO: nada em
 * tempo de execução a valida. Uma anotação errada — copiar um model e esquecer de trocar
 * a classe é o caso — faz a IDE e o PHPStan concordarem com colunas que não existem, e o
 * erro só aparece no banco. É exatamente a classe de problema que um gate de CI resolve
 * de graça, já que `generate:types` já sabe o mapeamento tabela => classe.
 *
 * Model sem anotação NÃO é erro: `ref()` degrada para `Query\Table` e continua
 * funcionando. Anotação PRESENTE e errada é.
 *
 * A comparação é pelo nome curto da classe. Resolver o FQCN exigiria interpretar os
 * `use` do arquivo, e o erro real que isto pega — `Model<CityTable>` num model de
 * `state` — já aparece inteiro no nome curto.
 */
final class RefAnnotationChecker
{
    public function __construct(private readonly string $generatedNamespace)
    {
    }

    /**
     * @param list<class-string> $modelClasses
     * @return list<string> os problemas encontrados, vazio se estiver tudo certo
     */
    public function check(array $modelClasses): array
    {
        $problems = [];

        foreach ($modelClasses as $class) {
            $problem = $this->checkOne($class);

            if ($problem !== null) {
                $problems[] = $problem;
            }
        }

        return $problems;
    }

    /**
     * @param class-string $class
     */
    private function checkOne(string $class): ?string
    {
        if (!is_subclass_of($class, Model::class)) {
            return null;
        }

        $reflection = new ReflectionClass($class);
        $docComment = $reflection->getDocComment();

        if ($docComment === false) {
            return null;
        }

        $declared = self::extendsArgument($docComment);

        if ($declared === null) {
            return null;
        }

        $expected = Naming::tableClass(SchemaRegistry::for($class)->name);

        if ($declared === $expected) {
            return null;
        }

        return "{$class}: @extends Model<{$declared}> deveria ser @extends Model<{$expected}>"
            . " ({$this->generatedNamespace}\\{$expected}).";
    }

    /**
     * O argumento de `@extends Model<...>`, pelo nome curto.
     *
     * Aceita a forma qualificada (`@extends \Diogodg\Neoorm\Abstract\Model<X>`) porque um
     * docblock não tem obrigação de usar o nome importado.
     */
    private static function extendsArgument(string $docComment): ?string
    {
        if (preg_match('/@extends\s+\\\\?[\w\\\\]*Model\s*<\s*\\\\?([\w\\\\]+)\s*>/', $docComment, $matches) !== 1) {
            return null;
        }

        $argument = $matches[1];
        $position = strrpos($argument, '\\');

        return $position === false ? $argument : substr($argument, $position + 1);
    }
}
