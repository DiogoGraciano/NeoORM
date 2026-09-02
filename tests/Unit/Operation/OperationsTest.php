<?php

declare(strict_types=1);

namespace Tests\Unit\Operation;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Operation\AddColumn;
use Diogodg\Neoorm\Migrations\Operation\AlterColumn;
use Diogodg\Neoorm\Migrations\Operation\ColumnChangeSet;
use Diogodg\Neoorm\Migrations\Operation\CreateIndex;
use Diogodg\Neoorm\Migrations\Operation\DropColumn;
use Diogodg\Neoorm\Migrations\Operation\DropTable;
use Diogodg\Neoorm\Migrations\Operation\OperationList;
use Diogodg\Neoorm\Migrations\Operation\RawSql;
use Diogodg\Neoorm\Migrations\Operation\RenameColumn;
use Diogodg\Neoorm\Migrations\Operation\RenameTable;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use Tests\Support\Factory\Ir;
use Tests\Support\Factory\Ops;
use Tests\Support\UnitTestCase;

/**
 * O vocabulário das operações, antes de qualquer dialeto.
 *
 * Três coisas se afirmam aqui: que nome perigoso não entra nem na operação, que
 * `describe()` é legível (é o contrato que o differ vai ser testado contra), e que
 * `isDestructive()` classifica o risco de forma defensável.
 */
final class OperationsTest extends UnitTestCase
{
    /**
     * A validação acontece no construtor da operação, e não só no
     * `quoteIdentifier()` do dialeto: assim não existe operação com nome inválido
     * em circulação, nem em dry-run, nem em log, nem em mensagem de erro.
     */
    public function testTableNamesAreValidatedAtConstruction(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new DropTable('users; DROP TABLE x');
    }

    public function testColumnNamesAreValidatedAtConstruction(): void
    {
        $this->expectException(InvalidIdentifierException::class);
        new DropColumn('users', 'name -- ');
    }

    /**
     * Em renomeação, `tableName()` é o nome de ORIGEM: é o que existe no banco no
     * momento em que a operação roda, e é por ele que o sorter acha as foreign keys
     * que precisam sair da frente antes.
     */
    public function testRenamesReportTheSourceNameAsTheTableTheyActOn(): void
    {
        $this->assertSame('product', (new RenameTable('product', 'item'))->tableName());
        $this->assertSame('product', (new RenameColumn('product', 'name', 'title'))->tableName());
    }

    public function testEveryCatalogOperationDescribesItselfInOneReadableLine(): void
    {
        foreach (Ops::catalog() as $label => $operation) {
            $description = $operation->describe();

            $this->assertNotSame('', trim($description), "{$label} não se descreve");
            $this->assertStringNotContainsString("\n", $description, "{$label} descreve em mais de uma linha");
        }
    }

    /**
     * O texto de `describe()` é lido por gente em dry-run, e é comparado
     * literalmente pelos testes do differ. Fixar alguns aqui é o que impede uma
     * mudança de redação de passar despercebida.
     */
    public function testDescriptionsNameTheObjectTheyTouch(): void
    {
        $catalog = Ops::catalog();

        $this->assertSame(
            "cria a tabela 'product' com 7 colunas",
            $catalog['create_table']->describe(),
        );
        $this->assertSame(
            "adiciona a coluna 'product.sku' (VARCHAR(32))",
            $catalog['add_column']->describe(),
        );
        $this->assertSame(
            "renomeia a coluna 'product.name' para 'title'",
            $catalog['rename_column']->describe(),
        );
        $this->assertSame(
            "altera a coluna 'product.name' (tipo, comentário)",
            $catalog['alter_column']->describe(),
        );
        $this->assertSame(
            "cria o índice 'product_category_index' em 'product' (category)",
            $catalog['create_index']->describe(),
        );
        $this->assertSame(
            "adiciona a foreign key 'product_category_category_id_fk' em 'product' (category) -> category (id)",
            $catalog['add_foreign_key']->describe(),
        );
    }

    /**
     * Uma tabela com uma coluna só não diz "1 colunas".
     */
    public function testSingularAndPluralAreBothCorrect(): void
    {
        $single = new \Diogodg\Neoorm\Migrations\Operation\CreateTable(
            Ir::table('flag', [Ir::id()]),
        );

        $this->assertSame("cria a tabela 'flag' com 1 coluna", $single->describe());
    }

    // ---------------------------------------------------- risco de destruição

    public function testDroppingThingsThatHoldDataIsDestructive(): void
    {
        $this->assertTrue((new DropTable('product'))->isDestructive());
        $this->assertTrue((new DropColumn('product', 'name'))->isDestructive());
    }

    /**
     * Remover índice, unique, check ou foreign key não apaga linha nenhuma. A
     * classificação é sobre dados, não sobre importância — e é ela que decide
     * quando `migration:generate` exige `--allow-destructive`.
     */
    public function testDroppingConstraintsIsNotDestructive(): void
    {
        $catalog = Ops::catalog();

        foreach (
            [
                'drop_index',
                'drop_unique_constraint',
                'drop_check_constraint',
                'drop_foreign_key',
                'drop_primary_key',
            ] as $label
        ) {
            $this->assertFalse(
                $catalog[$label]->isDestructive(),
                "{$label} não apaga dados e não deveria ser classificada como destrutiva",
            );
        }
    }

    /**
     * Uma coluna `NOT NULL` sem default falha na hora se a tabela já tiver linhas —
     * não é perda de dado, é interrupção no meio de uma migração, e o custo disso é
     * alto o bastante para ser anunciado antes.
     */
    public function testAddingANotNullColumnWithoutDefaultIsFlagged(): void
    {
        $this->assertTrue(
            (new AddColumn('product', Ir::column('sku', 'VARCHAR', 32, notNull: true)))->isDestructive(),
        );

        $this->assertFalse(
            (new AddColumn('product', Ir::column(
                'sku',
                'VARCHAR',
                32,
                notNull: true,
                default: DefaultValue::literal(''),
            )))->isDestructive(),
        );

        $this->assertFalse(
            (new AddColumn('product', Ir::column('sku', 'VARCHAR', 32)))->isDestructive(),
        );
    }

    /**
     * Saber se um ALTER vai truncar de fato exigiria olhar as linhas. A operação
     * declara o risco e quem decide é quem roda o comando.
     */
    public function testTypeChangesAndNewNotNullAreFlaggedButOtherChangesAreNot(): void
    {
        $narrowing = new AlterColumn(
            'product',
            Ir::column('name', 'VARCHAR', 200, notNull: true),
            Ir::column('name', 'VARCHAR', 20, notNull: true),
        );
        $this->assertTrue($narrowing->isDestructive());

        $notNull = new AlterColumn(
            'product',
            Ir::column('name', 'VARCHAR', 120),
            Ir::column('name', 'VARCHAR', 120, notNull: true),
        );
        $this->assertTrue($notNull->isDestructive());

        $notNullWithDefault = new AlterColumn(
            'product',
            Ir::column('name', 'VARCHAR', 120),
            Ir::column('name', 'VARCHAR', 120, notNull: true, default: DefaultValue::literal('')),
        );
        $this->assertFalse($notNullWithDefault->isDestructive());

        $commentOnly = new AlterColumn(
            'product',
            Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'antes'),
            Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'depois'),
        );
        $this->assertFalse($commentOnly->isDestructive());
    }

    /** Índice único falha se já houver duplicata; o comum não falha nunca. */
    public function testOnlyUniqueIndexesAreFlagged(): void
    {
        $this->assertFalse((new CreateIndex('product', Ir::index('product', ['name'])))->isDestructive());
        $this->assertTrue(
            (new CreateIndex('product', Ir::index('product', ['name'], unique: true)))->isDestructive(),
        );
    }

    // ------------------------------------------------------- ColumnChangeSet

    public function testTheChangeSetNamesEveryAspectThatChanged(): void
    {
        $changes = ColumnChangeSet::between(
            Ir::column('name', 'VARCHAR', 120, comment: 'antes'),
            Ir::column('name', 'VARCHAR', 200, notNull: true, comment: 'depois'),
        );

        $this->assertSame(['tipo', 'nulidade', 'comentário'], $changes->changed());
        $this->assertFalse($changes->isEmpty());
        $this->assertFalse($changes->isCommentOnly());
    }

    public function testIdenticalColumnsProduceAnEmptyChangeSet(): void
    {
        $column = Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'x');

        $this->assertTrue(ColumnChangeSet::between($column, $column)->isEmpty());
        $this->assertSame([], ColumnChangeSet::between($column, $column)->changed());
    }

    /**
     * O default é comparado por `DefaultValue::equals()`, que compara literal
     * numérico por valor — o catálogo do MySQL devolve todo default como string, e
     * exigir que `0` e `"0"` fossem distintos geraria drift falso em toda coluna
     * numérica com default.
     */
    public function testANumericDefaultWrittenAsStringIsNotAChange(): void
    {
        $changes = ColumnChangeSet::between(
            Ir::column('hits', 'INT', notNull: true, default: DefaultValue::literal(0)),
            Ir::column('hits', 'INT', notNull: true, default: DefaultValue::literal('0')),
        );

        $this->assertTrue($changes->isEmpty());
    }

    /**
     * Renomear é `RenameColumn`. Deixar `AlterColumn` aceitar duas colunas de nomes
     * diferentes criaria um segundo caminho para renomear, e é justamente o caminho
     * que apagaria a coluna em vez de renomeá-la.
     */
    public function testAlterColumnRefusesTwoDifferentColumns(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/RenameColumn/');

        new AlterColumn('product', Ir::column('name', 'VARCHAR', 120), Ir::column('title', 'VARCHAR', 120));
    }

    // ------------------------------------------------------------- RawSql

    public function testRawSqlKeepsTheStatementAndDropsTheTrailingSemicolon(): void
    {
        $operation = new RawSql('CREATE VIEW v AS SELECT 1;  ');

        $this->assertSame('CREATE VIEW v AS SELECT 1', $operation->sql);
        $this->assertSame('', $operation->tableName());
        $this->assertFalse($operation->isDestructive());
    }

    /**
     * Um statement por operação. O runner manda um `exec()` por statement e
     * `MYSQL_ATTR_MULTI_STATEMENTS` fica desligado, então um bloco com `;` no meio
     * falharia no banco em vez de aqui, onde a mensagem é útil.
     */
    public function testRawSqlRefusesMoreThanOneStatement(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/um único statement/');

        new RawSql('DROP TABLE a; DROP TABLE b');
    }

    public function testRawSqlRefusesToBeEmpty(): void
    {
        $this->expectException(MigrationException::class);
        new RawSql('   ');
    }

    public function testRawSqlDescriptionIsTruncatedForLogging(): void
    {
        $operation = new RawSql('SELECT ' . str_repeat('x', 200));

        $this->assertLessThan(80, mb_strlen($operation->describe()));
        $this->assertStringEndsWith('...', $operation->describe());
    }

    // -------------------------------------------------------- OperationList

    public function testTheListPreservesOrder(): void
    {
        $first = new DropTable('a');
        $second = new DropTable('b');

        $this->assertSame([$first, $second], OperationList::of($first, $second)->all());
    }

    public function testAnEmptyListIsEmptyAndCountsZero(): void
    {
        $list = new OperationList();

        $this->assertTrue($list->isEmpty());
        $this->assertCount(0, $list);
        $this->assertSame([], $list->describe());
        $this->assertSame([], $list->tables());
        $this->assertFalse($list->hasDestructive());
    }

    public function testTheListIsIterableAndCountable(): void
    {
        $list = OperationList::of(new DropTable('a'), new DropTable('b'));

        $this->assertCount(2, $list);

        $seen = [];

        foreach ($list as $operation) {
            $seen[] = $operation->tableName();
        }

        $this->assertSame(['a', 'b'], $seen);
    }

    public function testWithAndMergeReturnNewListsWithoutTouchingTheOriginal(): void
    {
        $original = OperationList::of(new DropTable('a'));

        $extended = $original->with(new DropTable('b'));
        $merged = $original->merge(OperationList::of(new DropTable('c')));

        $this->assertCount(1, $original);
        $this->assertCount(2, $extended);
        $this->assertCount(2, $merged);
    }

    public function testDestructiveOperationsCanBeIsolatedForTheConfirmationPrompt(): void
    {
        $list = OperationList::of(
            new DropTable('a'),
            new RenameColumn('b', 'x', 'y'),
            new DropColumn('c', 'z'),
        );

        $this->assertTrue($list->hasDestructive());
        $this->assertSame(
            ['a', 'c'],
            array_map(static fn (SchemaOperation $o): string => $o->tableName(), $list->destructive()->all()),
        );
    }

    /** Tabelas sem repetição e ordenadas; `RawSql` sem tabela não entra. */
    public function testTablesAreDeduplicatedAndSorted(): void
    {
        $list = OperationList::of(
            new DropTable('zebra'),
            new DropColumn('alpha', 'x'),
            new DropColumn('zebra', 'y'),
            new RawSql('SELECT 1'),
        );

        $this->assertSame(['alpha', 'zebra'], $list->tables());
    }

    public function testFilterKeepsTheListImmutable(): void
    {
        $list = OperationList::of(new DropTable('a'), new DropColumn('b', 'x'));

        $onlyTables = $list->filter(static fn (SchemaOperation $o): bool => $o instanceof DropTable);

        $this->assertCount(2, $list);
        $this->assertCount(1, $onlyTables);
    }
}
