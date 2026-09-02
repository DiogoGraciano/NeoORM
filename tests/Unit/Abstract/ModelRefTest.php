<?php

declare(strict_types=1);

namespace Tests\Unit\Abstract;

use Diogodg\Neoorm\Config;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;
use Tests\App\Models\State;
use Tests\Generated\StateTable;
use Tests\Support\UnitTestCase;

/**
 * A ponte entre o model e o código gerado.
 *
 * Até aqui o model descrevia a tabela e o `Generated/` a consultava, sem nenhuma ligação
 * declarada: escrever consulta sobre `State` exigia lembrar que o método é
 * `Tables::state()`. `State::ref()` fecha isso.
 *
 * A resolução é pelo NOME da tabela, contra o mapa do `Tables` gerado, e não por um `use`
 * do model para `Generated/`. Se fosse `use`, apagar o diretório faria o model fatalar —
 * e `generate:types` precisa LER os models para recriá-lo.
 */
final class ModelRefTest extends UnitTestCase
{
    /** @var array<string,string|null> */
    private array $previous = [];

    private const KEYS = ['GENERATED_NAMESPACE', 'MODEL_NAMESPACE'];

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

        Config::reset();

        parent::tearDown();
    }

    private function useGeneratedNamespace(string $namespace): void
    {
        $_ENV['GENERATED_NAMESPACE'] = $namespace;
        $_SERVER['GENERATED_NAMESPACE'] = $namespace;

        Config::reset();
    }

    #[Test]
    public function refReturnsTheGeneratedTableForTheModel(): void
    {
        $this->useGeneratedNamespace('Tests\\Generated');

        $this->assertInstanceOf(StateTable::class, State::ref());
    }

    /** O alias atravessa: é o que faz self-join funcionar. */
    #[Test]
    public function refForwardsTheAlias(): void
    {
        $this->useGeneratedNamespace('Tests\\Generated');

        $this->assertSame('vizinho', State::ref('vizinho')->alias);
    }

    /** `ref()` e o método nomeado do registry produzem a mesma coisa. */
    #[Test]
    public function refAgreesWithTheNamedRegistryMethod(): void
    {
        $this->useGeneratedNamespace('Tests\\Generated');

        $this->assertSame(
            \Tests\Generated\Tables::state()->tableName(),
            State::ref()->tableName(),
        );
    }

    /**
     * Sem `Generated/`, a mensagem diz qual comando rodar.
     *
     * É o cenário do clone recém-feito em que alguém esqueceu de gerar: sem esta guarda o
     * erro seria "Class Tables not found", a duas camadas de distância da causa.
     */
    #[Test]
    public function refExplainsWhatToRunWhenTheTypesWereNeverGenerated(): void
    {
        $this->useGeneratedNamespace('Namespace\\Que\\Nao\\Existe');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/generate:types/');

        State::ref();
    }
}
