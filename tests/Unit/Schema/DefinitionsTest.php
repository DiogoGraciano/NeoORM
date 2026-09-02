<?php

declare(strict_types=1);

namespace Tests\Unit\Schema;

use Diogodg\Neoorm\Schema\CheckConstraintDefinition;
use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;
use Diogodg\Neoorm\Schema\Exception\SchemaException;
use Diogodg\Neoorm\Schema\ForeignKeyDefinition;
use Diogodg\Neoorm\Schema\IndexDefinition;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;
use Tests\Support\Factory\Ir;
use Tests\Support\UnitTestCase;

final class DefinitionsTest extends UnitTestCase
{
    public function testBuildingSchemaOpensNoConnection(): void
    {
        Ir::geographySchema();

        // A guarda do UnitTestCase já cobre isto, mas dizer explicitamente
        // marca a propriedade de que tudo depende: definir schema é offline.
        $this->assertFalse(\Diogodg\Neoorm\Connection::isOpen());
    }

    // -------------------------------------------------------------- colunas

    public function testColumnNameIsNormalizedToLowercase(): void
    {
        $this->assertSame('nome', Ir::column('  Nome  ')->name);
    }

    public function testColumnRejectsAnInvalidName(): void
    {
        $this->expectException(InvalidIdentifierException::class);

        Ir::column('nome invalido');
    }

    public function testEmptyCommentBecomesNullSoItDoesNotLookLikeAChange(): void
    {
        // "sem comentário" e "comentário vazio" produzem o mesmo DDL; mantê-los
        // distintos no IR inventaria um diff a cada comparação.
        $this->assertNull(Ir::column('a', comment: '')->comment);
        $this->assertNull(Ir::column('a', comment: null)->comment);
    }

    public function testColumnDefaultsToNoDefaultNotToNull(): void
    {
        $this->assertTrue(Ir::column('a')->default->isNone());
    }

    public function testColumnWithersReturnCopies(): void
    {
        $original = Ir::column('a', 'INT');
        $renamed = $original->withName('b');

        $this->assertSame('a', $original->name);
        $this->assertSame('b', $renamed->name);
        $this->assertNotSame($original, $renamed);
    }

    public function testColumnEqualityComparesEveryAttribute(): void
    {
        $base = Ir::column('a', 'INT', notNull: true, comment: 'x');

        $this->assertTrue($base->equals(Ir::column('a', 'INT', notNull: true, comment: 'x')));
        $this->assertFalse($base->equals(Ir::column('a', 'BIGINT', notNull: true, comment: 'x')));
        $this->assertFalse($base->equals(Ir::column('a', 'INT', notNull: false, comment: 'x')));
        $this->assertFalse($base->equals(Ir::column('a', 'INT', notNull: true, comment: 'y')));
        $this->assertFalse($base->equals($base->withAutoIncrement(true)));
        $this->assertFalse($base->equals($base->withDefault(DefaultValue::literal(1))));
    }

    // -------------------------------------------------------------- tabelas

    public function testTableKeepsColumnsInDeclarationOrder(): void
    {
        // A ordem é de declaração, sempre. O sistema antigo reordenava a lista
        // quando via a PK, sustentando a convenção implícita "columns[0] é a
        // chave primária" — que quebrava se a PK não fosse declarada primeiro.
        $table = Ir::table('t', [
            Ir::column('zebra', 'INT'),
            Ir::id(),
            Ir::column('alfa', 'INT'),
        ]);

        $this->assertSame(['zebra', 'id', 'alfa'], $table->getColumnNames());
    }

    public function testPrimaryKeyIsAFieldNotAPosition(): void
    {
        $table = Ir::table('t', [Ir::column('nome', 'VARCHAR', 10), Ir::id()]);

        $this->assertSame(['id'], $table->getPrimaryKeyColumns());
        $this->assertNotSame('id', $table->getColumnNames()[0]);
    }

    public function testDuplicateColumnIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/duplicada/');

        Ir::table('t', [Ir::id(), Ir::column('id', 'INT')]);
    }

    public function testTableWithoutColumnsIsRejected(): void
    {
        $this->expectException(SchemaException::class);

        Ir::table('t', []);
    }

    public function testDuplicateIndexNameIsRejected(): void
    {
        $this->expectException(SchemaException::class);

        Ir::table('t', [Ir::id(), Ir::column('a', 'INT')], indexes: [
            new IndexDefinition('idx', ['a']),
            new IndexDefinition('idx', ['id']),
        ]);
    }

    public function testAutoIncrementLookupFindsTheColumnRegardlessOfPosition(): void
    {
        $table = Ir::table('t', [Ir::column('nome', 'VARCHAR', 10), Ir::id()]);

        $this->assertTrue($table->hasAutoIncrement());
        $this->assertSame('id', $table->autoIncrementColumn()?->name);
    }

    public function testTableWithoutAutoIncrementReportsNone(): void
    {
        $table = Ir::table('t', [
            Ir::column('codigo', 'VARCHAR', 64, notNull: true),
        ], primaryKey: ['codigo']);

        $this->assertFalse($table->hasAutoIncrement());
        $this->assertNull($table->autoIncrementColumn());
    }

    /**
     * Uma tabela não precisa existir antes de si mesma. Manter a auto
     * referência no grafo criaria um ciclo trivial em todo model com FK para a
     * própria tabela — o caso "categoria pai".
     */
    public function testSelfReferenceIsExcludedFromTheDependencyList(): void
    {
        $table = Ir::table('categoria', [Ir::id(), Ir::column('parent_id', 'INT')], foreignKeys: [
            Ir::foreignKey('categoria', ['parent_id'], 'categoria'),
        ]);

        $this->assertSame([], $table->referencedTables());
    }

    public function testReferencedTablesAreSortedAndDeduplicated(): void
    {
        $table = Ir::table('t', [
            Ir::id(),
            Ir::column('a', 'INT'),
            Ir::column('b', 'INT'),
            Ir::column('c', 'INT'),
        ], foreignKeys: [
            Ir::foreignKey('t', ['a'], 'zebra'),
            Ir::foreignKey('t', ['b'], 'alfa'),
            Ir::foreignKey('t', ['c'], 'zebra'),
        ]);

        $this->assertSame(['alfa', 'zebra'], $table->referencedTables());
    }

    // -------------------------------------------------------- foreign keys

    /**
     * Regressão do mapa chaveado pela coluna referenciada. Com o default `id`,
     * todas as FKs de uma tabela colidiam numa entrada só, e só a última era
     * rastreada — por isso três das quatro FKs de `appointment` sumiam.
     */
    public function testSeveralForeignKeysPointingAtIdCoexist(): void
    {
        $table = Ir::table('appointment', [
            Ir::id(),
            Ir::column('user_id', 'INT', notNull: true),
            Ir::column('schedule_id', 'INT', notNull: true),
            Ir::column('client_id', 'INT'),
            Ir::column('employee_id', 'INT', notNull: true),
        ], foreignKeys: [
            Ir::foreignKey('appointment', ['user_id'], 'users'),
            Ir::foreignKey('appointment', ['schedule_id'], 'schedule'),
            Ir::foreignKey('appointment', ['client_id'], 'client'),
            Ir::foreignKey('appointment', ['employee_id'], 'employee'),
        ]);

        $this->assertCount(4, $table->foreignKeys);
        $this->assertSame(['client', 'employee', 'schedule', 'users'], $table->referencedTables());
    }

    public function testForeignKeyWithMismatchedColumnCountsIsRejected(): void
    {
        $this->expectException(SchemaException::class);
        $this->expectExceptionMessageMatches('/não bate/');

        new ForeignKeyDefinition('fk', ['a', 'b'], 'outra', ['id']);
    }

    /**
     * MySQL reporta regra omitida como RESTRICT, PostgreSQL como NO ACTION.
     * Guardá-los distintos faria toda FK sem regra explícita parecer alterada
     * em todo check — e RESTRICT é justamente o default do DSL dos models.
     */
    public function testRestrictAndNoActionAreTheSameCanonicalAction(): void
    {
        $this->assertSame(ReferentialAction::NoAction, ReferentialAction::canonical('RESTRICT'));
        $this->assertSame(ReferentialAction::NoAction, ReferentialAction::canonical('NO ACTION'));
        $this->assertSame(ReferentialAction::NoAction, ReferentialAction::canonical(''));
        $this->assertSame(ReferentialAction::Cascade, ReferentialAction::canonical('cascade'));
        $this->assertSame(ReferentialAction::SetNull, ReferentialAction::canonical('set  null'));
    }

    public function testUnknownReferentialActionIsRejected(): void
    {
        $this->expectException(SchemaException::class);

        ReferentialAction::canonical('EXPLODE');
    }

    // --------------------------------------------------------------- schema

    public function testSchemaOrdersTablesByName(): void
    {
        $schema = Ir::schema([
            Ir::table('zebra', [Ir::id()]),
            Ir::table('alfa', [Ir::id()]),
        ]);

        $this->assertSame(['alfa', 'zebra'], $schema->tableNames());
    }

    public function testDuplicateTableIsRejected(): void
    {
        $this->expectException(SchemaException::class);

        Ir::schema([Ir::table('t', [Ir::id()]), Ir::table('t', [Ir::id()])]);
    }

    public function testDependencyGraphOnlyContainsTablesPresentInTheSchema(): void
    {
        // Uma referência para fora do schema não pode entrar no grafo: não há o
        // que ordenar antes dela, e o sort topológico dependeria de um nó
        // inexistente. Quem reclama da referência solta é o validador.
        $schema = Ir::schema([
            Ir::table('city', [Ir::id(), Ir::column('state', 'INT')], foreignKeys: [
                Ir::foreignKey('city', ['state'], 'state'),
            ]),
        ]);

        $this->assertSame(['city' => []], $schema->dependencyGraph());
    }

    public function testDependencyGraphLinksTablesThatDoExist(): void
    {
        $graph = Ir::geographySchema()->dependencyGraph();

        $this->assertSame([], $graph['country']);
        $this->assertSame(['country'], $graph['state']);
        $this->assertSame(['state'], $graph['city']);
    }

    public function testWithTableAndWithoutTableReturnCopies(): void
    {
        $schema = Ir::schema([Ir::table('a', [Ir::id()])]);
        $bigger = $schema->withTable(Ir::table('b', [Ir::id()]));
        $smaller = $bigger->withoutTable('a');

        $this->assertSame(['a'], $schema->tableNames());
        $this->assertSame(['a', 'b'], $bigger->tableNames());
        $this->assertSame(['b'], $smaller->tableNames());
    }

    // -------------------------------------------------------- serialização

    /**
     * A propriedade que sustenta o snapshot: serializar e desserializar não
     * pode perder nem inventar nada, e o JSON precisa ser idêntico byte a byte
     * nas duas voltas.
     */
    public function testSchemaSerializationRoundTripsExactly(): void
    {
        $schema = Ir::geographySchema();

        $once = $schema->toArray();
        $restored = SchemaDefinition::fromArray($once);
        $twice = $restored->toArray();

        $this->assertSame($once, $twice);
        $this->assertSame(
            json_encode($once, JSON_PRETTY_PRINT),
            json_encode($twice, JSON_PRETTY_PRINT),
        );
    }

    public function testRoundTripPreservesDeclarationOrderThroughColumnOrder(): void
    {
        // `columns` é serializado em ordem alfabética por determinismo, então a
        // ordem de declaração só sobrevive se `columnOrder` for respeitado na
        // volta. Sem isso, o ORM receberia as colunas embaralhadas.
        $table = Ir::table('t', [
            Ir::column('zebra', 'INT'),
            Ir::id(),
            Ir::column('alfa', 'INT'),
        ]);

        $restored = TableDefinition::fromArray($table->toArray());

        $this->assertSame(['zebra', 'id', 'alfa'], $restored->getColumnNames());
    }

    public function testRoundTripPreservesEveryConstraintKind(): void
    {
        $table = Ir::table('t', [
            Ir::id(),
            Ir::column('a', 'INT', notNull: true),
            Ir::column('b', 'VARCHAR', 20, default: DefaultValue::literal('x')),
        ],
            uniques: [Ir::unique('t', ['a'])],
            indexes: [Ir::index('t', ['b'])],
            foreignKeys: [Ir::foreignKey('t', ['a'], 'outra', ['id'], ReferentialAction::Cascade)],
            checks: [Ir::check('t', 'a > 0')],
            comment: 'tabela',
        );

        $restored = TableDefinition::fromArray($table->toArray());

        $this->assertSame($table->toArray(), $restored->toArray());
        $this->assertCount(1, $restored->uniqueConstraints);
        $this->assertCount(1, $restored->indexes);
        $this->assertCount(1, $restored->foreignKeys);
        $this->assertCount(1, $restored->checks);
        $this->assertSame(
            ReferentialAction::Cascade,
            $restored->foreignKeys[array_key_first($restored->foreignKeys)]->onDelete,
        );
    }

    /**
     * Determinismo: a ordem em que as coisas foram declaradas não pode aparecer
     * no JSON, exceto em `columnOrder`. É isso que impede um `generate` de
     * produzir diff onde não houve mudança nenhuma.
     */
    public function testSerializationIsIndependentOfDeclarationOrder(): void
    {
        $a = Ir::table('t', [Ir::id(), Ir::column('x', 'INT'), Ir::column('y', 'INT')], indexes: [
            Ir::index('t', ['x']),
            Ir::index('t', ['y']),
        ]);

        $b = Ir::table('t', [Ir::id(), Ir::column('x', 'INT'), Ir::column('y', 'INT')], indexes: [
            Ir::index('t', ['y']),
            Ir::index('t', ['x']),
        ]);

        $this->assertSame($a->toArray(), $b->toArray());
    }

    public function testPrimaryKeyEqualityIgnoresTheName(): void
    {
        // PostgreSQL nomeia `{tabela}_pkey`, MySQL chama de `PRIMARY`. Comparar
        // por nome faria toda tabela introspectada parecer divergente.
        $a = new PrimaryKeyDefinition('t_pk', ['id']);
        $b = new PrimaryKeyDefinition('t_pkey', ['id']);

        $this->assertTrue($a->equals($b));
        $this->assertFalse($a->equals(new PrimaryKeyDefinition('t_pk', ['id', 'outra'])));
    }

    /**
     * O PostgreSQL reescreve a expressão do CHECK ao guardá-la. Comparar o
     * texto cru geraria falso positivo em toda restrição.
     */
    public function testCheckComparisonIgnoresEngineReformatting(): void
    {
        $pairs = [
            ["status = 'ativo'", "((status)::text = 'ativo'::text)"],
            ['preco > 0', '(preco > (0)::numeric)'],
            ["nome <> ''", "((nome)::character varying <> ''::character varying)"],
        ];

        foreach ($pairs as [$declared, $introspected]) {
            $this->assertTrue(
                (new CheckConstraintDefinition('t_chk', $declared))
                    ->equals(new CheckConstraintDefinition('t_chk', $introspected)),
                "'{$declared}' e '{$introspected}' deveriam comparar iguais",
            );
        }
    }

    /**
     * A normalização remove parênteses redundantes em torno de um termo
     * sozinho, mas não em torno de subexpressões: fazê-lo em geral poderia
     * mudar a precedência dos operadores. `(a) or (b)` vira `a or b`;
     * `((a > 0) and (b < 10))` perde só os parênteses externos.
     *
     * O custo disso é um falso positivo conhecido — uma expressão escrita sem
     * parênteses não bate com a mesma expressão parentizada pelo banco. É
     * exatamente por isso que divergência de CHECK é reportada como aviso e
     * nunca vira ALTER automático.
     */
    public function testCheckNormalizationIsConservativeWithCompoundExpressions(): void
    {
        $this->assertSame(
            'a or b',
            (new CheckConstraintDefinition('c', '(a) or (b)'))->normalizedExpression(),
        );

        $this->assertSame(
            '(a > 0) and (b < 10)',
            (new CheckConstraintDefinition('c', '((a > 0) and (b < 10))'))->normalizedExpression(),
        );
    }

    public function testDifferentChecksDoNotNormalizeToEquality(): void
    {
        // Guarda contra a normalização virar tão agressiva que tudo se iguala.
        $this->assertFalse(
            (new CheckConstraintDefinition('c', 'a > 0'))
                ->equals(new CheckConstraintDefinition('c', 'a < 0')),
        );
    }

    public function testEmptyCheckExpressionIsRejected(): void
    {
        $this->expectException(SchemaException::class);

        new CheckConstraintDefinition('t_chk', '   ');
    }
}
