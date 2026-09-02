<?php

declare(strict_types=1);

namespace Tests\Support;

use Diogodg\Neoorm\Codegen\GeneratedFile;
use Diogodg\Neoorm\Codegen\Generator;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;

/**
 * Os DTOs dos models de fixture, gerados e **commitados** em `tests/Generated/`.
 *
 * Commitar é o fluxo que a biblioteca recomenda, e a suíte o usa em si mesma: sem os
 * arquivos no disco, `use Tests\Generated\Tables;` não resolveria, os testes voltariam a
 * escrever `$tables::country()` sobre um `class-string`, e nem a IDE nem o PHPStan
 * veriam as colunas — que é exatamente a tipagem que este trabalho existe para entregar.
 *
 * O preço é o par ficar desalinhado quando um model de fixture muda. Quem cobra é o
 * `GeneratedFixturesAreCurrentTest`, com a mesma comparação que o
 * `neoorm generate:types --check` faz em CI.
 *
 * @see \Tests\Unit\Codegen\GeneratedFixturesAreCurrentTest
 */
final class GeneratedTables
{
    public const NAMESPACE = 'Tests\\Generated';

    private function __construct()
    {
    }

    public static function directory(): string
    {
        return dirname(__DIR__) . '/Generated';
    }

    /**
     * Roda o mesmo caminho do `neoorm generate:types`, e sem banco: `ModelSchemaLoader`
     * só lê diretório.
     *
     * O caminho e o namespace vêm daqui, não de `Config`: a suíte unitária roda sem
     * `PATH_MODEL` no ambiente de propósito.
     *
     * @return list<GeneratedFile>
     */
    public static function generate(): array
    {
        $loader = new ModelSchemaLoader(dirname(__DIR__) . '/App/Models', 'Tests\\App\\Models');

        return (new Generator(self::NAMESPACE))->generate($loader->load());
    }
}
