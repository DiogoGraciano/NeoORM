<?php

declare(strict_types=1);

namespace Tests\Unit\Diff;

use Diogodg\Neoorm\Migrations\Diff\OperationSorter;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Operation\AddCheckConstraint;
use Diogodg\Neoorm\Migrations\Operation\AddColumn;
use Diogodg\Neoorm\Migrations\Operation\AddForeignKey;
use Diogodg\Neoorm\Migrations\Operation\AddPrimaryKey;
use Diogodg\Neoorm\Migrations\Operation\AddUniqueConstraint;
use Diogodg\Neoorm\Migrations\Operation\AlterColumn;
use Diogodg\Neoorm\Migrations\Operation\CreateIndex;
use Diogodg\Neoorm\Migrations\Operation\CreateTable;
use Diogodg\Neoorm\Migrations\Operation\DropCheckConstraint;
use Diogodg\Neoorm\Migrations\Operation\DropColumn;
use Diogodg\Neoorm\Migrations\Operation\DropForeignKey;
use Diogodg\Neoorm\Migrations\Operation\DropIndex;
use Diogodg\Neoorm\Migrations\Operation\DropPrimaryKey;
use Diogodg\Neoorm\Migrations\Operation\DropTable;
use Diogodg\Neoorm\Migrations\Operation\DropUniqueConstraint;
use Diogodg\Neoorm\Migrations\Operation\OperationList;
use Diogodg\Neoorm\Migrations\Operation\RawSql;
use Diogodg\Neoorm\Migrations\Operation\RenameColumn;
use Diogodg\Neoorm\Migrations\Operation\RenameTable;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Migrations\Operation\SetTableComment;
use Diogodg\Neoorm\Migrations\Operation\SetTableOptions;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableOptions;
use Tests\Support\Factory\EdgeCaseSchemas;
use Tests\Support\Factory\Ir;
use Tests\Support\UnitTestCase;

final class OperationSorterTest extends UnitTestCase
{
    private function sort(array $operations, ?SchemaDefinition $from = null, ?SchemaDefinition $to = null): array
    {
        $sorted = (new OperationSorter())->sort(
            new OperationList($operations),
            $from ?? SchemaDefinition::empty(),
            $to ?? SchemaDefinition::empty(),
        );

        return array_map(
            static fn (SchemaOperation $o): string => (new \ReflectionClass($o))->getShortName(),
            $sorted->all(),
        );
    }

    /**
     * O pipeline inteiro, com uma operação de cada tipo entrando em ordem embaralhada.
     *
     * A sequência que sai é a tese do desenho: solta as referências, solta os índices,
     * solta a chave primária, renomeia, cria tabelas, mexe nas colunas, refaz a chave
     * primária, refaz índices, refaz referências, e só então remove coluna e tabela.
     * Remover por último é o que dá a toda operação anterior um schema onde tudo em que
     * ela toca ainda existe.
     */
    public function testTheWholePipelineInOrder(): void
    {
        $table = Ir::table('t', [Ir::id(), Ir::column('c', 'INT')]);
        $column = Ir::column('c', 'INT');
        $primaryKey = new PrimaryKeyDefinition('t_pk', ['id']);

        // Ordem de entrada deliberadamente ao contrário da esperada.
        $operations = [
            new DropTable('t'),
            new DropColumn('t', 'c'),
            new AddForeignKey('t', Ir::foreignKey('t', ['c'], 't2')),
            new AddCheckConstraint('t', Ir::check('t', 'c > 0')),
            new CreateIndex('t', Ir::index('t', ['c'])),
            new AddUniqueConstraint('t', Ir::unique('t', ['c'])),
            new AddPrimaryKey('t', $primaryKey),
            new SetTableOptions('t', new TableOptions(engine: 'InnoDB')),
            new SetTableComment('t', 'x'),
            new AlterColumn('t', $column, Ir::column('c', 'BIGINT')),
            new AddColumn('t', Ir::column('novo', 'INT')),
            new CreateTable($table),
            new RenameColumn('t', 'a', 'b'),
            new RenameTable('t0', 't'),
            new DropPrimaryKey('t', 't_pk'),
            new DropCheckConstraint('t', 'chk'),
            new DropUniqueConstraint('t', 'uniq'),
            new DropIndex('t', 'idx'),
            new DropForeignKey('t', 'fk'),
        ];

        // A afirmação é sobre FASES, não sobre a ordem dentro de cada uma: dentro de
        // uma fase a ordem de entrada é preservada de propósito, então exigir uma
        // sequência total aqui estaria testando a ordem em que este teste montou a
        // lista, e não o pipeline.
        $this->assertSame(
            [
                'P0 solta referências',
                'P1 solta índices e restrições',
                'P1 solta índices e restrições',
                'P1 solta índices e restrições',
                'P2 solta a chave primária',
                'P3 renomeia tabela',
                'P3 renomeia coluna',
                'P4 cria tabela',
                'P5 mexe nas colunas',
                'P5 mexe nas colunas',
                'P5 mexe nas colunas',
                'P5 mexe nas colunas',
                'P6 refaz a chave primária',
                'P7 refaz índices e restrições',
                'P7 refaz índices e restrições',
                'P7 refaz índices e restrições',
                'P8 refaz referências',
                'P9 remove coluna',
                'P10 remove tabela',
            ],
            array_map(self::phaseOf(...), $this->sort($operations)),
        );
    }

    private static function phaseOf(string $operation): string
    {
        return match ($operation) {
            'DropForeignKey' => 'P0 solta referências',
            'DropIndex', 'DropUniqueConstraint', 'DropCheckConstraint' => 'P1 solta índices e restrições',
            'DropPrimaryKey' => 'P2 solta a chave primária',
            'RenameTable' => 'P3 renomeia tabela',
            'RenameColumn' => 'P3 renomeia coluna',
            'CreateTable' => 'P4 cria tabela',
            'AddColumn', 'AlterColumn', 'SetTableComment', 'SetTableOptions' => 'P5 mexe nas colunas',
            'AddPrimaryKey' => 'P6 refaz a chave primária',
            'AddUniqueConstraint', 'CreateIndex', 'AddCheckConstraint' => 'P7 refaz índices e restrições',
            'AddForeignKey' => 'P8 refaz referências',
            'DropColumn' => 'P9 remove coluna',
            'DropTable' => 'P10 remove tabela',
            default => "sem fase: {$operation}",
        };
    }

    /** As 20 operações menos RawSql, que o sorter recusa: nenhuma ficou de fora. */
    public function testEveryOperationExceptRawSqlHasAPhase(): void
    {
        $sorted = $this->sort([
            new DropTable('t'),
            new DropColumn('t', 'c'),
            new AddForeignKey('t', Ir::foreignKey('t', ['c'], 't2')),
            new AddCheckConstraint('t', Ir::check('t', 'c > 0')),
            new CreateIndex('t', Ir::index('t', ['c'])),
            new AddUniqueConstraint('t', Ir::unique('t', ['c'])),
            new AddPrimaryKey('t', new PrimaryKeyDefinition('t_pk', ['id'])),
            new SetTableOptions('t', new TableOptions(engine: 'InnoDB')),
            new SetTableComment('t', 'x'),
            new AlterColumn('t', Ir::column('c', 'INT'), Ir::column('c', 'BIGINT')),
            new AddColumn('t', Ir::column('novo', 'INT')),
            new CreateTable(Ir::table('t', [Ir::id(), Ir::column('c', 'INT')])),
            new RenameColumn('t', 'a', 'b'),
            new RenameTable('t0', 't'),
            new DropPrimaryKey('t', 't_pk'),
            new DropCheckConstraint('t', 'chk'),
            new DropUniqueConstraint('t', 'uniq'),
            new DropIndex('t', 'idx'),
            new DropForeignKey('t', 'fk'),
        ]);

        $this->assertCount(19, $sorted);

        foreach ($sorted as $operation) {
            $this->assertStringNotContainsString('sem fase', self::phaseOf($operation), $operation);
        }
    }

    /**
     * Renomear a tabela vem antes de renomear a coluna, e não é preferência: as
     * operações de coluna já carregam o nome NOVO da tabela, porque a P3 já rodou.
     */
    public function testTableRenamesComeBeforeColumnRenames(): void
    {
        $this->assertSame(
            ['RenameTable', 'RenameColumn'],
            $this->sort([new RenameColumn('town', 'a', 'b'), new RenameTable('city', 'town')]),
        );
    }

    public function testTableCreationFollowsTheDependencyGraphOfTheTargetSchema(): void
    {
        $schema = Ir::geographySchema();

        $operations = [
            new CreateTable($schema->tables['city']),
            new CreateTable($schema->tables['country']),
            new CreateTable($schema->tables['state']),
        ];

        $sorted = (new OperationSorter())->sort(
            new OperationList($operations),
            SchemaDefinition::empty(),
            $schema,
        );

        $this->assertSame(
            ['country', 'state', 'city'],
            array_map(static fn (SchemaOperation $o): string => $o->tableName(), $sorted->all()),
        );
    }

    public function testTableRemovalFollowsTheReverseGraphOfTheSourceSchema(): void
    {
        $schema = Ir::geographySchema();

        $operations = [new DropTable('country'), new DropTable('city'), new DropTable('state')];

        $sorted = (new OperationSorter())->sort(
            new OperationList($operations),
            $schema,
            SchemaDefinition::empty(),
        );

        $this->assertSame(
            ['city', 'state', 'country'],
            array_map(static fn (SchemaOperation $o): string => $o->tableName(), $sorted->all()),
        );
    }

    /**
     * Fora das fases de criação e remoção, a ordem de entrada é preservada. O differ
     * percorre mapas ordenados por nome, então isso já é determinístico E agrupa as
     * operações de uma mesma tabela.
     */
    public function testWithinOtherPhasesTheInputOrderIsPreserved(): void
    {
        $operations = [
            new AddColumn('zebra', Ir::column('a', 'INT')),
            new AddColumn('alpha', Ir::column('b', 'INT')),
            new AddColumn('meio', Ir::column('c', 'INT')),
        ];

        $sorted = (new OperationSorter())->sort(
            new OperationList($operations),
            SchemaDefinition::empty(),
            SchemaDefinition::empty(),
        );

        $this->assertSame(
            ['zebra', 'alpha', 'meio'],
            array_map(static fn (SchemaOperation $o): string => $o->tableName(), $sorted->all()),
        );
    }

    /** Ordenar duas vezes dá o mesmo resultado, e ordenar o resultado é no-op. */
    public function testSortingIsStableAndIdempotent(): void
    {
        $schema = EdgeCaseSchemas::get('deep_chain');
        $operations = array_map(
            static fn (string $name): CreateTable => new CreateTable($schema->tables[$name]),
            array_keys($schema->tables),
        );

        $sorter = new OperationSorter();
        $once = $sorter->sort(new OperationList($operations), SchemaDefinition::empty(), $schema);
        $twice = $sorter->sort($once, SchemaDefinition::empty(), $schema);

        $this->assertSame($once->describe(), $twice->describe());
    }

    public function testAnEmptyListSortsToAnEmptyList(): void
    {
        $this->assertSame([], $this->sort([]));
    }

    /**
     * Não há resposta correta para onde colocar SQL escrito à mão, e reordená-lo por
     * chute mudaria o que a pessoa quis dizer. Uma migração com RawSql é escrita na
     * ordem em que vai rodar.
     */
    public function testRawSqlIsRefusedInsteadOfBeingReorderedByGuess(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/na ordem em que foi escrito/');

        $this->sort([new RawSql('CREATE VIEW v AS SELECT 1')]);
    }
}
