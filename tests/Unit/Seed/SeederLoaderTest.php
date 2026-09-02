<?php

declare(strict_types=1);

namespace Tests\Unit\Seed;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Seed\SeederLoader;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\App\SeederRoots\Valid\InvoiceSeeder;
use Tests\App\SeederRoots\Valid\Nested\LineSeeder;
use Tests\App\SeederRoots\Valid\RootSeeder;
use Tests\Support\UnitTestCase;

/**
 * A descoberta dos seeders, sem banco.
 *
 * O loader espelha o `ModelSchemaLoader` de propósito: caminho e namespace por
 * construtor, nunca `Config`. É o que permite a estes testes apontarem para roots
 * próprios em vez de dependerem do diretório de seeders de verdade.
 */
final class SeederLoaderTest extends UnitTestCase
{
    private const ROOT = __DIR__ . '/../../App/SeederRoots';

    protected function setUp(): void
    {
        parent::setUp();

        SchemaRegistry::flush();
    }

    protected function tearDown(): void
    {
        SchemaRegistry::flush();

        parent::tearDown();
    }

    private function loader(string $subdirectory): SeederLoader
    {
        return new SeederLoader(
            self::ROOT . '/' . $subdirectory,
            'Tests\\App\\SeederRoots\\' . $subdirectory,
        );
    }

    private function schema(): SchemaDefinition
    {
        return (new ModelSchemaLoader(
            __DIR__ . '/../../App/SchemaModels/Recursive',
            'Tests\\App\\SchemaModels\\Recursive',
        ))->load();
    }

    /** Recursivo, como o de models: um projeto grande organiza os seeders por domínio. */
    #[Test]
    public function itFindsSeedersInSubdirectories(): void
    {
        $this->assertSame(
            [
                'rec_invoice' => InvoiceSeeder::class,
                'rec_line' => LineSeeder::class,
                'rec_root' => RootSeeder::class,
            ],
            $this->loader('Valid')->seeders(),
        );
    }

    /**
     * Uma base abstrata entre `Seeder` e os seeders concretos não é um seeder.
     *
     * `AbstractPartial` até declara `table()`, e sem o descarte por reflexão ela roubaria
     * o nome de tabela de alguém.
     */
    #[Test]
    public function itIgnoresAbstractSeeders(): void
    {
        $this->assertNotContains(
            'nunca_deveria_aparecer',
            array_keys($this->loader('Valid')->seeders()),
        );
    }

    /**
     * Diretório ausente não é erro — projeto sem seeder nenhum é o caso comum.
     *
     * Difere do `ModelSchemaLoader`, que lança quando `PATH_MODEL` não existe, e a
     * diferença é intencional: models ausentes são sempre configuração errada, seeders
     * ausentes são o estado normal de um projeto novo.
     */
    #[Test]
    public function aMissingDirectoryIsNotAnError(): void
    {
        $this->assertSame([], $this->loader('NaoExiste')->seeders());
    }

    /**
     * Duas classes para a mesma tabela é ambiguidade sem resposta: o grafo ordena
     * TABELAS, então ele não tem como decidir qual das duas roda primeiro.
     */
    #[Test]
    public function twoSeedersForTheSameTableAreRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/Mais de um seeder para a mesma tabela/');

        $this->loader('Duplicate')->seeders();
    }

    /** E a mensagem nomeia as duas classes, não só a tabela. */
    #[Test]
    public function theDuplicateMessageNamesBothClasses(): void
    {
        try {
            $this->loader('Duplicate')->seeders();
            $this->fail('Esperava MigrationException.');
        } catch (MigrationException $e) {
            $this->assertStringContainsString('FirstRootSeeder', $e->getMessage());
            $this->assertStringContainsString('SecondRootSeeder', $e->getMessage());
            $this->assertStringContainsString("'rec_root'", $e->getMessage());
        }
    }

    /**
     * Seeder apontando para tabela inexistente é o typo silencioso do desenho antigo: o
     * runner percorre as tabelas do schema, então um `table()` errado nunca é alcançado e
     * a única evidência é a linha que não apareceu no banco.
     */
    #[Test]
    public function aSeederForAnUnknownTableIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/tabela_que_nao_existe/');

        $this->loader('Unknown')->seedersFor($this->schema());
    }

    /** Contra o schema certo, os mesmos seeders passam intactos. */
    #[Test]
    public function aValidSetPassesTheSchemaCheck(): void
    {
        $this->assertSame(
            ['rec_invoice', 'rec_line', 'rec_root'],
            array_keys($this->loader('Valid')->seedersFor($this->schema())),
        );
    }

    #[Test]
    public function itExposesWhereItLooked(): void
    {
        $loader = $this->loader('Valid');

        $this->assertStringEndsWith('SeederRoots/Valid', $loader->path());
        $this->assertSame('Tests\\App\\SeederRoots\\Valid', $loader->namespace());
    }
}
