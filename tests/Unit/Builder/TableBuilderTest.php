<?php

declare(strict_types=1);

namespace Tests\Unit\Builder;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\UnitTestCase;

/**
 * O builder de tabela, sem banco.
 *
 * Que estes testes possam existir É o resultado da fase: antes, `new Table(...)`
 * escolhia um driver a partir de `Config::getDriver()`, o driver construía um rastreador
 * de schema, e o construtor do rastreador executava cinco `CREATE TABLE IF NOT EXISTS`.
 * Definir schema exigia um banco no ar, e por isso nada disto era testável.
 *
 * `UnitTestCase` faz o teste falhar se alguma conexão for aberta, então a garantia não
 * depende de ninguém lembrar dela.
 */
final class TableBuilderTest extends UnitTestCase
{
    /**
     * O DSL exatamente como os models de verdade escrevem: colunas num mapa, foreign key
     * declarada na coluna que ela restringe.
     */
    private function city(): Table
    {
        return Table::make('city', comment: 'Cities table')
            ->columns([
                'id' => Col::id()->comment('City ID'),
                'name' => Col::varchar(120)->notNull()->comment('City name'),
                'state' => Col::int()->notNull()->references('state')->comment('State ID of the city'),
                'ibge' => Col::int()->unique()->comment('IBGE ID of the city'),
            ]);
    }

    public function testTheModelDslProducesTheExpectedDefinition(): void
    {
        $table = $this->city()->build();

        $this->assertSame('city', $table->name);
        $this->assertSame('Cities table', $table->comment);
        $this->assertSame(['id', 'name', 'state', 'ibge'], $table->getColumnNames());
        $this->assertSame(['id'], $table->getPrimaryKeyColumns());
        $this->assertTrue($table->hasAutoIncrement());
        $this->assertSame('id', $table->autoIncrementColumn()?->name);
    }

    /** A chave do mapa É o nome da coluna, e chega ao IR sem passar por lugar nenhum. */
    public function testTheMapKeyNamesTheColumn(): void
    {
        $table = Table::make('t')->columns(['user_id' => Col::int()->primary()])->build();

        $this->assertSame(['user_id'], $table->getColumnNames());
    }

    /**
     * Nome repetido é recusado com o nome dentro da mensagem.
     *
     * Num literal de array o segundo valor simplesmente sobrescreve o primeiro, sem aviso
     * do PHP — a checagem existe para o caso de as colunas virem de duas chamadas a
     * `columns()`, que é onde a duplicata é escrevível sem ser vista.
     */
    public function testADuplicateColumnNameIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/coluna duplicada \'nome\'/');

        Table::make('t')
            ->columns(['nome' => Col::varchar(20)])
            ->columns(['nome' => Col::varchar(40)]);
    }

    public function testATableWithoutColumnsIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/não declara nenhuma coluna/');

        Table::make('t')->build();
    }

    /**
     * `columns()` acumula, então um bloco comum pode ser composto fora do model.
     */
    public function testColumnsAccumulatesAcrossCalls(): void
    {
        $table = Table::make('t')
            ->columns(['id' => Col::id()])
            ->columns(['name' => Col::varchar(20)])
            ->build();

        $this->assertSame(['id', 'name'], $table->getColumnNames());
    }

    /**
     * A ordem das chamadas não importa.
     *
     * O builder antigo validava na chamada, então declarar um índice exigia que as colunas
     * já existissem. Validando o resultado pronto, declarar em qualquer ordem dá o mesmo IR.
     */
    public function testCallOrderDoesNotMatter(): void
    {
        $forward = Table::make('t')
            ->columns([
                'id' => Col::id(),
                'ref' => Col::int()->notNull(),
            ])
            ->index('t_ref_index', ['ref'])
            ->foreignKey('outra', 'ref')
            ->build();

        $backward = Table::make('t')
            ->foreignKey('outra', 'ref')
            ->index('t_ref_index', ['ref'])
            ->columns([
                'id' => Col::id(),
                'ref' => Col::int()->notNull(),
            ])
            ->build();

        // assertEquals: são objetos distintos com o mesmo conteúdo, e é o conteúdo que se
        // afirma aqui.
        $this->assertEquals($forward->indexes, $backward->indexes);
        $this->assertSame(array_keys($forward->foreignKeys), array_keys($backward->foreignKeys));
        $this->assertSame($forward->getColumnNames(), $backward->getColumnNames());
    }

    /**
     * O rastreador antigo indexava foreign keys pela coluna REFERENCIADA, que é `id` em
     * quase todo caso — então uma tabela com quatro referências para `id` de quatro
     * tabelas diferentes registrava só uma. É o bug B5.
     */
    public function testFourForeignKeysToTheSameColumnNameAllSurvive(): void
    {
        $table = Table::make('appointment')
            ->columns([
                'id' => Col::id(),
                'user_id' => Col::int()->notNull()->references('users'),
                'schedule_id' => Col::int()->notNull()->references('schedule'),
                'client_id' => Col::int()->references('client'),
                'employee_id' => Col::int()->notNull()->references('employee'),
            ])
            ->build();

        $this->assertCount(4, $table->foreignKeys);
        $this->assertSame(
            [
                'appointment_user_id_users_id_fk',
                'appointment_schedule_id_schedule_id_fk',
                'appointment_client_id_client_id_fk',
                'appointment_employee_id_employee_id_fk',
            ],
            array_keys($table->foreignKeys),
        );
    }

    /**
     * `engine` e `collate` default `null` = "default do servidor".
     *
     * Eram `InnoDB` e `utf8mb4_general_ci` cravados, e é a causa direta de B2: o default
     * assumido discordava permanentemente de um MySQL 8, cujo default é
     * `utf8mb4_0900_ai_ci`, e cada migrate gerava um ALTER que a comparação seguinte nunca
     * aceitava.
     */
    public function testTableOptionsDefaultToUnspecified(): void
    {
        $this->assertTrue(
            Table::make('t')->columns(['id' => Col::int()])->build()->options->isEmpty(),
        );

        $explicit = Table::make('t', engine: 'MyISAM', collate: 'utf8mb4_bin')
            ->columns(['id' => Col::int()])
            ->build();

        $this->assertSame('MyISAM', $explicit->options->engine);
        $this->assertSame('utf8mb4_bin', $explicit->options->collation);
    }

    /** `Col::primary()` em mais de uma coluna produz chave composta. */
    public function testMultiplePrimaryColumnsProduceACompositeKey(): void
    {
        $table = Table::make('product_category')
            ->columns([
                'product' => Col::int()->primary(),
                'category' => Col::int()->primary(),
            ])
            ->build();

        $this->assertSame(['product', 'category'], $table->getPrimaryKeyColumns());
        $this->assertTrue($table->primaryKey?->isComposite());
        $this->assertFalse($table->hasAutoIncrement());
    }

    /** `Table::primary()` declara a chave composta sem tocar nas colunas. */
    public function testTablePrimaryDeclaresACompositeKey(): void
    {
        $table = Table::make('t')
            ->columns([
                'a' => Col::int()->notNull(),
                'b' => Col::int()->notNull(),
            ])
            ->primary(['a', 'b'])
            ->build();

        $this->assertSame(['a', 'b'], $table->getPrimaryKeyColumns());
    }

    public function testUniqueOnAColumnBecomesAUniqueConstraint(): void
    {
        $table = $this->city()->build();

        $this->assertSame(['city_ibge_unique'], array_keys($table->uniqueConstraints));
        $this->assertSame(['ibge'], $table->uniqueConstraints['city_ibge_unique']->columns);
    }

    /** Restrição de unicidade multi-coluna, que a coluna sozinha não expressa. */
    public function testTableUniqueSupportsSeveralColumns(): void
    {
        $table = Table::make('t')
            ->columns([
                'a' => Col::int()->notNull(),
                'b' => Col::int()->notNull(),
            ])
            ->unique('t_a_b_unique', ['a', 'b'])
            ->build();

        $this->assertSame(['a', 'b'], $table->uniqueConstraints['t_a_b_unique']->columns);
        $this->assertSame([], $table->indexes);
    }

    /**
     * Índice de UMA coluna. O builder antigo recusava menos de duas, e a restrição era
     * arbitrária — índice de coluna única é o caso mais comum que existe.
     */
    public function testASingleColumnIndexIsAllowed(): void
    {
        $table = Table::make('t')
            ->columns([
                'id' => Col::id(),
                'name' => Col::varchar(60),
            ])
            ->index('t_name_index', ['name'])
            ->build();

        $this->assertSame(['name'], $table->indexes['t_name_index']->columns);
    }

    /** `Col::index()` sem nome deixa o `ConstraintNamer` decidir. */
    public function testAColumnLevelIndexIsNamedByTheNamer(): void
    {
        $table = Table::make('t')
            ->columns([
                'id' => Col::id(),
                'slug' => Col::varchar(60)->index(),
            ])
            ->build();

        $this->assertSame(['slug'], $table->indexes['t_slug_index']->columns);
        $this->assertFalse($table->indexes['t_slug_index']->unique);
    }

    public function testCheckConstraintsReachTheIr(): void
    {
        $table = Table::make('t')
            ->columns(['id' => Col::id(), 'qtd' => Col::int()->notNull()])
            ->check('qtd >= 0', 't_qtd_positiva')
            ->build();

        $this->assertSame('qtd >= 0', $table->checks['t_qtd_positiva']->expression);
    }

    /** `timestamps()` é açúcar, e o que ele produz está afirmado aqui em vez de implícito. */
    public function testTimestampsAddsTheTwoUsualColumns(): void
    {
        $table = Table::make('t')->columns(['id' => Col::id()])->timestamps()->build();

        $this->assertSame(['id', 'created_at', 'updated_at'], $table->getColumnNames());
        $this->assertTrue($table->column('created_at')?->notNull);
        $this->assertTrue($table->column('created_at')?->default->isExpression());
        $this->assertSame('CURRENT_TIMESTAMP', $table->column('created_at')?->default->value);

        // `updated_at` fica nulo e SEM default: `ON UPDATE CURRENT_TIMESTAMP` só existe no
        // MySQL, e o IR é neutro de dialeto.
        $this->assertFalse($table->column('updated_at')?->notNull);
        $this->assertTrue($table->column('updated_at')?->default->isNone());
    }

    public function testCompositeForeignKeysAreSupported(): void
    {
        $table = Table::make('t')
            ->columns([
                'a' => Col::int()->notNull(),
                'b' => Col::int()->notNull(),
            ])
            ->primary(['a', 'b'])
            ->foreignKey('outra', ['a', 'b'], ['x', 'y'])
            ->build();

        $foreignKey = array_values($table->foreignKeys)[0];

        $this->assertSame(['a', 'b'], $foreignKey->columns);
        $this->assertSame(['x', 'y'], $foreignKey->referencedColumns);
    }

    /**
     * Chave composta sem informar as colunas referenciadas é quase sempre erro de
     * digitação, e o default `'id'` do parâmetro esconderia isso.
     */
    public function testACompositeForeignKeyWithoutReferencedColumnsIsRefused(): void
    {
        $this->expectException(MigrationException::class);
        $this->expectExceptionMessageMatches('/mesma quantidade de colunas/');

        Table::make('t')
            ->columns([
                'a' => Col::int()->notNull(),
                'b' => Col::int()->notNull(),
            ])
            ->foreignKey('outra', ['a', 'b'])
            ->build();
    }

    /**
     * `RESTRICT` (o default histórico) e `NO ACTION` são o mesmo comportamento, e o IR os
     * dobra num só — manter distintos garantiria drift falso eterno num dos dois bancos.
     */
    public function testRestrictFoldsIntoNoAction(): void
    {
        $table = Table::make('t')
            ->columns([
                'id' => Col::id(),
                'ref' => Col::int()->notNull()->references('outra'),
            ])
            ->build();

        $this->assertSame(ReferentialAction::NoAction, array_values($table->foreignKeys)[0]->onDelete);
    }

    /** `onDelete` declarado no model chega ao IR — antes era descartado e virava RESTRICT. */
    public function testOnDeleteReachesTheIr(): void
    {
        $table = Table::make('t')
            ->columns([
                'id' => Col::id(),
                'ref' => Col::int()->references('outra', onDelete: 'CASCADE', onUpdate: 'SET NULL'),
            ])
            ->build();

        $foreignKey = array_values($table->foreignKeys)[0];

        $this->assertSame(ReferentialAction::Cascade, $foreignKey->onDelete);
        $this->assertSame(ReferentialAction::SetNull, $foreignKey->onUpdate);
    }

    public function testBuildIsMemoizedButInvalidatedByFurtherCalls(): void
    {
        $table = Table::make('t')->columns(['id' => Col::id()]);

        $first = $table->build();
        $this->assertSame($first, $table->build());

        $table->columns(['name' => Col::varchar(20)]);

        $this->assertNotSame($first, $table->build());
        $this->assertSame(['id', 'name'], $table->build()->getColumnNames());
    }

    /** As consultas puras continuam funcionando: elas nunca precisaram de banco. */
    public function testTheInformationalAccessorsStillWork(): void
    {
        $table = $this->city();

        $this->assertSame('city', $table->getTable());
        $this->assertTrue($table->getAutoIncrement());
        $this->assertTrue($table->hasForeignKey());
        $this->assertSame(['state'], $table->getForeignKeyTables());
        $this->assertSame(['id'], $table->getPrimaryKey());
        $this->assertSame(['id', 'name', 'state', 'ibge'], array_keys($table->getColumns()));
        $this->assertNull($table->getEngine());
        $this->assertSame('Cities table', $table->getComment());
    }

    // ------------------------------------------------------------- lápides

}
