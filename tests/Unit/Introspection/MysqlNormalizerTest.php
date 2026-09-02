<?php

declare(strict_types=1);

namespace Tests\Unit\Introspection;

use Diogodg\Neoorm\Migrations\Introspection\MysqlNormalizer;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Value\DefaultKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use Tests\Support\UnitTestCase;

/**
 * O normalizador do MySQL, exercitado sem banco.
 *
 * Toda entrada deste arquivo foi COLHIDA de um MySQL 8.0 real, criando uma tabela com
 * as colunas correspondentes e lendo `information_schema.COLUMNS`. Isso é o que separa
 * um teste de normalização de um teste de expectativa: inventar a forma do catálogo e
 * depois afirmar que o código a entende prova apenas que o código concorda comigo.
 *
 * O teste vive aqui, e não na suíte de integração, porque é onde ele é útil: o
 * round-trip contra o banco prova que o conjunto todo fecha, mas quando ele quebra não
 * diz qual regra errou. Estes casos dizem.
 */
final class MysqlNormalizerTest extends UnitTestCase
{
    /**
     * @return iterable<string,array{string,string,array<string,mixed>}>
     */
    public static function columnTypes(): iterable
    {
        // COLUMN_TYPE exatamente como o servidor o devolveu.
        yield 'int' => ['int', 'INT', []];
        yield 'bigint' => ['bigint', 'BIGINT', []];
        yield 'smallint unsigned' => ['smallint unsigned', 'SMALLINT', ['unsigned' => true]];
        yield 'varchar com tamanho' => ['varchar(120)', 'VARCHAR', ['length' => 120]];
        yield 'char com tamanho' => ['char(2)', 'CHAR', ['length' => 2]];
        yield 'decimal com escala' => ['decimal(10,2)', 'DECIMAL', ['precision' => 10, 'scale' => 2]];
        yield 'double' => ['double', 'DOUBLE', []];
        yield 'text' => ['text', 'TEXT', []];
        yield 'json' => ['json', 'JSON', []];
        yield 'date' => ['date', 'DATE', []];
        yield 'timestamp' => ['timestamp', 'TIMESTAMP', []];
        yield 'datetime' => ['datetime', 'DATETIME', []];
    }

    /**
     * @param array<string,mixed> $expected
     */
    #[Test]
    #[DataProvider('columnTypes')]
    public function itReadsTheTypeFromColumnType(string $columnType, string $name, array $expected): void
    {
        $type = MysqlNormalizer::type($columnType);

        $this->assertSame($name, $type->name->value);
        $this->assertSame($expected['length'] ?? null, $type->length);
        $this->assertSame($expected['precision'] ?? null, $type->precision);
        $this->assertSame($expected['scale'] ?? null, $type->scale);
        $this->assertSame($expected['unsigned'] ?? false, $type->unsigned);
    }

    /**
     * O MySQL não tem BOOLEAN: é apelido de TINYINT(1), e os dois são o mesmo no
     * catálogo. Algum dos dois tem que ganhar, e ganha BOOLEAN.
     */
    #[Test]
    public function tinyintDeUmVirouBoolean(): void
    {
        $this->assertSame(TypeName::Boolean, MysqlNormalizer::type('tinyint(1)')->name);
        $this->assertSame(TypeName::Boolean, MysqlNormalizer::type('tinyint(1) unsigned')->name);
    }

    /**
     * E o custo de BOOLEAN ganhar é este: um TINYINT declarado sem largura continua
     * TINYINT. Se a largura de exibição chegasse ao IR por qualquer caminho, os dois
     * tipos seriam indistinguíveis e um deles divergiria para sempre.
     */
    #[Test]
    public function tinyintSemLarguraContinuaTinyint(): void
    {
        $this->assertSame(TypeName::TinyInt, MysqlNormalizer::type('tinyint')->name);
        $this->assertNull(MysqlNormalizer::type('tinyint')->length);
    }

    /**
     * `INT(11)` e `INT` são o mesmo INT — o 8.0.19 parou até de reportar a largura.
     */
    #[Test]
    public function larguraDeExibicaoNaoChegaAoIr(): void
    {
        $this->assertNull(MysqlNormalizer::type('int(11)')->length);
        $this->assertSame(TypeName::Int, MysqlNormalizer::type('int(11)')->name);
    }

    /**
     * Regressão: `INT UNSIGNED ZEROFILL` é reportado como `int(10) unsigned zerofill`.
     *
     * Ou seja, o zerofill traz a largura de exibição de volta com ele, e o atributo vem
     * DEPOIS dos parênteses. Antes da correção o tipo chegava a `TypeName::fromAlias()`
     * como a string `int(10)` e a introspecção morria com "Tipo desconhecido" numa
     * tabela perfeitamente legítima.
     */
    #[Test]
    public function zerofillNaoDerrubaAIntrospeccao(): void
    {
        $type = MysqlNormalizer::type('int(10) unsigned zerofill');

        $this->assertSame(TypeName::Int, $type->name);
        $this->assertTrue($type->unsigned);
        $this->assertNull($type->length, 'zerofill não é representável no IR, e a largura de INT nunca é.');
    }

    /**
     * Os membros do ENUM sobrevivem à volta, inclusive os que contêm vírgula e aspa.
     *
     * Valor colhido do servidor: um `ENUM('a','b,c','d''e')` declarado volta escrito
     * exatamente assim. Partir na vírgula quebraria o segundo membro e desdobrar a aspa
     * errado MUDARIA o terceiro.
     */
    #[Test]
    public function membrosDoEnumSobrevivem(): void
    {
        $type = MysqlNormalizer::type("enum('a','b,c','d''e')");

        $this->assertSame(TypeName::Enum, $type->name);
        $this->assertSame(['a', 'b,c', "d'e"], $type->values);
    }

    /**
     * @return iterable<string,array{mixed,string,string,DefaultKind,string|int|float|bool|null}>
     */
    public static function defaults(): iterable
    {
        // Tuplas (COLUMN_DEFAULT, EXTRA, COLUMN_TYPE) como o servidor as devolveu.
        yield 'sem default' => [null, '', 'bigint', DefaultKind::None, null];
        yield 'auto increment não tem default' => [null, 'auto_increment', 'int', DefaultKind::None, null];
        yield 'inteiro' => ['3', '', 'tinyint', DefaultKind::Literal, 3];
        yield 'inteiro negativo' => ['-42', '', 'int', DefaultKind::Literal, -42];
        yield 'decimal ganha as casas' => ['0.00', '', 'decimal(10,2)', DefaultKind::Literal, 0.0];
        yield 'double' => ['1.5', '', 'double', DefaultKind::Literal, 1.5];
        yield 'boolean verdadeiro' => ['1', '', 'tinyint(1)', DefaultKind::Literal, true];
        yield 'boolean falso' => ['0', '', 'tinyint(1)', DefaultKind::Literal, false];
        yield 'string vazia' => ['', '', 'varchar(120)', DefaultKind::Literal, ''];
        yield 'texto' => ['a', '', "enum('a','b')", DefaultKind::Literal, 'a'];
        yield 'expressão marcada no EXTRA' => [
            'CURRENT_TIMESTAMP', 'DEFAULT_GENERATED', 'timestamp',
            DefaultKind::Expression, 'CURRENT_TIMESTAMP',
        ];
        yield 'expressão em datetime' => [
            'CURRENT_TIMESTAMP', 'DEFAULT_GENERATED', 'datetime',
            DefaultKind::Expression, 'CURRENT_TIMESTAMP',
        ];
        // Servidores anteriores ao 8.0.13 não marcam o EXTRA.
        yield 'expressão sem EXTRA, coluna temporal' => [
            'CURRENT_TIMESTAMP', '', 'timestamp',
            DefaultKind::Expression, 'CURRENT_TIMESTAMP',
        ];
        yield 'now() sem EXTRA, coluna temporal' => [
            'now()', '', 'datetime',
            DefaultKind::Expression, 'CURRENT_TIMESTAMP',
        ];
    }

    #[Test]
    #[DataProvider('defaults')]
    public function itReadsTheDefault(
        mixed $raw,
        string $extra,
        string $columnType,
        DefaultKind $kind,
        string|int|float|bool|null $value,
    ): void {
        $default = MysqlNormalizer::default($raw, $extra, $columnType);

        $this->assertSame($kind, $default->kind);
        $this->assertSame($value, $default->value);
    }

    /**
     * Regressão: o texto `CURRENT_TIMESTAMP` numa coluna VARCHAR é o LITERAL.
     *
     * Colhido do servidor: um `VARCHAR(40) NOT NULL DEFAULT 'CURRENT_TIMESTAMP'` chega
     * aqui com `COLUMN_DEFAULT = 'CURRENT_TIMESTAMP'` e `EXTRA` VAZIO — indistinguível,
     * campo a campo, de um `TIMESTAMP DEFAULT CURRENT_TIMESTAMP` num servidor antigo.
     * O que os separa é o tipo da coluna, e nada mais.
     *
     * Sem essa regra o fallback de compatibilidade desfazia justamente a distinção que
     * o EXTRA existe para fazer, e a coluna divergiria do model em toda comparação.
     */
    #[Test]
    public function currentTimestampNumaColunaDeTextoEhLiteral(): void
    {
        $default = MysqlNormalizer::default('CURRENT_TIMESTAMP', '', 'varchar(40)');

        $this->assertSame(DefaultKind::Literal, $default->kind);
        $this->assertSame('CURRENT_TIMESTAMP', $default->value);
    }

    /**
     * `COLUMN_DEFAULT` nulo é AUSÊNCIA de default, nunca `DEFAULT NULL`.
     *
     * O MySQL guarda o mesmo estado para `x INT NULL` e `x INT NULL DEFAULT NULL`, então
     * inventar a distinção aqui faria uma das duas formas divergir para sempre. O
     * `SchemaValidator` recusa a declaração pelo mesmo motivo.
     */
    #[Test]
    public function defaultNuloEhAusencia(): void
    {
        $default = MysqlNormalizer::default(null, '', 'int');

        $this->assertTrue($default->isNone());
        $this->assertFalse($default->isNull());
    }

    /**
     * O default vem como string mesmo quando a coluna é numérica, e o tipo PHP do
     * literal importa para o ARQUIVO de snapshot: `"price": "0"` ao lado de um `0`
     * declarado é um diff de code review que não corresponde a mudança nenhuma.
     */
    #[Test]
    public function literalNumericoNaoFicaComoString(): void
    {
        $this->assertIsInt(MysqlNormalizer::default('7', '', 'smallint unsigned')->value);
        $this->assertIsFloat(MysqlNormalizer::default('1.5', '', 'double')->value);
        $this->assertIsBool(MysqlNormalizer::default('1', '', 'tinyint(1)')->value);
        $this->assertIsString(MysqlNormalizer::default('7', '', 'varchar(10)')->value);
    }

    /**
     * BTREE é o método padrão e ninguém o declara. Reportá-lo faria todo índice
     * declarado sem método divergir do banco.
     */
    #[Test]
    public function btreeNaoEhReportado(): void
    {
        $this->assertNull(MysqlNormalizer::indexMethod('BTREE'));
        $this->assertNull(MysqlNormalizer::indexMethod('btree'));
        $this->assertSame('HASH', MysqlNormalizer::indexMethod('hash'));
        $this->assertSame('FULLTEXT', MysqlNormalizer::indexMethod(' FULLTEXT '));
    }

    /**
     * O MySQL devolve a expressão do CHECK com os identificadores em crase.
     */
    #[Test]
    public function craseSaiDaExpressaoDoCheck(): void
    {
        $this->assertSame(
            '(price >= 0)',
            MysqlNormalizer::checkExpression(' (`price` >= 0) '),
        );
    }
}
