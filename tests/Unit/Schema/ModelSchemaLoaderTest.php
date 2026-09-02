<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use Diogodg\Neoorm\Migrations\Diff\DependencyGraph;
use Diogodg\Neoorm\Schema\Exception\SchemaException;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PHPUnit\Framework\Attributes\Test;
use Tests\App\SchemaModels\Loader\Gadget;
use Tests\App\SchemaModels\Loader\Widget;
use Tests\Support\UnitTestCase;

/**
 * A única porta de I/O de `Schema\` — e é I/O de diretório, não de banco.
 *
 * O loader recebe caminho e namespace por construtor e nunca consulta `Config`. Isso é o
 * que permite a estes testes apontarem para um root próprio: `PATH_MODEL` é varrido por
 * INTEIRO, então um fixture posto em `tests/App/Models/` para exercitar o loader entraria
 * também no schema dos testes de ORM e passaria a ser criado no banco.
 */
final class ModelSchemaLoaderTest extends UnitTestCase
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

    private function loader(string $subdirectory): ModelSchemaLoader
    {
        return new ModelSchemaLoader(
            self::ROOT . '/' . $subdirectory,
            'Tests\\App\\SchemaModels\\' . $subdirectory,
        );
    }

    #[Test]
    public function itLoadsEveryModelInTheDirectory(): void
    {
        $schema = $this->loader('Loader')->load();

        $this->assertSame(['gadget', 'widget'], $schema->tableNames());
    }

    /**
     * O que NÃO é model é ignorado em silêncio: um enum, uma classe base abstrata, um
     * `README.md`. Um arquivo em `PATH_MODEL` que não descreve tabela não é erro — é
     * rotina.
     */
    #[Test]
    public function itIgnoresWhatIsNotAModel(): void
    {
        $classes = $this->loader('Loader')->modelClasses();

        $this->assertSame([Gadget::class, Widget::class], $classes);
    }

    /**
     * A ordem de leitura é alfabética e determinística, o que importa para mensagens de
     * erro reproduzíveis — e é irrelevante para o resultado.
     *
     * `Gadget` depende de `Widget` e vem antes dele no diretório. Se a ordem de criação
     * saísse do nome do arquivo, como no sistema antigo, a FK apontaria para uma tabela
     * que ainda não existe. Ela sai do grafo.
     */
    #[Test]
    public function theDependencyOrderComesFromTheGraphNotTheFilename(): void
    {
        $schema = $this->loader('Loader')->load();

        $this->assertSame(
            ['widget', 'gadget'],
            DependencyGraph::fromSchema($schema)->sort(),
            'Widget tem que ser criada antes de Gadget, apesar de vir depois em ordem alfabética.',
        );
    }

    #[Test]
    public function itRefusesADirectoryThatDoesNotExist(): void
    {
        $loader = new ModelSchemaLoader(self::ROOT . '/NaoExiste', 'Tests\\App\\SchemaModels\\NaoExiste');

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/Diretório de models não encontrado/');

        $loader->load();
    }

    /**
     * A mensagem de diretório ausente cita PATH_MODEL e a regra de resolução.
     *
     * Não é enfeite: o erro real que os usuários encontram é rodar `php neof` de um
     * subdiretório, e sem essa frase a mensagem parece dizer que o diretório não existe
     * quando ele existe — só não a partir de onde se olhou.
     */
    #[Test]
    public function theMissingDirectoryMessageExplainsPathResolution(): void
    {
        $loader = new ModelSchemaLoader('./App/Models', 'App\\Models');

        try {
            $loader->modelClasses();
            $this->fail('Esperava SchemaException.');
        } catch (SchemaException $e) {
            $this->assertStringContainsString('PATH_MODEL', $e->getMessage());
            $this->assertStringContainsString('raiz do projeto', $e->getMessage());
        }
    }

    /**
     * Barra no fim do caminho e barra no começo do namespace não podem virar dois
     * separadores nem um nome de classe inválido.
     */
    #[Test]
    public function itToleratesTrailingSeparators(): void
    {
        $loader = new ModelSchemaLoader(
            self::ROOT . '/Loader/',
            '\\Tests\\App\\SchemaModels\\Loader\\',
        );

        $this->assertSame([Gadget::class, Widget::class], $loader->modelClasses());
    }

    /**
     * `loadValidated()` reúne TODOS os problemas numa exceção só.
     *
     * O builder antigo lançava de dentro do construtor de `Column`, um problema por
     * execução, e cada correção revelava o seguinte: corrigir cinco erros exigia cinco
     * rodadas de tentativa.
     */
    #[Test]
    public function validationReportsEveryProblemAtOnce(): void
    {
        try {
            $this->loader('Invalid')->loadValidated('mysql');
            $this->fail('Esperava SchemaException.');
        } catch (SchemaException $e) {
            $message = $e->getMessage();

            $this->assertStringContainsString("'nao_existe'", $message);
            $this->assertStringContainsString("'fantasma'", $message);
            $this->assertStringContainsString('DEFAULT NULL', $message);
            $this->assertStringContainsString('mysql', $message);
        }
    }

    /**
     * O mesmo diretório de models tem duas respostas, uma por dialeto.
     *
     * `UNSIGNED` e `DATETIME` não existem no PostgreSQL. Renderizá-los como o mais
     * próximo e seguir adiante — o que o gerador antigo fazia — cria uma coluna que a
     * introspecção traz de volta diferente do que o model declara, ou seja, uma
     * divergência eterna que migração nenhuma resolve. O validador recusa a declaração.
     */
    #[Test]
    public function validationIsDialectAware(): void
    {
        $loader = $this->loader('DialectSpecific');

        $mysql = $loader->loadValidated('mysql');
        $this->assertSame(['counter'], $mysql->tableNames());

        SchemaRegistry::flush();

        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/pgsql/');

        $loader->loadValidated('pgsql');
    }

    /**
     * Um schema válido atravessa `loadValidated()` intacto.
     */
    #[Test]
    public function aValidSchemaPassesThrough(): void
    {
        $schema = $this->loader('Loader')->loadValidated('pgsql');

        $this->assertSame(['gadget', 'widget'], $schema->tableNames());
    }

    // ------------------------------------------------------------- recursão

    /**
     * Subpasta vira segmento de namespace, em qualquer profundidade.
     *
     * A varredura era plana, e o efeito não era erro e sim silêncio: um model em
     * `App/Models/Billing/Invoice.php` não existia para o schema, então a migração
     * seguinte propunha dropar a tabela dele.
     */
    #[Test]
    public function itDescendsIntoSubdirectories(): void
    {
        $classes = $this->loader('Recursive')->modelClasses();

        $this->assertSame(
            [
                'Tests\\App\\SchemaModels\\Recursive\\Billing\\Deep\\Line',
                'Tests\\App\\SchemaModels\\Recursive\\Billing\\Invoice',
                'Tests\\App\\SchemaModels\\Recursive\\Root',
            ],
            $classes,
        );
    }

    /**
     * `Generated/` é pulado por NOME de diretório.
     *
     * `PATH_GENERATED` fica sob `PATH_MODEL` por padrão, então a recursão passaria por
     * ali. O fixture `Generated\Decoy` tem `table()` e portanto passaria pelo filtro de
     * capacidade — a exclusão precisa ser do diretório, não depender da forma do código
     * gerado de hoje.
     */
    #[Test]
    public function itSkipsTheGeneratedDirectory(): void
    {
        $tables = $this->loader('Recursive')->load()->tableNames();

        $this->assertNotContains('rec_decoy', $tables);
        $this->assertSame(['rec_invoice', 'rec_line', 'rec_root'], $tables);
    }

    /** A lista de diretórios pulados é injetável, e sem ela o chamariz aparece. */
    #[Test]
    public function theSkipListIsInjectable(): void
    {
        $loader = new ModelSchemaLoader(
            self::ROOT . '/Recursive',
            'Tests\\App\\SchemaModels\\Recursive',
            skipDirectories: [],
        );

        $this->assertContains('rec_decoy', $loader->load()->tableNames());
    }

    /** A ordem é alfabética por caminho relativo, e portanto reproduzível. */
    #[Test]
    public function nestedDiscoveryIsDeterministic(): void
    {
        $loader = $this->loader('Recursive');

        $this->assertSame($loader->modelClasses(), $loader->modelClasses());
    }
}
