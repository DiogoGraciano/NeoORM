<?php

declare(strict_types=1);

namespace Tests\Unit\Introspection;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Introspection\PgsqlNormalizer;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\Value\DefaultKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\UnitTestCase;

/**
 * O normalizador do PostgreSQL, exercitado sem banco.
 *
 * Como no lado do MySQL, toda entrada foi COLHIDA de um PostgreSQL 17 real — criando a
 * tabela e lendo `format_type(atttypid, atttypmod)` e `pg_get_expr(adbin, adrelid)`.
 * As formas do catálogo do Postgres são especialmente fáceis de errar de memória: ele
 * anota o default com o tipo, cita número negativo e parentiza aritmética.
 */
final class PgsqlNormalizerTest extends UnitTestCase
{
    /**
     * @return iterable<string,array{string,string,array<string,mixed>}>
     */
    public static function formattedTypes(): iterable
    {
        // Saída de format_type() exatamente como o servidor a devolveu.
        yield 'integer' => ['integer', 'INT', []];
        yield 'bigint' => ['bigint', 'BIGINT', []];
        yield 'boolean' => ['boolean', 'BOOLEAN', []];
        yield 'character varying com tamanho' => ['character varying(120)', 'VARCHAR', ['length' => 120]];
        yield 'character varying sem tamanho' => ['character varying', 'VARCHAR', []];
        yield 'numeric com escala' => ['numeric(10,2)', 'DECIMAL', ['precision' => 10, 'scale' => 2]];
        yield 'double precision' => ['double precision', 'DOUBLE', []];
        yield 'real' => ['real', 'FLOAT', []];
        yield 'text' => ['text', 'TEXT', []];
        yield 'jsonb' => ['jsonb', 'JSONB', []];
        yield 'uuid' => ['uuid', 'UUID', []];
        yield 'date' => ['date', 'DATE', []];
        yield 'timestamp sem fuso' => ['timestamp without time zone', 'TIMESTAMP', []];
        yield 'timestamp com fuso' => ['timestamp with time zone', 'TIMESTAMPTZ', []];
    }

    /**
     * @param array<string,mixed> $expected
     */
    #[Test]
    #[DataProvider('formattedTypes')]
    public function itReadsTheTypeFromFormatType(string $formatted, string $name, array $expected): void
    {
        $type = PgsqlNormalizer::type($formatted);

        $this->assertSame($name, $type->name->value);
        $this->assertSame($expected['length'] ?? null, $type->length);
        $this->assertSame($expected['precision'] ?? null, $type->precision);
        $this->assertSame($expected['scale'] ?? null, $type->scale);
    }

    /**
     * `serial` não existe no catálogo — é `integer` mais um default `nextval`. Quem faz o
     * papel de dizer "é auto incremento" é o `attidentity`/`nextval`, nunca o tipo.
     */
    #[Test]
    public function serialEhIntegerNoCatalogo(): void
    {
        $this->assertSame(TypeName::Int, PgsqlNormalizer::type('integer')->name);
    }

    /**
     * Array não tem representação no IR, e falhar é melhor que mentir.
     *
     * Reportar o tipo base geraria uma migração que troca `integer[]` por `integer` —
     * DDL destrutivo silencioso, a partir de uma leitura que a biblioteca sabia estar
     * incompleta.
     */
    #[Test]
    public function tipoArrayFalhaEmVezDeReportarOTipoBase(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/não é representável no IR/');

        PgsqlNormalizer::type('integer[]');
    }

    /**
     * @return iterable<string,array{string,TypeSpec,DefaultKind,string|int|float|bool|null}>
     */
    public static function defaults(): iterable
    {
        $int = new TypeSpec(TypeName::Int);
        $varchar = new TypeSpec(TypeName::Varchar, length: 40);
        $numeric = new TypeSpec(TypeName::Decimal, precision: 10, scale: 2);

        // Saída de pg_get_expr() exatamente como o servidor a devolveu.
        yield 'booleano' => [
            'true', new TypeSpec(TypeName::Boolean), DefaultKind::Literal, true,
        ];
        yield 'booleano falso' => [
            'false', new TypeSpec(TypeName::Boolean), DefaultKind::Literal, false,
        ];
        yield 'inteiro sem anotação' => ['0', $int, DefaultKind::Literal, 0];
        yield 'numeric zero' => ['0', $numeric, DefaultKind::Literal, 0.0];
        yield 'double' => ['1.5', new TypeSpec(TypeName::Double), DefaultKind::Literal, 1.5];
        yield 'texto anotado' => [
            "'scheduled'::character varying", $varchar, DefaultKind::Literal, 'scheduled',
        ];
        yield 'string vazia anotada' => [
            "''::character varying", $varchar, DefaultKind::Literal, '',
        ];
        yield 'aspa dobrada' => [
            "'d''agua'::character varying", $varchar, DefaultKind::Literal, "d'agua",
        ];
        yield 'jsonb anotado' => [
            "'{}'::jsonb", new TypeSpec(TypeName::Jsonb), DefaultKind::Literal, '{}',
        ];
        yield 'current_timestamp' => [
            'CURRENT_TIMESTAMP', new TypeSpec(TypeName::Timestamp),
            DefaultKind::Expression, 'CURRENT_TIMESTAMP',
        ];
        yield 'now()' => [
            'now()', new TypeSpec(TypeName::TimestampTz),
            DefaultKind::Expression, 'CURRENT_TIMESTAMP',
        ];
        yield 'current_date' => [
            'CURRENT_DATE', new TypeSpec(TypeName::Date),
            DefaultKind::Expression, 'CURRENT_DATE',
        ];
        yield 'função' => [
            'gen_random_uuid()', new TypeSpec(TypeName::Uuid),
            DefaultKind::Expression, 'gen_random_uuid()',
        ];
        // O Postgres parentiza aritmética; `DefaultValue::expression()` tira os
        // parênteses externos na canonicalização.
        yield 'aritmética' => ['(2 + 3)', $int, DefaultKind::Expression, '2 + 3'];
    }

    #[Test]
    #[DataProvider('defaults')]
    public function itReadsTheDefault(
        string $raw,
        TypeSpec $type,
        DefaultKind $kind,
        string|int|float|bool|null $value,
    ): void {
        $default = PgsqlNormalizer::default($raw, $type);

        $this->assertNotNull($default);
        $this->assertSame($kind, $default->kind);
        $this->assertSame($value, $default->value);
    }

    /**
     * Regressão: o Postgres CITA número negativo.
     *
     * Um `INTEGER NOT NULL DEFAULT -42` volta do catálogo como `'-42'::integer`, e um
     * `NUMERIC(10,2) DEFAULT -1.25` como `'-1.25'::numeric` — os dois entre aspas, como
     * um texto qualquer. Tratar "está entre aspas" como "é texto" fazia o default virar a
     * string `-42`, e string contra inteiro é divergência de KIND: a coluna geraria
     * migração em toda execução, para sempre.
     *
     * A ordem é que conserta isso: desaspar primeiro, decidir o tipo depois.
     */
    #[Test]
    public function negativoCitadoContinuaNumero(): void
    {
        $inteiro = PgsqlNormalizer::default("'-42'::integer", new TypeSpec(TypeName::Int));
        $decimal = PgsqlNormalizer::default("'-1.25'::numeric", new TypeSpec(TypeName::Decimal, precision: 10, scale: 2));

        $this->assertNotNull($inteiro);
        $this->assertNotNull($decimal);
        $this->assertSame(DefaultKind::Literal, $inteiro->kind);
        $this->assertSame(-42, $inteiro->value);
        $this->assertSame(-1.25, $decimal->value);
    }

    /**
     * E o Postgres também parentiza negativo em alguns caminhos: `(-42)`.
     */
    #[Test]
    public function negativoParentizadoContinuaNumero(): void
    {
        $default = PgsqlNormalizer::default('(-42)', new TypeSpec(TypeName::Int));

        $this->assertNotNull($default);
        $this->assertSame(DefaultKind::Literal, $default->kind);
        $this->assertSame(-42, $default->value);
    }

    /**
     * O texto `CURRENT_TIMESTAMP` numa coluna de texto é o LITERAL, e aqui é a aspa que
     * faz a distinção: um `VARCHAR(40) DEFAULT 'CURRENT_TIMESTAMP'` volta como
     * `'CURRENT_TIMESTAMP'::character varying`, com aspas, enquanto a expressão volta
     * nua. O MySQL não tem essa sorte — lá a distinção depende do tipo da coluna.
     */
    #[Test]
    public function currentTimestampCitadoEhLiteral(): void
    {
        $default = PgsqlNormalizer::default(
            "'CURRENT_TIMESTAMP'::character varying",
            new TypeSpec(TypeName::Varchar, length: 40),
        );

        $this->assertNotNull($default);
        $this->assertSame(DefaultKind::Literal, $default->kind);
        $this->assertSame('CURRENT_TIMESTAMP', $default->value);
    }

    /**
     * `nextval` devolve `null`, que quem chama lê como "é auto incremento, e não tem
     * default".
     *
     * É o que faz uma coluna `serial` — no catálogo, `integer` mais um default `nextval` —
     * fechar o round-trip contra uma coluna declarada com auto incremento. Se o default
     * sobrevivesse, o schema declarado divergiria do banco para sempre.
     */
    #[Test]
    public function nextvalNaoEhDefault(): void
    {
        $this->assertNull(PgsqlNormalizer::default(
            "nextval('zz_probe_ser_seq'::regclass)",
            new TypeSpec(TypeName::Int),
        ));
    }

    #[Test]
    public function ausenciaDeDefaultEhNone(): void
    {
        $default = PgsqlNormalizer::default(null, new TypeSpec(TypeName::Text));

        $this->assertNotNull($default);
        $this->assertTrue($default->isNone());
        $this->assertFalse($default->isNull());
    }

    #[Test]
    public function btreeNaoEhReportado(): void
    {
        $this->assertNull(PgsqlNormalizer::indexMethod('btree'));
        $this->assertNull(PgsqlNormalizer::indexMethod(' BTREE '));
        $this->assertSame('GIN', PgsqlNormalizer::indexMethod('gin'));
        $this->assertSame('HASH', PgsqlNormalizer::indexMethod('hash'));
    }

    /**
     * `pg_get_constraintdef` devolve `CHECK ((price >= 0))`; o IR guarda a expressão.
     */
    #[Test]
    public function aExpressaoSaiDeDentroDoCheck(): void
    {
        $this->assertSame('(price >= 0)', PgsqlNormalizer::checkExpression('CHECK ((price >= 0))'));
        $this->assertSame('(price >= 0)', PgsqlNormalizer::checkExpression('CHECK((price >= 0))'));
    }

    /**
     * Uma expressão que não venha embrulhada em CHECK atravessa intacta, em vez de
     * perder o primeiro e o último caractere por um recorte cego.
     */
    #[Test]
    public function expressaoSemOEnvelopeAtravessaIntacta(): void
    {
        $this->assertSame('price >= 0', PgsqlNormalizer::checkExpression(' price >= 0 '));
    }

    /**
     * `conkey` vem como um array literal do Postgres, `{1,3}`, e o que interessa são os
     * nomes na ordem em que a constraint os declara.
     */
    #[Test]
    public function conkeyViraListaDeNomes(): void
    {
        $byAttnum = [1 => 'id', 2 => 'name', 3 => 'state'];

        $this->assertSame(['id', 'state'], PgsqlNormalizer::columnList('{1,3}', $byAttnum));
        $this->assertSame(['state', 'id'], PgsqlNormalizer::columnList('{3,1}', $byAttnum));
        $this->assertSame(['name'], PgsqlNormalizer::columnList('{2}', $byAttnum));
    }

    /**
     * Uma coluna descartada (`attisdropped`) não aparece no mapa de attnum. Devolver um
     * nome vazio no lugar dela produziria uma constraint sobre uma coluna sem nome.
     */
    #[Test]
    public function attnumDesconhecidoEhOmitido(): void
    {
        $this->assertSame(['id'], PgsqlNormalizer::columnList('{1,99}', [1 => 'id']));
        $this->assertSame([], PgsqlNormalizer::columnList('{}', [1 => 'id']));
        $this->assertSame([], PgsqlNormalizer::columnList(null, [1 => 'id']));
    }
}
