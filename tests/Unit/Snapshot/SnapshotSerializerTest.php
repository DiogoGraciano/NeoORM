<?php

declare(strict_types=1);

namespace Tests\Unit\Snapshot;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Migrations\Snapshot\SnapshotMeta;
use Diogodg\Neoorm\Migrations\Snapshot\SnapshotSerializer;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\IrAssertions;
use Tests\Support\Factory\EdgeCaseSchemas;
use Tests\Support\Factory\Ir;
use Tests\Support\UnitTestCase;

/**
 * P2 — determinismo do snapshot.
 *
 * A propriedade é uma linha: `toJson(fromJson(toJson(x)))` igual a `toJson(x)`,
 * byte a byte, para todo schema. Se ela falhar, o sistema inteiro perde sentido:
 * o arquivo muda sozinho a cada volta pelo disco, o differ vê diferença onde não
 * houve, e cada `generate` produz uma migração espúria até ninguém mais confiar no
 * comando.
 */
final class SnapshotSerializerTest extends UnitTestCase
{
    use IrAssertions;

    private function serializer(): SnapshotSerializer
    {
        return new SnapshotSerializer();
    }

    /**
     * @return iterable<string,array{string}>
     */
    public static function schemaNames(): iterable
    {
        foreach (array_keys(EdgeCaseSchemas::all()) as $name) {
            yield $name => [$name];
        }
    }

    #[DataProvider('schemaNames')]
    public function testJsonSurvivesARoundTripByteForByte(string $schema): void
    {
        $serializer = $this->serializer();
        $snapshot = Snapshot::initial('pgsql', EdgeCaseSchemas::get($schema));

        $once = $serializer->toJson($snapshot);
        $twice = $serializer->toJson($serializer->fromJson($once));

        $this->assertSame($once, $twice, "O snapshot '{$schema}' não é estável em ida e volta.");
    }

    /**
     * A volta preserva o IR, não só os bytes. São afirmações diferentes: a de cima
     * pega instabilidade de formatação, esta pega perda de informação — um campo que
     * não é serializado sai igual nas duas serializações e mesmo assim desapareceu.
     */
    #[DataProvider('schemaNames')]
    public function testTheIrSurvivesARoundTrip(string $schema): void
    {
        $serializer = $this->serializer();
        $snapshot = Snapshot::initial('mysql', EdgeCaseSchemas::get($schema));

        $restored = $serializer->fromJson($serializer->toJson($snapshot));

        $this->assertSnapshotSchemaEquals($snapshot, $restored);
        $this->assertSame($snapshot->dialect, $restored->dialect);
        $this->assertSame($snapshot->id, $restored->id);
        $this->assertSame($snapshot->prevId, $restored->prevId);
    }

    /**
     * O JSON não depende da ordem em que as coisas foram declaradas.
     *
     * Declarar as mesmas tabelas em outra ordem tem que produzir o mesmo arquivo,
     * senão dois desenvolvedores com os mesmos models geram snapshots diferentes e o
     * primeiro `generate` de cada um acusa uma migração que o outro não vê.
     */
    public function testDeclarationOrderOfTablesDoesNotChangeTheJson(): void
    {
        $tables = array_values(EdgeCaseSchemas::get('geography')->tables);

        $straight = Snapshot::initial('pgsql', Ir::schema($tables));
        $reversed = Snapshot::initial('pgsql', Ir::schema(array_reverse($tables)));

        $this->assertSame($this->serializer()->toJson($straight), $this->serializer()->toJson($reversed));
    }

    /**
     * `JSON_PRESERVE_ZERO_FRACTION` não é detalhe de estilo. Sem ele, um default
     * `1.0` é escrito como `1`, volta como inteiro, e o snapshot deixa de bater com
     * ele mesmo depois de uma única volta pelo disco.
     */
    public function testAWholeFloatDefaultKeepsItsFraction(): void
    {
        $snapshot = Snapshot::initial('pgsql', EdgeCaseSchemas::get('default_variants'));
        $json = $this->serializer()->toJson($snapshot);

        $this->assertStringContainsString('1.0', $json);

        $restored = $this->serializer()->fromJson($json);
        $column = $restored->table('setting')?->column('float_whole');

        $this->assertNotNull($column);
        $this->assertTrue($column->default->equals(
            \Diogodg\Neoorm\Schema\Value\DefaultValue::literal(1.0),
        ));
    }

    /**
     * Acentuação e emoji ficam legíveis no arquivo. Um snapshot é lido por gente em
     * code review, e `ção` no lugar de `ção` inviabiliza isso.
     */
    public function testUnicodeAndSlashesAreNotEscaped(): void
    {
        $snapshot = Snapshot::initial('pgsql', EdgeCaseSchemas::get('comment_variants'));
        $json = $this->serializer()->toJson($snapshot);

        $this->assertStringContainsString('ção', $json);
        $this->assertStringContainsString('🎯', $json);

        // Nenhuma sequência de escape unicode: é o que `JSON_UNESCAPED_UNICODE`
        // garante, e o que separa um arquivo legível de um ilegível.
        $this->assertDoesNotMatchRegularExpression('/\\\\u[0-9a-f]{4}/i', $json);
    }

    /**
     * Um campo que é mapa continua sendo mapa quando está vazio.
     *
     * Sem isso o tipo JSON de `indexes` dependeria de a tabela ter índices:
     * `{...}` numa, `[]` na vizinha. Round-trip em PHP funciona nos dois casos, mas
     * um campo que troca de tipo conforme o conteúdo é um campo que ninguém
     * consegue descrever — e snapshot é lido por gente em code review.
     */
    public function testEmptyMapsStayMapsAndEmptyListsStayLists(): void
    {
        $json = $this->serializer()->toJson(Snapshot::initial('pgsql', EdgeCaseSchemas::get('single_table')));

        foreach (['checks', 'foreignKeys', 'indexes', 'uniqueConstraints'] as $map) {
            $this->assertStringContainsString("\"{$map}\": {}", $json, "{$map} vazio deveria ser objeto");
        }

        $this->assertStringContainsString('"renamedTables": {}', $json);
        $this->assertStringNotContainsString('[]', $json);

        // E o inverso: `columnOrder` é lista de verdade e continua lista.
        $this->assertStringContainsString('"columnOrder": [', $json);
    }

    public function testAnEmptySchemaSerializesTablesAsAnEmptyObject(): void
    {
        $json = $this->serializer()->toJson(Snapshot::baseline('pgsql'));

        $this->assertStringContainsString('"tables": {}', $json);
        $this->assertSame(
            $json,
            $this->serializer()->toJson($this->serializer()->fromJson($json)),
        );
    }

    public function testTheFileEndsWithExactlyOneNewline(): void
    {
        $json = $this->serializer()->toJson(Snapshot::initial('pgsql', EdgeCaseSchemas::get('single_table')));

        $this->assertStringEndsWith("}\n", $json);
    }

    /**
     * Chaves de dados ordenadas, e `columnOrder` como a única lista posicional.
     */
    public function testTheStructureIsSortedAndColumnOrderIsTheOnlyPositionalList(): void
    {
        $snapshot = Snapshot::initial('pgsql', EdgeCaseSchemas::get('geography'));
        $data = $this->serializer()->toArray($snapshot);

        $this->assertSame(['_meta', 'dialect', 'id', 'prevId', 'tables', 'version'], array_keys($data));

        /** @var array<string,array<string,mixed>> $tables */
        $tables = $data['tables'];
        $this->assertSame(['city', 'country', 'state'], array_keys($tables));

        /** @var array<string,mixed> $city */
        $city = $tables['city'];
        $this->assertSame(
            [
                'checks', 'columnOrder', 'columns', 'comment', 'foreignKeys',
                'indexes', 'name', 'options', 'primaryKey', 'uniqueConstraints',
            ],
            array_keys($city),
        );

        // Colunas ordenadas alfabeticamente no mapa; a ordem de declaração vive só
        // em columnOrder, e o differ não a compara — ordem de coluna não vale um
        // ALTER em nenhum dos dois bancos.
        /** @var array<string,mixed> $columns */
        $columns = $city['columns'];
        $this->assertSame(['ibge', 'id', 'name', 'state'], array_keys($columns));
        $this->assertSame(['id', 'name', 'state', 'ibge'], $city['columnOrder']);
    }

    /** `INT` é `{"name":"INT"}`, não um objeto cheio de nulos. */
    public function testTypesOmitEmptyFields(): void
    {
        $json = $this->serializer()->toJson(Snapshot::initial('pgsql', EdgeCaseSchemas::get('single_table')));

        $this->assertStringContainsString('"type": {' . "\n" . '                        "name": "INT"', $json);
        $this->assertStringNotContainsString('"precision": null', $json);
    }

    public function testMetaSurvivesTheRoundTrip(): void
    {
        $snapshot = new Snapshot(
            'pgsql',
            '0003',
            '0002',
            EdgeCaseSchemas::get('single_table'),
            new SnapshotMeta(['town' => 'city'], ['city.label' => 'name']),
        );

        $restored = $this->serializer()->fromJson($this->serializer()->toJson($snapshot));

        $this->assertSame('city', $restored->meta->renamedTable('town'));
        $this->assertSame('name', $restored->meta->renamedColumn('city', 'label'));
    }

    // ------------------------------------------------------------- validação

    public function testMalformedJsonFailsWithAUsefulMessage(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/não é JSON válido/');

        $this->serializer()->fromJson('{ isto não é json');
    }

    public function testATruncatedSnapshotNamesTheMissingKey(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches("/'tables'/");

        $this->serializer()->fromJson('{"version":1,"dialect":"pgsql","id":"0000"}');
    }

    /**
     * Formato mais novo é erro, não aviso: ler um arquivo que esta versão não entende
     * e adivinhar o resto produziria um diff plausível e errado.
     */
    public function testAFutureFormatVersionIsRefusedInsteadOfGuessed(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/Atualize a biblioteca/');

        $this->serializer()->fromJson('{"version":99,"dialect":"pgsql","id":"0000","tables":{}}');
    }

    /**
     * O ponto de extensão existe desde o primeiro arquivo escrito, e não quando for
     * necessário: um projeto com cem migrações no histórico não pode precisar
     * regerar todas para o formato mudar.
     */
    public function testUpcastIsIdentityForTheCurrentFormat(): void
    {
        $data = ['version' => Snapshot::FORMAT_VERSION, 'dialect' => 'pgsql', 'id' => '0000', 'tables' => []];

        $this->assertSame($data, $this->serializer()->upcast($data));
    }
}
