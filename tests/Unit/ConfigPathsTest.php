<?php

declare(strict_types=1);

namespace Tests\Unit;

use Diogodg\Neoorm\Config;
use Tests\Support\UnitTestCase;

/**
 * Caminhos de configuração eram resolvidos contra o diretório de trabalho do
 * processo: `PATH_MODEL=./App/Models` só funcionava rodando o CLI da raiz do
 * projeto, e de um subdiretório a migração morria com "diretório de models não
 * encontrado". Agora tudo é ancorado na raiz do projeto — pré-requisito para
 * escrever arquivos de migração no repositório de quem usa a lib.
 */
final class ConfigPathsTest extends UnitTestCase
{
    /** @var array<string,string|null> */
    private array $previous = [];

    private const KEYS = [
        'PATH_MODEL',
        'MODEL_NAMESPACE',
        'PATH_GENERATED',
        'GENERATED_NAMESPACE',
        'PATH_SEEDS',
        'SEEDER_NAMESPACE',
        'PATH_MIGRATIONS',
        'MIGRATIONS_TABLE',
        'MIGRATIONS_STRICT',
        'DBSCHEMA',
        'DRIVER',
        'NEOORM_ROOT',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (self::KEYS as $key) {
            $this->previous[$key] = $_ENV[$key] ?? null;
            unset($_ENV[$key], $_SERVER[$key]);
        }

        Config::reset();
    }

    protected function tearDown(): void
    {
        foreach ($this->previous as $key => $value) {
            if ($value === null) {
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        Config::setProjectRoot(null);
        Config::reset();

        parent::tearDown();
    }

    public function testProjectRootEndsWithASeparatorAndExists(): void
    {
        $root = Config::getProjectRoot();

        $this->assertStringEndsWith(DIRECTORY_SEPARATOR, $root);
        $this->assertDirectoryExists($root);
    }

    public function testProjectRootIsTheRepositoryWhenRunningTheOwnSuite(): void
    {
        $this->assertSame(
            \dirname(__DIR__, 2) . DIRECTORY_SEPARATOR,
            Config::getProjectRoot(),
        );
    }

    public function testExplicitRootEnvironmentVariableWins(): void
    {
        $_ENV['NEOORM_ROOT'] = '/opt/projeto';
        Config::reset();

        $this->assertSame('/opt/projeto' . DIRECTORY_SEPARATOR, Config::getProjectRoot());
    }

    public function testRelativeModelPathIsAnchoredOnTheProjectRootNotTheWorkingDirectory(): void
    {
        Config::setProjectRoot('/opt/projeto');
        $_ENV['PATH_MODEL'] = './App/Models';

        $this->assertSame('/opt/projeto/App/Models', Config::getPathModel());
    }

    public function testAbsoluteModelPathIsLeftAlone(): void
    {
        Config::setProjectRoot('/opt/projeto');
        $_ENV['PATH_MODEL'] = '/srv/outro/Models';

        $this->assertSame('/srv/outro/Models', Config::getPathModel());
    }

    /**
     * O layout padrão inteiro, sem uma linha de .env.
     *
     * Antes só migrações e seeders tinham padrão: `PATH_MODEL` ausente virava string
     * vazia — que descia até o loader como "diretório '' não encontrado" — e o
     * namespace ausente era remendado por um `?: 'App\Models'` na chamada, um por
     * chamador. Cada caminho tem UM padrão, e ele mora em `Config`.
     */
    public function testEveryPathDefaultsToTheStandardProjectLayout(): void
    {
        Config::setProjectRoot('/opt/projeto');

        $this->assertSame('/opt/projeto/App/Models', Config::getPathModel());
        $this->assertSame('/opt/projeto/App/Models/Generated', Config::getPathGenerated());
        $this->assertSame('/opt/projeto/App/Seeders', Config::getPathSeeds());
        $this->assertSame('/opt/projeto/Migrations', Config::getPathMigrations());

        $this->assertSame('App\\Models', Config::getModelNamespace());
        $this->assertSame('App\\Models\\Generated', Config::getGeneratedNamespace());
        $this->assertSame('App\\Seeders', Config::getSeederNamespace());
    }

    /**
     * Um `.env` copiado do exemplo costuma ter a chave declarada e apagada. Deixar a
     * string vazia passar devolveria a raiz do projeto como diretório de models — e o
     * scan varreria o projeto inteiro, `vendor/` incluído.
     */
    public function testDeclaredButEmptyValuesFallBackToTheDefaultInsteadOfEmptyString(): void
    {
        Config::setProjectRoot('/opt/projeto');

        foreach (['PATH_MODEL' => '', 'MODEL_NAMESPACE' => '   '] as $key => $blank) {
            $_ENV[$key] = $blank;
            $_SERVER[$key] = $blank;
        }

        $this->assertSame('/opt/projeto/App/Models', Config::getPathModel());
        $this->assertSame('App\\Models', Config::getModelNamespace());
    }

    /**
     * Derivados seguem o model quando este muda: sem isso, quem trocasse só o
     * `PATH_MODEL` geraria os DTOs num diretório que nenhum PSR-4 mapeia.
     */
    public function testGeneratedFollowsTheModelPathAndNamespaceUnlessOverridden(): void
    {
        Config::setProjectRoot('/opt/projeto');
        $_ENV['PATH_MODEL'] = './src/Entidades';
        $_ENV['MODEL_NAMESPACE'] = 'Loja\\Entidades';

        $this->assertSame('/opt/projeto/src/Entidades/Generated', Config::getPathGenerated());
        $this->assertSame('Loja\\Entidades\\Generated', Config::getGeneratedNamespace());

        $_ENV['PATH_GENERATED'] = './src/Gerado';
        $_ENV['GENERATED_NAMESPACE'] = '\\Loja\\Gerado\\';

        $this->assertSame('/opt/projeto/src/Gerado', Config::getPathGenerated());
        $this->assertSame('Loja\\Gerado', Config::getGeneratedNamespace());
    }

    public function testSeedPathHonoursTheEnvironmentVariable(): void
    {
        Config::setProjectRoot('/opt/projeto');
        $_ENV['PATH_SEEDS'] = 'db/seeds';

        $this->assertSame('/opt/projeto/db/seeds', Config::getPathSeeds());
    }

    public function testMigrationsPathHonoursTheEnvironmentVariable(): void
    {
        Config::setProjectRoot('/opt/projeto');
        $_ENV['PATH_MIGRATIONS'] = 'db/migracoes';

        $this->assertSame('/opt/projeto/db/migracoes', Config::getPathMigrations());
    }

    public function testMigrationsTableDefaultsToTheReservedName(): void
    {
        $this->assertSame('_neoorm_migrations', Config::getMigrationsTable());
    }

    /**
     * O nome da tabela entra em DDL, onde não existe parâmetro para vincular.
     * Validar na fronteira é o que impede que a configuração vire injeção.
     */
    public function testMigrationsTableRejectsAnythingThatIsNotAPlainIdentifier(): void
    {
        foreach (['minha tabela', 'tab;DROP', '1tabela', 'tab-ela', '"tab"', ''] as $invalid) {
            $_ENV['MIGRATIONS_TABLE'] = $invalid;
            Config::reset();

            try {
                Config::getMigrationsTable();
                $this->fail("MIGRATIONS_TABLE inválido foi aceito: '{$invalid}'");
            } catch (\InvalidArgumentException $e) {
                $this->assertStringContainsString('MIGRATIONS_TABLE', $e->getMessage());
            }
        }
    }

    public function testSchemaDefaultsToPublicOnPostgresAndToNothingOnMysql(): void
    {
        $_ENV['DRIVER'] = 'pgsql';
        Config::reset();
        $this->assertSame('public', Config::getSchema());

        $_ENV['DRIVER'] = 'mysql';
        Config::reset();
        $this->assertSame('', Config::getSchema());
    }

    public function testSchemaHonoursTheEnvironmentVariableOnPostgres(): void
    {
        $_ENV['DRIVER'] = 'pgsql';
        $_ENV['DBSCHEMA'] = 'app';
        Config::reset();

        $this->assertSame('app', Config::getSchema());
    }

    public function testStrictModeIsOnByDefault(): void
    {
        $this->assertTrue(Config::isMigrationsStrict());
    }

    public function testStrictModeAcceptsTheUsualSpellingsOfOff(): void
    {
        foreach (['0', 'false', 'off', 'no', ''] as $off) {
            $_ENV['MIGRATIONS_STRICT'] = $off;
            Config::reset();

            $this->assertFalse(Config::isMigrationsStrict(), "valor: '{$off}'");
        }

        foreach (['1', 'true', 'on', 'yes'] as $on) {
            $_ENV['MIGRATIONS_STRICT'] = $on;
            Config::reset();

            $this->assertTrue(Config::isMigrationsStrict(), "valor: '{$on}'");
        }
    }

    /**
     * Regressão do arranjo com path repository + symlink.
     *
     * Ali o __DIR__ resolve para o repositório real da NeoORM, e uma busca que
     * começasse por ele elegeria a própria biblioteca como "projeto" — sem
     * erro, escrevendo as migrações do usuário dentro do diretório da
     * dependência. A busca a partir do diretório de trabalho tem que vencer.
     */
    public function testDiscoveryStartsFromTheWorkingDirectoryNotFromTheLibraryLocation(): void
    {
        $project = \sys_get_temp_dir() . '/neoprojeto_' . \bin2hex(\random_bytes(6));
        \mkdir($project . '/App', 0777, true);
        \file_put_contents($project . '/composer.json', '{}');

        $previousCwd = \getcwd();
        \chdir($project);

        try {
            Config::setProjectRoot(null);

            $this->assertSame(
                \realpath($project) . DIRECTORY_SEPARATOR,
                Config::getProjectRoot(),
            );

            // E o efeito que de fato importa: onde as migrações seriam gravadas.
            $this->assertSame(
                \realpath($project) . '/Migrations',
                Config::getPathMigrations(),
            );
        } finally {
            if ($previousCwd !== false) {
                \chdir($previousCwd);
            }

            \unlink($project . '/composer.json');
            \rmdir($project . '/App');
            \rmdir($project);
        }
    }

    public function testResetClearsTheMemoizedProjectRoot(): void
    {
        Config::setProjectRoot('/opt/projeto');
        $this->assertSame('/opt/projeto' . DIRECTORY_SEPARATOR, Config::getProjectRoot());

        Config::reset();

        $this->assertNotSame('/opt/projeto' . DIRECTORY_SEPARATOR, Config::getProjectRoot());
    }
}
