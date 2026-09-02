<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;

/**
 * Cache de `TableDefinition` por classe de model.
 *
 * Necessário, não conveniente: `Db::__construct` chama `Model::table()` em TODA
 * instanciação de model. Sem cache, montar o IR de uma tabela com dez colunas
 * aconteceria uma vez por objeto criado, no caminho mais quente do ORM.
 *
 * Não conhece `Migrations\Table` de propósito: exige apenas que o objeto devolvido
 * por `table()` saiba `build()` e que o resultado seja um `TableDefinition`. Assim a
 * dependência continua indo de `Migrations` para `Schema`, nunca ao contrário — e um
 * projeto pode trocar o builder sem trocar isto.
 */
final class SchemaRegistry
{
    /** @var array<string,TableDefinition> */
    private static array $cache = [];

    private function __construct()
    {
    }

    /**
     * @param class-string $modelClass
     */
    public static function for(string $modelClass): TableDefinition
    {
        if (isset(self::$cache[$modelClass])) {
            return self::$cache[$modelClass];
        }

        if (!class_exists($modelClass)) {
            throw new SchemaException("Classe de model inexistente: '{$modelClass}'.");
        }

        if (!method_exists($modelClass, 'table')) {
            throw new SchemaException(
                "O model '{$modelClass}' não tem o método estático table(), então não descreve schema nenhum.",
            );
        }

        /** @var mixed $builder */
        $builder = $modelClass::table();

        if ($builder instanceof TableDefinition) {
            return self::$cache[$modelClass] = $builder;
        }

        if (!is_object($builder) || !method_exists($builder, 'build')) {
            throw new SchemaException(
                "O método {$modelClass}::table() precisa devolver um builder com build() ou um "
                . 'TableDefinition. Recebeu: ' . get_debug_type($builder) . '.',
            );
        }

        /** @var mixed $definition */
        $definition = $builder->build();

        if (!$definition instanceof TableDefinition) {
            throw new SchemaException(
                "O build() do builder de {$modelClass} devolveu " . get_debug_type($definition)
                . ', e não um TableDefinition.',
            );
        }

        return self::$cache[$modelClass] = $definition;
    }

    /**
     * Esvazia o cache, todo ou de uma classe.
     *
     * Existe para os testes: sem isso, um caso que aponta `PATH_MODEL` para outro
     * diretório herdaria as definições do caso anterior — e com ordem de execução
     * aleatória a falha apareceria num teste distante do que a causou.
     *
     * @param class-string|null $modelClass
     */
    public static function flush(?string $modelClass = null): void
    {
        if ($modelClass === null) {
            self::$cache = [];

            return;
        }

        unset(self::$cache[$modelClass]);
    }

    /**
     * @param class-string $modelClass
     */
    public static function has(string $modelClass): bool
    {
        return isset(self::$cache[$modelClass]);
    }
}
