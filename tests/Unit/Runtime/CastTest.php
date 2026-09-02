<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use DateTimeImmutable;
use DateTimeZone;
use Diogodg\Neoorm\Runtime\Casting\Cast;
use Diogodg\Neoorm\Runtime\Casting\CastException;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnitTestCase;

/**
 * Os casters, contra toda representação de wire que os dois bancos produzem.
 *
 * O ponto do teste é a totalidade: o caster é neutro de dialeto porque aceita o que
 * QUALQUER um dos dois entrega. Se algum caso de wire faltar, o DTO gerado quebra em
 * produção num dos bancos e passa no outro — a falha mais cara de diagnosticar que esta
 * camada pode ter.
 */
final class CastTest extends UnitTestCase
{
    // ---------------------------------------------------------------- inteiro

    #[DataProvider('integerWireValues')]
    public function testIntAcceptsWhatTheDriversReturn(mixed $wire, int $expected): void
    {
        $this->assertSame($expected, Cast::int($wire, 'users.id'));
    }

    /**
     * @return iterable<string,array{mixed,int}>
     */
    public static function integerWireValues(): iterable
    {
        yield 'int nativo' => [42, 42];
        yield 'negativo' => [-7, -7];
        // O driver devolve string quando o valor excede a precisão nativa e em colunas
        // calculadas.
        yield 'string numérica' => ['42', 42];
        yield 'string negativa' => ['-7', -7];
    }

    /**
     * `'1.5'` viraria `1` num cast solto — perda silenciosa, e o tipo de coisa que só
     * aparece quando o total não fecha.
     */
    public function testIntRefusesWhatWouldLoseInformation(): void
    {
        $this->expectException(CastException::class);
        Cast::int('1.5', 'users.id');
    }

    // --------------------------------------------------------------- decimal

    /**
     * A representação do banco é preservada caractere por caractere. Passar por float
     * reintroduziria a perda que o tipo string existe para evitar.
     */
    public function testDecimalPreservesTheExactRepresentation(): void
    {
        $this->assertSame('1234567890123456.7890', Cast::decimal('1234567890123456.7890', 'p.total'));
        $this->assertSame('0.10', Cast::decimal('0.10', 'p.total'));
        $this->assertSame('42', Cast::decimal(42, 'p.total'));
    }

    // -------------------------------------------------------------- booleano

    /**
     * O caster com mais representações, e não por gosto: o mysqlnd devolve `int` porque
     * BOOLEAN é TINYINT(1); o pdo_pgsql devolve `bool` nativo em versão recente e
     * `'t'`/`'f'` em versão antiga.
     */
    #[DataProvider('booleanWireValues')]
    public function testBoolAcceptsEveryRepresentationBothEnginesProduce(mixed $wire, bool $expected): void
    {
        $this->assertSame($expected, Cast::bool($wire, 'users.active'));
    }

    /**
     * @return iterable<string,array{mixed,bool}>
     */
    public static function booleanWireValues(): iterable
    {
        yield 'bool nativo verdadeiro' => [true, true];
        yield 'bool nativo falso' => [false, false];
        yield 'mysql tinyint 1' => [1, true];
        yield 'mysql tinyint 0' => [0, false];
        yield 'string 1' => ['1', true];
        yield 'string 0' => ['0', false];
        yield 'pgsql t' => ['t', true];
        yield 'pgsql f' => ['f', false];
        yield 'true textual' => ['true', true];
        yield 'false textual' => ['false', false];
    }

    public function testBoolRefusesWhatIsNotABoolean(): void
    {
        $this->expectException(CastException::class);
        Cast::bool('talvez', 'users.active');
    }

    // ------------------------------------------------------------------ CHAR

    /**
     * MySQL corta o preenchimento na leitura, PostgreSQL o devolve. Sem o `rtrim`,
     * `CHAR(10)` guardando 'ab' compara igual num banco e diferente no outro.
     */
    public function testCharMakesBothEnginesAgree(): void
    {
        $this->assertSame('ab', Cast::char('ab        ', 'users.code'));
        $this->assertSame('ab', Cast::char('ab', 'users.code'));
        // Espaço à esquerda é dado, não preenchimento.
        $this->assertSame(' ab', Cast::char(' ab  ', 'users.code'));
    }

    // -------------------------------------------------------------- temporais

    public function testDateTimeParsesWhatTheDriversReturn(): void
    {
        $this->assertSame(
            '2026-08-08 14:30:00',
            Cast::dateTime('2026-08-08 14:30:00', 'users.created_at')->format('Y-m-d H:i:s'),
        );

        $this->assertSame(
            '2026-08-08',
            Cast::dateTime('2026-08-08', 'users.birth_date')->format('Y-m-d'),
        );

        $this->assertSame(
            'UTC',
            Cast::dateTime('2026-08-08 14:30:00', 'users.created_at')->getTimezone()->getName(),
        );
    }

    public function testAnAlreadyConvertedDateIsNormalizedToUtc(): void
    {
        $date = new DateTimeImmutable('2026-08-08 14:30:00-03:00');
        $converted = Cast::dateTime($date, 'users.created_at');

        $this->assertSame('UTC', $converted->getTimezone()->getName());
        $this->assertSame('2026-08-08 17:30:00', $converted->format('Y-m-d H:i:s'));
    }

    /**
     * O MySQL fora do modo estrito devolve '0000-00-00 00:00:00'. Um parser permissivo
     * converteria para 1970 — trocando dado ausente por dado errado, que é pior.
     */
    public function testTheMysqlZeroDateThrowsInsteadOfBecomingNineteenSeventy(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessageMatches('/users\.created_at/');
        $this->expectExceptionMessageMatches('/data zero/');

        Cast::dateTime('0000-00-00 00:00:00', 'users.created_at');
    }

    public function testAnInvalidTemporalNamesTheColumn(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessageMatches('/users\.created_at/');

        Cast::dateTime('não é data', 'users.created_at');
    }

    /**
     * Normalizar na leitura é o que faz duas linhas gravadas em fusos diferentes
     * compararem corretamente entre si.
     */
    public function testTimestampTzIsNormalizedToUtc(): void
    {
        $date = Cast::dateTimeTz('2026-08-08 14:30:00-03:00', 'events.at');

        $this->assertSame('UTC', $date->getTimezone()->getName());
        $this->assertSame('2026-08-08 17:30:00', $date->format('Y-m-d H:i:s'));
    }

    // ------------------------------------------------------------------- JSON

    /**
     * `'42'`, `'"texto"'` e `'null'` são documentos JSON válidos e nenhum vira array —
     * é por isso que o tipo é `mixed` e não `array`.
     */
    #[DataProvider('jsonDocuments')]
    public function testJsonDecodesAnyValidDocument(string $raw, mixed $expected): void
    {
        $this->assertSame($expected, Cast::json($raw, 'users.metadata'));
    }

    /**
     * @return iterable<string,array{string,mixed}>
     */
    public static function jsonDocuments(): iterable
    {
        yield 'objeto' => ['{"a":1}', ['a' => 1]];
        yield 'lista' => ['[1,2]', [1, 2]];
        yield 'escalar numérico' => ['42', 42];
        yield 'escalar textual' => ['"texto"', 'texto'];
        yield 'booleano' => ['true', true];
        yield 'nulo' => ['null', null];
    }

    public function testInvalidJsonNamesTheColumn(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessageMatches('/users\.metadata/');

        Cast::json('{quebrado', 'users.metadata');
    }

    // --------------------------------------------------------------- binário

    /**
     * O pdo_pgsql entrega `bytea` como resource de stream. Sem drenar, o DTO receberia
     * `Resource id #7` e a falha apareceria longe da causa.
     */
    public function testBinaryDrainsThePostgresStream(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, "\x00\x01conteúdo");
        rewind($stream);

        $this->assertSame("\x00\x01conteúdo", Cast::binary($stream, 'files.content'));

        fclose($stream);
    }

    public function testBinaryAcceptsThePlainStringMysqlReturns(): void
    {
        $this->assertSame("\x00\x01dados", Cast::binary("\x00\x01dados", 'files.content'));
    }

    // -------------------------------------------------------------- nulidade

    /**
     * A variante nullable existe para não haver ramo de nulabilidade dentro do caster —
     * assim o tipo de retorno é exato dos dois lados, e o gerador escolhe qual chamar.
     */
    public function testEveryNullableVariantPassesNullThrough(): void
    {
        $this->assertNull(Cast::nullableInt(null, 'c'));
        $this->assertNull(Cast::nullableFloat(null, 'c'));
        $this->assertNull(Cast::nullableDecimal(null, 'c'));
        $this->assertNull(Cast::nullableBool(null, 'c'));
        $this->assertNull(Cast::nullableString(null, 'c'));
        $this->assertNull(Cast::nullableChar(null, 'c'));
        $this->assertNull(Cast::nullableDateTime(null, 'c'));
        $this->assertNull(Cast::nullableDateTimeTz(null, 'c'));
        $this->assertNull(Cast::nullableBinary(null, 'c'));
        $this->assertNull(Cast::nullableJson(null, 'c'));
    }

    /**
     * O caster não-nullable recusa null: uma coluna NOT NULL que volta nula significa
     * que a linha veio de um SELECT que não a trouxe — e devolver um DTO meio montado
     * esconderia isso.
     */
    public function testANotNullColumnRefusesNull(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessageMatches('/users\.name/');

        Cast::string(null, 'users.name');
    }

    public function testANotNullJsonColumnRefusesNull(): void
    {
        $this->expectException(CastException::class);
        $this->expectExceptionMessageMatches('/users\.metadata/');

        Cast::json(null, 'users.metadata');
    }

    /**
     * O texto sem offset é lido em UTC mesmo quando o PHP do processo não está em UTC.
     *
     * Este é o único caso que o resto da suíte não consegue provar: o `phpunit.xml` fixa
     * `date.timezone = UTC`, e sob esse default um parser que respeita o fuso do processo
     * e um que impõe UTC dão exatamente o mesmo resultado. O fuso é trocado aqui dentro
     * de propósito — sem isso, remover o `DateTimeZone('UTC')` do caster não quebraria
     * teste nenhum, e a MESMA linha voltaria como instantes diferentes conforme o
     * container que a leu.
     *
     * DATETIME e TIMESTAMP chegam sem offset dos dois bancos, então é este o formato que
     * importa aqui.
     */
    public function testANaiveTemporalIsReadAsUtcRegardlessOfTheProcessTimezone(): void
    {
        $original = date_default_timezone_get();
        date_default_timezone_set('America/Sao_Paulo');

        try {
            $date = Cast::dateTime('2026-08-08 14:30:00', 'users.created_at');

            $this->assertSame('UTC', $date->getTimezone()->getName());
            // O instante, e não só a etiqueta de fuso: lido em São Paulo, o mesmo texto
            // seria três horas antes.
            $this->assertSame(
                (new DateTimeImmutable('2026-08-08 14:30:00', new DateTimeZone('UTC')))->getTimestamp(),
                $date->getTimestamp(),
            );
        } finally {
            date_default_timezone_set($original);
        }
    }
}
