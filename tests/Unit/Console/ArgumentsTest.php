<?php

declare(strict_types=1);

namespace Tests\Unit\Console;

use Diogodg\Neoorm\Console\Arguments;
use Tests\Support\UnitTestCase;

/**
 * O parsing da linha de comando.
 *
 * Vale um teste próprio porque o modo de falha aqui é silencioso: uma opção não lida é
 * uma opção IGNORADA, e `--allow-destructive` ignorado significa uma migração que apaga
 * dados sendo escrita sem a confirmação que o usuário achou que tinha dado.
 */
final class ArgumentsTest extends UnitTestCase
{
    /**
     * O `getopt()` para no primeiro argumento que não é opção, e o nome do subcomando é
     * exatamente isso — então ele devolveria opção nenhuma para esta linha.
     */
    public function testOptionsAfterTheCommandNameAreRead(): void
    {
        $arguments = Arguments::parse(['neoorm', 'generate:types', '--check', '--dry-run']);

        $this->assertSame('generate:types', $arguments->command);
        $this->assertTrue($arguments->has('check'));
        $this->assertTrue($arguments->has('dry-run'));
    }

    public function testAnEmptyLineHasNoCommand(): void
    {
        $this->assertNull(Arguments::parse(['neoorm'])->command);
    }

    public function testValuedOptions(): void
    {
        $arguments = Arguments::parse(['neoorm', 'migration:generate', '--name=cria_usuarios', '--step=3']);

        $this->assertSame('cria_usuarios', $arguments->value('name'));
        $this->assertSame(3, $arguments->integer('step'));
    }

    public function testAValueWithAnEqualsSignInsideItSurvives(): void
    {
        $arguments = Arguments::parse(['neoorm', 'migration:pull', '--write-to=/tmp/a=b.json']);

        $this->assertSame('/tmp/a=b.json', $arguments->value('write-to'));
    }

    public function testAFlagHasNoValueAndDoesNotBecomeOne(): void
    {
        $arguments = Arguments::parse(['neoorm', 'migration:up', '--dry-run']);

        $this->assertTrue($arguments->has('dry-run'));
        $this->assertNull($arguments->value('dry-run'));
    }

    public function testANonNumericStepIsNotAnInteger(): void
    {
        $arguments = Arguments::parse(['neoorm', 'migration:up', '--step=todas']);

        $this->assertNull($arguments->integer('step'));
    }

    /**
     * `--rename` aparece uma vez por renomeação, e todas precisam chegar ao resolvedor:
     * ficar só com a última faria a migração apagar a coluna cuja renomeação se perdeu.
     */
    public function testARepeatableOptionKeepsEveryOccurrence(): void
    {
        $arguments = Arguments::parse([
            'neoorm', 'migration:generate', '--rename=city:town', '--rename=city.name:title',
        ]);

        $this->assertSame(['city:town', 'city.name:title'], $arguments->all('rename'));
    }

    public function testPositionalsKeepTheirOrderAfterTheCommand(): void
    {
        $arguments = Arguments::parse(['neoorm', 'migration:up', 'um', 'dois']);

        $this->assertSame('migration:up', $arguments->command);
        $this->assertSame(['um', 'dois'], $arguments->positionals);
    }

    /**
     * Um `--dry-runn` digitado errado tem que falhar, não aplicar a migração de verdade.
     */
    public function testUnknownOptionsAreReported(): void
    {
        $arguments = Arguments::parse(['neoorm', 'migration:up', '--dry-runn', '--to=0003']);

        $this->assertSame(['dry-runn'], $arguments->unknown(['to', 'step', 'dry-run']));
        $this->assertSame([], $arguments->unknown(['to', 'step', 'dry-run', 'dry-runn']));
    }
}
