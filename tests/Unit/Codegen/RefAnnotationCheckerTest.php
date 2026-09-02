<?php

declare(strict_types=1);

namespace Tests\Unit\Codegen;

use Diogodg\Neoorm\Codegen\RefAnnotationChecker;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\App\SchemaModels\Refs\Correct;
use Tests\App\SchemaModels\Refs\Unannotated;
use Tests\App\SchemaModels\Refs\Wrong;
use Tests\App\SchemaModels\Loader\Widget;
use Tests\Support\UnitTestCase;

/**
 * O gate contra anotação de tipo mentirosa.
 *
 * `@extends Model<XTable>` é o que dá tipo concreto a `Model::ref()`, e é um comentário:
 * nada em tempo de execução o contradiz. Copiar um model e esquecer de trocar a classe
 * faz a IDE e o PHPStan concordarem com colunas que não existem, e o erro só aparece no
 * banco. `generate:types --check` já sabe o mapeamento tabela => classe, então fechar
 * isso sai de graça.
 */
final class RefAnnotationCheckerTest extends UnitTestCase
{
    private const ROOT = __DIR__ . '/../../App/SchemaModels';

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

    private function checker(): RefAnnotationChecker
    {
        return new RefAnnotationChecker('Tests\\Generated');
    }

    #[Test]
    public function aCorrectAnnotationPasses(): void
    {
        $this->assertSame([], $this->checker()->check([Correct::class]));
    }

    /** Anotação ausente não é erro: `ref()` degrada para `Query\Table`. */
    #[Test]
    public function aMissingAnnotationIsNotAProblem(): void
    {
        $this->assertSame([], $this->checker()->check([Unannotated::class]));
    }

    #[Test]
    public function aWrongAnnotationIsReportedWithBothClasses(): void
    {
        $problems = $this->checker()->check([Wrong::class]);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Model<CorrectTable>', $problems[0]);
        $this->assertStringContainsString('Model<RefWrongTable>', $problems[0]);
        $this->assertStringContainsString(Wrong::class, $problems[0]);
    }

    /**
     * Uma classe que não herda de `Model` não tem `ref()` e não é conferida — o loader
     * aceita qualquer classe com `table()` estático, e a maioria das fixtures é assim.
     */
    #[Test]
    public function aClassThatDoesNotExtendModelIsSkipped(): void
    {
        $this->assertSame([], $this->checker()->check([Widget::class]));
    }

    /** Rodando sobre o root inteiro, só o model errado aparece. */
    #[Test]
    public function itReportsOnlyTheOffenders(): void
    {
        $classes = (new ModelSchemaLoader(self::ROOT . '/Refs', 'Tests\\App\\SchemaModels\\Refs'))
            ->modelClasses();

        $problems = $this->checker()->check($classes);

        $this->assertCount(1, $problems);
        $this->assertStringContainsString('Refs\\Wrong', $problems[0]);
    }
}
