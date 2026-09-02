<?php

declare(strict_types=1);

namespace Tests\Unit\Runtime;

use DateTimeImmutable;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Runtime\Casting\Binder;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use PDO;
use stdClass;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\DialectProviders;
use Tests\Support\UnitTestCase;

/**
 * O caminho de escrita, que é ciente de dialeto — ao contrário do de leitura.
 *
 * A assimetria tem motivo: `Cast` roda dentro de um DTO gerado, que não pode carregar
 * configuração de banco, então é total e neutro. O `Binder` roda onde já existe conexão,
 * e ali o dialeto sai de graça.
 */
final class BinderTest extends UnitTestCase
{
    use DialectProviders;

    private function binder(string $dialect): Binder
    {
        return new Binder(DialectFactory::for($dialect));
    }

    /**
     * A divergência que justifica o Binder existir.
     *
     * O PostgreSQL tem `boolean` de verdade e quer `PARAM_BOOL`; o MySQL guarda
     * `TINYINT(1)` e precisa de 0 ou 1 como inteiro. Mandar `PARAM_BOOL` ao MySQL com
     * `ATTR_EMULATE_PREPARES` desligado grava string vazia para `false` — a linha entra,
     * sem erro, com o valor errado.
     */
    public function testBooleansTakeTheShapeEachEngineExpects(): void
    {
        $this->assertSame([1, PDO::PARAM_INT], $this->binder('mysql')->bind(true));
        $this->assertSame([0, PDO::PARAM_INT], $this->binder('mysql')->bind(false));

        $this->assertSame([true, PDO::PARAM_BOOL], $this->binder('pgsql')->bind(true));
        $this->assertSame([false, PDO::PARAM_BOOL], $this->binder('pgsql')->bind(false));
    }

    #[DataProvider('dialects')]
    public function testNullIsBoundAsNull(string $dialect): void
    {
        $this->assertSame([null, PDO::PARAM_NULL], $this->binder($dialect)->bind(null));
    }

    /**
     * Com a emulação de prepared statements desligada, o servidor leva o tipo a sério:
     * inteiro precisa chegar como inteiro.
     */
    #[DataProvider('dialects')]
    public function testIntegersKeepTheirType(string $dialect): void
    {
        $this->assertSame([42, PDO::PARAM_INT], $this->binder($dialect)->bind(42));
    }

    /**
     * Não existe `PARAM_FLOAT` no PDO. String preserva a representação e deixa a
     * conversão com o driver, evitando que o arredondamento aconteça duas vezes.
     */
    #[DataProvider('dialects')]
    public function testFloatsGoAsTextToPreserveTheirRepresentation(string $dialect): void
    {
        [$value, $type] = $this->binder($dialect)->bind(1.5);

        $this->assertSame('1.5', $value);
        $this->assertSame(PDO::PARAM_STR, $type);
    }

    #[DataProvider('dialects')]
    public function testBinaryColumnsUseTheLobParameter(string $dialect): void
    {
        $binder = $this->binder($dialect);

        $this->assertSame(
            [self::BINARY, PDO::PARAM_LOB],
            $binder->bind(self::BINARY, new TypeSpec(TypeName::Bytea)),
        );

        // A mesma string numa coluna textual continua sendo PARAM_STR: o que decide é o
        // tipo da coluna, não o conteúdo do valor.
        $this->assertSame(
            [self::BINARY, PDO::PARAM_STR],
            $binder->bind(self::BINARY, new TypeSpec(TypeName::Text)),
        );
    }

    private const BINARY = "\x00\x01dados";

    /**
     * O formato sai do tipo da coluna, não do valor.
     *
     * Gravar `'2026-08-08 00:00:00'` numa coluna `DATE` funciona; gravar só
     * `'2026-08-08'` numa `DATETIME` zera a hora em silêncio.
     */
    #[DataProvider('temporalFormats')]
    public function testTemporalFormatComesFromTheColumnType(?TypeName $name, string $expected): void
    {
        $date = new DateTimeImmutable('2026-08-08 14:30:00-03:00');
        $type = $name === null ? null : new TypeSpec($name);

        $this->assertSame([$expected, PDO::PARAM_STR], $this->binder('pgsql')->bind($date, $type));
    }

    /**
     * @return iterable<string,array{TypeName|null,string}>
     */
    public static function temporalFormats(): iterable
    {
        yield 'date' => [TypeName::Date, '2026-08-08'];
        yield 'time' => [TypeName::Time, '14:30:00'];
        yield 'datetime' => [TypeName::DateTime, '2026-08-08 17:30:00'];
        yield 'timestamp' => [TypeName::Timestamp, '2026-08-08 17:30:00'];
        yield 'timestamptz' => [TypeName::TimestampTz, '2026-08-08 17:30:00+00:00'];
        // Sem o tipo, o palpite seguro é instante completo em UTC.
        yield 'sem tipo' => [null, '2026-08-08 17:30:00'];
    }

    #[DataProvider('dialects')]
    public function testABackedEnumIsBoundAsItsValue(string $dialect): void
    {
        $this->assertSame(['ativo', PDO::PARAM_STR], $this->binder($dialect)->bind(BinderStatus::Ativo));
    }

    #[DataProvider('dialects')]
    public function testArraysAreSerializedAsJson(string $dialect): void
    {
        [$value, $type] = $this->binder($dialect)->bind(['a' => 1, 'b' => 'ção']);

        $this->assertSame('{"a":1,"b":"ção"}', $value);
        $this->assertSame(PDO::PARAM_STR, $type);
    }

    #[DataProvider('dialects')]
    public function testEveryNonNullJsonValueIsSerializedAsAJsonDocument(string $dialect): void
    {
        $binder = $this->binder($dialect);
        $type = new TypeSpec(TypeName::Json);
        $object = new stdClass();
        $object->name = 'Neo';

        $this->assertSame(['"texto"', PDO::PARAM_STR], $binder->bind('texto', $type));
        $this->assertSame(['42', PDO::PARAM_STR], $binder->bind(42, $type));
        $this->assertSame(['true', PDO::PARAM_STR], $binder->bind(true, $type));
        $this->assertSame(['{"name":"Neo"}', PDO::PARAM_STR], $binder->bind($object, $type));
    }

    #[DataProvider('dialects')]
    public function testNullInAJsonColumnStillMeansSqlNull(string $dialect): void
    {
        $this->assertSame(
            [null, PDO::PARAM_NULL],
            $this->binder($dialect)->bind(null, new TypeSpec(TypeName::Jsonb)),
        );
    }

    #[DataProvider('dialects')]
    public function testAStreamIsDrainedBeforeBinding(string $dialect): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, 'conteúdo');
        rewind($stream);

        $this->assertSame(['conteúdo', PDO::PARAM_LOB], $this->binder($dialect)->bind($stream));

        fclose($stream);
    }

    public function testAStreamContainingZeroIsNotTurnedIntoEmptyText(): void
    {
        $stream = fopen('php://memory', 'r+');
        self::assertIsResource($stream);
        fwrite($stream, '0');
        rewind($stream);

        $this->assertSame(['0', PDO::PARAM_LOB], $this->binder('pgsql')->bind($stream));

        fclose($stream);
    }
}

enum BinderStatus: string
{
    case Ativo = 'ativo';
    case Bloqueado = 'bloqueado';
}
