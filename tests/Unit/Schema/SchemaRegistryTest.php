<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use Diogodg\Neoorm\Schema\TableDefinition;
use PHPUnit\Framework\Attributes\Test;
use Tests\App\SchemaModels\Registry\CountsCalls;
use Tests\App\SchemaModels\Registry\HasNoTableMethod;
use Tests\App\SchemaModels\Registry\ReturnsBuilder;
use Tests\App\SchemaModels\Registry\ReturnsDefinition;
use Tests\App\SchemaModels\Registry\ReturnsScalar;
use Tests\App\SchemaModels\Registry\ReturnsWrongBuild;
use Tests\Support\UnitTestCase;

/**
 * O cache de `TableDefinition` por classe de model.
 *
 * Duas coisas se afirmam aqui, e a segunda é a que importa mais: que o cache funciona, e
 * que um `table()` que não cumpre o contrato falha com uma mensagem que diz QUAL classe e
 * o que ela devolveu. A arquitetura antiga descobria isso por `Undefined property` a três
 * camadas de distância.
 */
final class SchemaRegistryTest extends UnitTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SchemaRegistry::flush();
        CountsCalls::$calls = 0;
    }

    protected function tearDown(): void
    {
        SchemaRegistry::flush();
        CountsCalls::$calls = 0;

        parent::tearDown();
    }

    #[Test]
    public function itBuildsTheIrFromTheBuilder(): void
    {
        $definition = SchemaRegistry::for(ReturnsBuilder::class);

        $this->assertInstanceOf(TableDefinition::class, $definition);
        $this->assertSame('returns_builder', $definition->name);
    }

    /**
     * Um `table()` que já devolve IR atravessa sem passar por `build()`.
     */
    #[Test]
    public function itAcceptsAnIrDirectly(): void
    {
        $definition = SchemaRegistry::for(ReturnsDefinition::class);

        $this->assertSame('returns_definition', $definition->name);
    }

    /**
     * O cache é a razão de a classe existir: `Db::__construct` chama `Model::table()` em
     * toda instanciação de model.
     */
    #[Test]
    public function itBuildsEachModelOnlyOnce(): void
    {
        $first = SchemaRegistry::for(CountsCalls::class);
        $second = SchemaRegistry::for(CountsCalls::class);

        $this->assertSame(1, CountsCalls::$calls);
        $this->assertSame($first, $second, 'A segunda chamada tem que devolver o MESMO objeto, não uma cópia.');
    }

    #[Test]
    public function flushDropsTheCache(): void
    {
        SchemaRegistry::for(CountsCalls::class);
        $this->assertTrue(SchemaRegistry::has(CountsCalls::class));

        SchemaRegistry::flush();

        $this->assertFalse(SchemaRegistry::has(CountsCalls::class));

        SchemaRegistry::for(CountsCalls::class);

        $this->assertSame(2, CountsCalls::$calls);
    }

    /**
     * O flush de uma classe só não pode levar as outras.
     *
     * É o que permite a um teste apontar `PATH_MODEL` para outro root sem invalidar o
     * schema de domínio inteiro — e, com ordem de execução aleatória, sem que a falha
     * apareça num teste distante do que a causou.
     */
    #[Test]
    public function flushOfOneClassSparesTheRest(): void
    {
        SchemaRegistry::for(CountsCalls::class);
        SchemaRegistry::for(ReturnsBuilder::class);

        SchemaRegistry::flush(CountsCalls::class);

        $this->assertFalse(SchemaRegistry::has(CountsCalls::class));
        $this->assertTrue(SchemaRegistry::has(ReturnsBuilder::class));
    }

    #[Test]
    public function hasIsFalseBeforeTheFirstBuild(): void
    {
        $this->assertFalse(SchemaRegistry::has(ReturnsBuilder::class));

        SchemaRegistry::for(ReturnsBuilder::class);

        $this->assertTrue(SchemaRegistry::has(ReturnsBuilder::class));
    }

    #[Test]
    public function itRefusesAClassThatDoesNotExist(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Classe de model inexistente/');

        /** @phpstan-ignore-next-line classe inexistente é justamente o caso sob teste */
        SchemaRegistry::for('Tests\\App\\SchemaModels\\Registry\\NaoExiste');
    }

    /**
     * A mensagem cita a classe. Sem isso, num root com trinta models, saber qual deles
     * está errado é trabalho de bisseção.
     */
    #[Test]
    public function itRefusesAClassWithoutTable(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/HasNoTableMethod.+table\(\)/s');

        SchemaRegistry::for(HasNoTableMethod::class);
    }

    #[Test]
    public function itRefusesATableThatReturnsAScalar(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Recebeu: string/');

        SchemaRegistry::for(ReturnsScalar::class);
    }

    #[Test]
    public function itRefusesABuildThatReturnsSomethingElse(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/devolveu string.+TableDefinition/s');

        SchemaRegistry::for(ReturnsWrongBuild::class);
    }

    /**
     * Uma falha não pode deixar entrada no cache.
     *
     * Se deixasse, a segunda chamada devolveria lixo em vez de repetir o erro — e o
     * diagnóstico passaria a depender de quantas vezes o model foi consultado.
     */
    #[Test]
    public function aFailureCachesNothing(): void
    {
        try {
            SchemaRegistry::for(ReturnsScalar::class);
        } catch (SchemaException) {
            // esperado
        }

        $this->assertFalse(SchemaRegistry::has(ReturnsScalar::class));
    }
}
