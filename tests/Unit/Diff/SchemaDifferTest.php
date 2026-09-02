<?php

declare(strict_types=1);

namespace Tests\Unit\Diff;

use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Migrations\Snapshot\SnapshotSerializer;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\Concerns\IrAssertions;
use Tests\Support\Factory\DriftPairs;
use Tests\Support\Factory\EdgeCaseSchemas;
use Tests\Support\Factory\Ir;
use Tests\Support\UnitTestCase;

/**
 * P1 — idempotência, e o contrato do differ lido como texto.
 *
 * A propriedade central é trivial de enunciar e é a que sustenta a confiança no
 * comando: comparar um schema consigo mesmo não produz operação nenhuma. Se ela
 * falhar, `migration:generate` cospe uma migração vazia-mas-não-vazia toda vez que
 * roda, e em duas semanas ninguém mais usa o comando.
 *
 * A variante que importa é a do meio: `diff(s, decode(encode(s)))`. Ela cruza as
 * duas metades do sistema — se a serialização perde um campo, a comparação acusa a
 * perda, e nenhum dos dois testes isolados pegaria isso.
 */
final class SchemaDifferTest extends UnitTestCase
{
    use IrAssertions;

    private function differ(): SchemaDiffer
    {
        return new SchemaDiffer();
    }

    private function snapshot(SchemaDefinition $schema, string $dialect = 'pgsql'): Snapshot
    {
        return Snapshot::initial($dialect, $schema);
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

    // ------------------------------------------------------- P1 idempotência

    #[DataProvider('schemaNames')]
    public function testComparingASchemaWithItselfProducesNothing(string $name): void
    {
        $snapshot = $this->snapshot(EdgeCaseSchemas::get($name));

        $this->assertNoOperations(
            $this->differ()->diff($snapshot, $snapshot),
            "O schema '{$name}' não é idempotente: comparado consigo mesmo, gerou operações.",
        );
    }

    /**
     * Atravessa serialização e comparação de uma vez.
     *
     * Um campo que o serializador perde sai igual nas duas serializações, então o
     * teste de estabilidade de bytes passa; é aqui que a perda aparece, porque o
     * lado que voltou do JSON difere do original.
     */
    #[DataProvider('schemaNames')]
    public function testComparingASchemaWithItsSerializedCopyProducesNothing(string $name): void
    {
        $serializer = new SnapshotSerializer();
        $snapshot = $this->snapshot(EdgeCaseSchemas::get($name));
        $restored = $serializer->fromJson($serializer->toJson($snapshot));

        $this->assertNoOperations(
            $this->differ()->diff($snapshot, $restored),
            "O schema '{$name}' perde informação na ida e volta pelo JSON.",
        );
    }

    /**
     * A determinização não depende da ordem de declaração.
     *
     * Duas pessoas com os mesmos models, declarados em ordens diferentes, precisam
     * gerar o mesmo snapshot — senão o primeiro `generate` de cada uma acusa uma
     * migração que a outra não vê.
     */
    #[DataProvider('schemaNames')]
    public function testShufflingDeclarationOrderChangesNothing(string $name): void
    {
        $schema = EdgeCaseSchemas::get($name);
        $tables = array_values($schema->tables);

        $shuffled = Ir::schema(array_reverse($tables));

        $this->assertNoOperations(
            $this->differ()->diff($this->snapshot($schema), $this->snapshot($shuffled)),
            "O schema '{$name}' depende da ordem de declaração das tabelas.",
        );
    }

    /**
     * E o mesmo para colunas. Aqui a ordem de declaração é preservada no snapshot,
     * em `columnOrder`, mas o differ não a compara — ordem de coluna não vale um
     * ALTER em nenhum dos dois bancos.
     */
    #[DataProvider('schemaNames')]
    public function testShufflingColumnOrderChangesNothing(string $name): void
    {
        $schema = EdgeCaseSchemas::get($name);
        $reordered = [];

        foreach ($schema->tables as $table) {
            $reordered[] = new \Diogodg\Neoorm\Schema\TableDefinition(
                name: $table->name,
                columns: array_reverse(array_values($table->getColumns())),
                primaryKey: $table->primaryKey,
                uniqueConstraints: array_values($table->uniqueConstraints),
                indexes: array_values($table->indexes),
                foreignKeys: array_values($table->foreignKeys),
                checks: array_values($table->checks),
                comment: $table->comment,
                options: $table->options,
            );
        }

        $this->assertNoOperations(
            $this->differ()->diff($this->snapshot($schema), $this->snapshot(new SchemaDefinition($reordered))),
            "O schema '{$name}' trata ordem de coluna como mudança.",
        );
    }

    /** Duas chamadas com a mesma entrada dão exatamente a mesma saída. */
    #[DataProvider('schemaNames')]
    public function testTheDifferIsAPureFunctionOfItsInputs(string $name): void
    {
        $from = $this->snapshot(SchemaDefinition::empty());
        $to = $this->snapshot(EdgeCaseSchemas::get($name));

        $this->assertSame(
            $this->differ()->diff($from, $to)->describe(),
            $this->differ()->diff($from, $to)->describe(),
        );
    }

    // ------------------------------------------------------ contrato por caso

    /**
     * @return iterable<string,array{string}>
     */
    public static function driftPairs(): iterable
    {
        foreach (array_keys(DriftPairs::all()) as $name) {
            yield $name => [$name];
        }
    }

    /**
     * Uma mudança atômica por caso, com a lista exata de operações esperada.
     *
     * Ver `Tests\Support\Factory\DriftPairs` — é lá que o comportamento está escrito,
     * e o arquivo é feito para ser lido em vez do differ.
     */
    #[DataProvider('driftPairs')]
    public function testEachAtomicChangeProducesExactlyTheExpectedOperations(string $name): void
    {
        [$from, $to, $expected] = DriftPairs::all()[$name];

        $this->assertOperationsAre(
            $expected,
            $this->differ()->diff($this->snapshot($from), $this->snapshot($to)),
            "Caso '{$name}' divergiu do contrato.",
        );
    }

    /**
     * Os casos em que a comparação é assimétrica DE PROPÓSITO.
     *
     * `null` em engine ou collation significa "default do servidor, não compare", e
     * é essa regra que mata o bug B2 na raiz. Ela é necessariamente unilateral:
     * declarar um collation é uma mudança, deixar de declará-lo não é. A consequência
     * assumida é que não existe migração que devolva uma tabela ao collation padrão
     * do servidor — e não existe porque não há DDL que peça o default sem nomear um
     * valor concreto.
     *
     * @var list<string>
     */
    private const ASYMMETRIC_BY_DESIGN = [
        'set_table_collation',
        'declared_null_collation_does_not_fight_the_server_default',
    ];

    /**
     * Se `A -> B` gera operações, `B -> A` também gera.
     *
     * Pega diff cego de um lado só: uma direção detecta a mudança, a outra não, e o
     * schema deixa de convergir dependendo de por onde se chega nele. As exceções
     * estão em `ASYMMETRIC_BY_DESIGN`, e são exceções documentadas, não descobertas.
     */
    #[DataProvider('driftPairs')]
    public function testEveryChangeIsDetectedInBothDirections(string $name): void
    {
        if (in_array($name, self::ASYMMETRIC_BY_DESIGN, true)) {
            $this->markTestSkipped(
                "'{$name}' é assimétrico por decisão de projeto: null em opções de tabela significa "
                . '"default do servidor, não compare". Ver ASYMMETRIC_BY_DESIGN.',
            );
        }

        [$from, $to, $expected] = DriftPairs::all()[$name];

        $backward = $this->differ()->diff($this->snapshot($to), $this->snapshot($from));

        if ($expected === []) {
            $this->assertNoOperations($backward, "Caso '{$name}': vazio na ida, não vazio na volta.");

            return;
        }

        $this->assertNotSame(
            [],
            $backward->describe(),
            "Caso '{$name}': a mudança é detectada na ida mas não na volta.",
        );
    }

    // ----------------------------------------------------------------- guardas

    public function testComparingSnapshotsOfDifferentDialectsIsRefused(): void
    {
        $schema = EdgeCaseSchemas::get('single_table');

        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/diretórios separados/');

        $this->differ()->diff($this->snapshot($schema, 'mysql'), $this->snapshot($schema, 'pgsql'));
    }

    /**
     * A primeira migração não é caminho especial de código: é
     * `diff(baseline, snapshot0)`. Um caminho que só roda uma vez por projeto é um
     * caminho que nunca é testado de verdade.
     */
    public function testTheFirstMigrationIsJustADiffAgainstTheBaseline(): void
    {
        $operations = $this->differ()->diff(
            Snapshot::baseline('pgsql'),
            $this->snapshot(Ir::geographySchema()),
        );

        // As tabelas saem em ordem topológica — pai antes de filho —, e as restrições
        // em ordem alfabética de tabela. A inconsistência é aceita: nenhuma das duas
        // ordens é necessária para o banco aceitar o resultado, porque toda FK entra
        // depois de toda tabela existir.
        $this->assertOperationsAre(
            [
                "cria a tabela 'country' com 3 colunas",
                "cria a tabela 'state' com 4 colunas",
                "cria a tabela 'city' com 4 colunas",
                "adiciona a restrição de unicidade 'city_ibge_unique' em 'city' (ibge)",
                "adiciona a restrição de unicidade 'state_ibge_unique' em 'state' (ibge)",
                "adiciona a foreign key 'city_state_state_id_fk' em 'city' (state) -> state (id)",
                "adiciona a foreign key 'state_country_country_id_fk' em 'state' (country) -> country (id)",
            ],
            $operations,
        );
    }

    /**
     * Dez tabelas em cadeia, nomeadas na ordem INVERSA da dependência. A ordem de
     * criação vem do grafo, não do nome — e era a ordem alfabética dos arquivos de
     * model que o sistema antigo usava, e a razão de precisar de um segundo passe de
     * foreign keys.
     */
    public function testCreationOrderComesFromTheGraphAndNotFromTheName(): void
    {
        $operations = $this->differ()->diff(
            Snapshot::baseline('pgsql'),
            $this->snapshot(EdgeCaseSchemas::get('deep_chain')),
        );

        $created = [];

        foreach ($operations as $operation) {
            if ($operation instanceof \Diogodg\Neoorm\Migrations\Operation\CreateTable) {
                $created[] = $operation->tableName();
            }
        }

        $this->assertSame(
            [
                'chain_10', 'chain_09', 'chain_08', 'chain_07', 'chain_06',
                'chain_05', 'chain_04', 'chain_03', 'chain_02', 'chain_01',
            ],
            $created,
        );
    }

    public function testRemovalOrderIsTheReverseOfCreation(): void
    {
        $operations = $this->differ()->diff(
            $this->snapshot(EdgeCaseSchemas::get('deep_chain')),
            Snapshot::baseline('pgsql'),
        );

        $dropped = [];

        foreach ($operations as $operation) {
            if ($operation instanceof \Diogodg\Neoorm\Migrations\Operation\DropTable) {
                $dropped[] = $operation->tableName();
            }
        }

        $this->assertSame(
            [
                'chain_01', 'chain_02', 'chain_03', 'chain_04', 'chain_05',
                'chain_06', 'chain_07', 'chain_08', 'chain_09', 'chain_10',
            ],
            $dropped,
        );
    }

    /**
     * Foreign key mútua não trava a criação. É legítima porque toda FK entra na P8,
     * quando as duas tabelas já existem — e é o que faz o desempate de ciclo por
     * nome ser suficiente em vez de o ciclo ser um erro.
     */
    public function testMutualForeignKeysDoNotBlockCreation(): void
    {
        $operations = $this->differ()->diff(
            Snapshot::baseline('pgsql'),
            $this->snapshot(EdgeCaseSchemas::get('cyclic')),
        );

        $this->assertOperationsAre(
            [
                "cria a tabela 'cyclic_a' com 2 colunas",
                "cria a tabela 'cyclic_b' com 2 colunas",
                "adiciona a foreign key 'cyclic_a_b_cyclic_b_id_fk' em 'cyclic_a' (b) -> cyclic_b (id)",
                "adiciona a foreign key 'cyclic_b_a_cyclic_a_id_fk' em 'cyclic_b' (a) -> cyclic_a (id)",
            ],
            $operations,
        );
    }

    /**
     * Duas tabelas de foreign key mútua, removidas juntas.
     *
     * Este é o caso que prova por que a P0 solta as referências de uma tabela que vai
     * sair, mesmo sabendo que o `DROP TABLE` as levaria embora: aqui NÃO EXISTE ordem
     * de `DROP TABLE` que funcione, porque cada tabela é referenciada pela outra e os
     * dois bancos recusam derrubar uma tabela referenciada. Nenhuma ordenação
     * topológica resolve; soltar as referências antes resolve.
     */
    public function testMutualForeignKeysAreReleasedBeforeDroppingEitherTable(): void
    {
        $operations = $this->differ()->diff(
            $this->snapshot(EdgeCaseSchemas::get('cyclic')),
            Snapshot::baseline('pgsql'),
        );

        $this->assertOperationsAre(
            [
                "remove a foreign key 'cyclic_a_b_cyclic_b_id_fk' de 'cyclic_a'",
                "remove a foreign key 'cyclic_b_a_cyclic_a_id_fk' de 'cyclic_b'",
                "remove a tabela 'cyclic_b'",
                "remove a tabela 'cyclic_a'",
            ],
            $operations,
        );
    }

    /**
     * Toda foreign key de uma tabela que sai é removida antes de qualquer
     * DROP TABLE, o que é o que torna a ordem de remoção irrelevante para o banco.
     */
    public function testForeignKeysAreReleasedBeforeAnyTableIsDropped(): void
    {
        $operations = $this->differ()->diff(
            $this->snapshot(Ir::geographySchema()),
            Snapshot::baseline('pgsql'),
        )->describe();

        $lastDropForeignKey = 0;
        $firstDropTable = count($operations);

        foreach ($operations as $position => $description) {
            if (str_starts_with($description, 'remove a foreign key')) {
                $lastDropForeignKey = $position;
            }

            if (str_starts_with($description, 'remove a tabela') && $firstDropTable === count($operations)) {
                $firstDropTable = $position;
            }
        }

        $this->assertLessThan($firstDropTable, $lastDropForeignKey);
    }

    /**
     * E o espelho: nenhuma foreign key é adicionada antes de a última tabela existir.
     */
    public function testForeignKeysAreAddedAfterEveryTableExists(): void
    {
        $operations = $this->differ()->diff(
            Snapshot::baseline('pgsql'),
            $this->snapshot(EdgeCaseSchemas::get('composite_everything')),
        )->describe();

        $lastCreate = 0;
        $firstAddForeignKey = count($operations);

        foreach ($operations as $position => $description) {
            if (str_starts_with($description, 'cria a tabela')) {
                $lastCreate = $position;
            }

            if (
                str_starts_with($description, 'adiciona a foreign key')
                && $firstAddForeignKey === count($operations)
            ) {
                $firstAddForeignKey = $position;
            }
        }

        $this->assertLessThan($firstAddForeignKey, $lastCreate);
    }
}
