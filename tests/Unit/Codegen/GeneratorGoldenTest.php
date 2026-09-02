<?php

declare(strict_types=1);

namespace Tests\Unit\Codegen;

use Diogodg\Neoorm\Codegen\FileSystemWriter;
use Diogodg\Neoorm\Codegen\Generator;
use Tests\Support\Factory\CodegenSchemas;
use Tests\Support\UnitTestCase;

/**
 * O código gerado, commitado como arquivo PHP de verdade.
 *
 * Não é snapshot de texto: os arquivos são gravados em `tests/Golden/Codegen` com o
 * namespace que o próprio caminho implica, e portanto são carregáveis pelo autoloader
 * PSR-4 dos testes. Isso compra três coisas que um `.txt` não daria — o PHP prova que o
 * arquivo parseia, o analisador estático verifica o código GERADO e não só o gerador, e
 * o diff no code review é o próprio código que o usuário vai ler.
 *
 * A conferência usa o `check()` do writer, o mesmo que roda no CI. Então este teste
 * também é o teste do drift check.
 *
 * Regenerar com `composer test:snapshots`.
 */
final class GeneratorGoldenTest extends UnitTestCase
{
    private const NAMESPACE = 'Tests\\Golden\\Codegen';

    private function directory(): string
    {
        return dirname(__DIR__, 2) . '/Golden/Codegen';
    }

    public function testTheGeneratedCodeMatchesWhatIsCommitted(): void
    {
        $files = (new Generator(self::NAMESPACE))->generate(CodegenSchemas::matrix());
        $writer = new FileSystemWriter($this->directory());

        if (self::shouldUpdate()) {
            $writer->write($files);
            $this->addToAssertionCount(1);

            return;
        }

        $drift = $writer->check($files);

        $this->assertTrue($drift->isClean(), $drift->summary());
    }

    /**
     * Gerar duas vezes tem que dar exatamente a mesma coisa.
     *
     * Sem isso, uma ordenação instável — de tabelas, de colunas, de imports — produziria
     * diff em quem rodasse o gerador em outra máquina, e os arquivos são commitados.
     */
    public function testGenerationIsDeterministic(): void
    {
        $generator = new Generator(self::NAMESPACE);
        $schema = CodegenSchemas::matrix();

        $first = $generator->generate($schema);
        $second = $generator->generate($schema);

        $this->assertEquals($first, $second);
    }

    /**
     * O arquivo gerado é PHP válido — provado por carregá-lo.
     *
     * O autoloader resolve `Tests\Golden\Codegen\UsersTable` para o arquivo commitado,
     * então `class_exists` só devolve true se o PHP conseguiu parsear e compilar.
     */
    public function testTheGeneratedFilesActuallyLoad(): void
    {
        $this->assertTrue(class_exists(self::NAMESPACE . '\\UsersTable'));
        $this->assertTrue(class_exists(self::NAMESPACE . '\\Rows\\UsersRow'));
        $this->assertTrue(class_exists(self::NAMESPACE . '\\Inserts\\UsersInsert'));
        $this->assertTrue(class_exists(self::NAMESPACE . '\\Tables'));
        $this->assertTrue(enum_exists(self::NAMESPACE . '\\Enums\\UsersStatus'));
    }

    private static function shouldUpdate(): bool
    {
        $value = $_ENV['UPDATE_SNAPSHOTS'] ?? $_SERVER['UPDATE_SNAPSHOTS'] ?? getenv('UPDATE_SNAPSHOTS');

        return !in_array(strtolower((string) ($value === false ? '' : $value)), ['', '0', 'false', 'off', 'no'], true);
    }
}
