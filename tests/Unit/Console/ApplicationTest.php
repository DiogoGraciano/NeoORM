<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Diogodg\Neoorm\Console\Application;
use Diogodg\Neoorm\Support\Output\Output;
use Tests\Support\UnitTestCase;

/**
 * O despacho do CLI, na parte que não precisa de banco.
 *
 * Ajuda, comando desconhecido e opção desconhecida são justamente o que um usuário
 * encontra primeiro, e nenhum deles deveria custar uma conexão — o `UnitTestCase`
 * reprova o teste se alguma dessas rotas abrir socket.
 */
final class ApplicationTest extends UnitTestCase
{
    public function testWithoutACommandItPrintsTheUsageAndFails(): void
    {
        $output = new RecordingOutput();

        $this->assertSame(1, (new Application($output))->run(['neoorm']));
        $this->assertStringContainsString('generate:types', $output->text());
        $this->assertStringContainsString('migration:up', $output->text());
    }

    public function testHelpSucceeds(): void
    {
        $output = new RecordingOutput();

        $this->assertSame(0, (new Application($output))->run(['neoorm', 'help']));
        $this->assertStringContainsString('db:reset', $output->text());
    }

    /**
     * Todo comando documentado na ajuda é despachável, e vice-versa. Sem isto, um
     * comando novo entra na lista e nunca aparece na ajuda — ou pior, o contrário.
     */
    public function testEveryDocumentedCommandIsRecognised(): void
    {
        $output = new RecordingOutput();
        (new Application($output))->run(['neoorm', 'help']);

        foreach ([
            'generate:types',
            'migration:generate',
            'migration:up',
            'migration:status',
            'db:check',
            'db:push',
            'db:pull',
            'db:seed',
            'db:reset',
        ] as $command) {
            $this->assertStringContainsString($command, $output->text());

            $erro = new RecordingOutput();
            (new Application($erro))->run(['neoorm', $command, '--nao-existe']);

            $this->assertStringNotContainsString(
                'Comando desconhecido',
                $erro->text(),
                "{$command} está na ajuda mas não é despachado",
            );
        }
    }

    public function testAnUnknownCommandFailsWithoutTouchingTheDatabase(): void
    {
        $output = new RecordingOutput();

        $this->assertSame(1, (new Application($output))->run(['neoorm', 'migration:destruir']));
        $this->assertStringContainsString('Comando desconhecido', $output->text());
    }

    /**
     * A opção desconhecida é recusada ANTES de o comando abrir conexão. É o que impede
     * `migration:up --dry-runn` de aplicar migração de verdade.
     */
    public function testAnUnknownOptionIsRefusedBeforeAnythingRuns(): void
    {
        $output = new RecordingOutput();

        $this->assertSame(1, (new Application($output))->run(['neoorm', 'migration:up', '--dry-runn']));
        $this->assertStringContainsString('Opção desconhecida: --dry-runn', $output->text());
    }

    public function testACommandThatTakesNoOptionsSaysSo(): void
    {
        $output = new RecordingOutput();

        $this->assertSame(1, (new Application($output))->run(['neoorm', 'migration:status', '--verbose']));
        $this->assertStringContainsString('não aceita opções', $output->text());
    }
}

/**
 * Um `Output` que guarda em vez de imprimir.
 *
 * `beStrictAboutOutputDuringTests` está ligado, então um comando que escrevesse no
 * terminal derrubaria a suíte — e é exatamente por isso que a saída é injetável.
 */
final class RecordingOutput implements Output
{
    /** @var list<string> */
    private array $lines = [];

    public function write(string $message): void
    {
        $this->lines[] = $message;
    }

    public function success(string $message): void
    {
        $this->lines[] = $message;
    }

    public function warning(string $message): void
    {
        $this->lines[] = $message;
    }

    public function error(string $message): void
    {
        $this->lines[] = $message;
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }
}
