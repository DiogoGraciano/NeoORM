<?php

declare(strict_types=1);

namespace Tests\Integration\Dialect;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Operation\AddCheckConstraint;
use Diogodg\Neoorm\Migrations\Operation\AddColumn;
use Diogodg\Neoorm\Migrations\Operation\AddForeignKey;
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
use Diogodg\Neoorm\Migrations\Operation\RenameColumn;
use Diogodg\Neoorm\Migrations\Operation\RenameTable;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Migrations\Operation\SetTableComment;
use Diogodg\Neoorm\Migrations\Operation\SetTableOptions;
use Diogodg\Neoorm\Schema\Naming\ConstraintNamer;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\TableOptions;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;
use PDO;
use Throwable;
use Tests\Support\DatabaseTestCase;
use Tests\Support\Factory\Ir;
use Tests\Support\Factory\Ops;

/**
 * O servidor aceita cada statement que os dialetos geram?
 *
 * Os testes unitários de dialeto afirmam qual SQL sai; este afirma que o SQL sai
 * *válido*. São perguntas diferentes, e a segunda não dá para responder sem um
 * servidor: uma suíte de geração de DDL 100% verde que produz sintaxe recusada
 * pelo banco é exatamente o tipo de confiança falsa que este projeto está
 * desfazendo.
 *
 * A sequência é uma migração de verdade — cria, altera, restringe, desfaz —
 * porque metade dos statements interessantes só é válida sobre um estado
 * específico: `DROP PRIMARY KEY` precisa de uma PK, e no MySQL precisa que o
 * AUTO_INCREMENT tenha saído antes.
 *
 * Roda contra um dialeto por processo, o que a configuração do PHPUnit escolhe.
 */
final class GeneratedSqlIsAcceptedTest extends DatabaseTestCase
{
    /** @var list<string> */
    private const SCRATCH_TABLES = [
        'gsa_product_category',
        'gsa_product',
        'gsa_catalog_entry',
        'gsa_category',
        'gsa_import_log',
        'gsa_import_journal',
        'gsa_order',
        'gsa_audit_log',
        'gsa_migrations',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->dropScratchTables();
    }

    protected function tearDown(): void
    {
        $this->dropScratchTables();

        parent::tearDown();
    }

    /**
     * A tabela de controle roda antes de qualquer bookkeeping existir para dizer se
     * ela já rodou, então precisa ser idempotente de verdade — e não "idempotente
     * se ninguém tiver rodado antes", que é o que um `CREATE TABLE` mais um
     * `ADD CONSTRAINT` separado daria.
     */
    public function testTheMigrationsTableDdlCanRunTwice(): void
    {
        $dialect = $this->dialect();

        foreach ([1, 2] as $pass) {
            foreach ($dialect->migrationsTableDdl('gsa_migrations') as $sql) {
                $this->assertStatementIsAccepted($sql, "tabela de controle, passe {$pass}");
            }
        }
    }

    public function testEveryStatementOfARealMigrationSequenceIsAccepted(): void
    {
        $dialect = $this->dialect();
        $applied = 0;

        foreach ($this->migrationSequence() as $operation) {
            foreach ($dialect->compile($operation) as $sql) {
                $this->assertStatementIsAccepted($sql, $operation->describe());
                $applied++;
            }
        }

        // Guarda contra o teste se esvaziar em silêncio: uma sequência que deixasse
        // de gerar statements passaria sem afirmar nada.
        $this->assertGreaterThan(40, $applied);
    }

    private function assertStatementIsAccepted(string $sql, string $what): void
    {
        try {
            $this->pdo()->exec($sql);
            $this->addToAssertionCount(1);
        } catch (Throwable $e) {
            $this->fail(
                "O {$this->driver()} recusou o SQL gerado para: {$what}\n"
                . "SQL: {$sql}\n"
                . "Erro: {$e->getMessage()}",
            );
        }
    }

    /**
     * Uma migração plausível, do zero até desfazer tudo.
     *
     * A ordem importa e é a mesma que o `OperationSorter` produzirá na fase 7: as
     * tabelas referenciadas primeiro, as foreign keys depois de todas as colunas
     * existirem, e as remoções na ordem inversa.
     *
     * @return list<SchemaOperation>
     */
    private function migrationSequence(): array
    {
        $category = new TableDefinition(
            name: 'gsa_category',
            columns: [
                Ir::id(),
                Ir::column('name', 'VARCHAR', 60, notNull: true),
                Ir::column('parent', 'INT'),
            ],
            primaryKey: new PrimaryKeyDefinition('gsa_category_pk', ['id']),
            comment: 'Categorias',
        );

        $catalogEntry = new TableDefinition(
            name: 'gsa_catalog_entry',
            columns: [
                Ir::column('product', 'INT', notNull: true),
                Ir::column('category', 'INT', notNull: true),
            ],
            primaryKey: new PrimaryKeyDefinition('gsa_catalog_entry_pk', ['product', 'category']),
        );

        $product = new TableDefinition(
            name: 'gsa_product',
            columns: array_values(Ops::productTable()->getColumns()),
            primaryKey: new PrimaryKeyDefinition('gsa_product_pk', ['id']),
            comment: 'Catálogo de produtos',
            options: new TableOptions(engine: 'InnoDB'),
        );

        $junction = new TableDefinition(
            name: 'gsa_product_category',
            columns: [
                Ir::column('product', 'INT', notNull: true),
                Ir::column('category', 'INT', notNull: true),
            ],
            primaryKey: new PrimaryKeyDefinition('gsa_product_category_pk', ['product', 'category']),
        );

        // Tabela sem PK, nome e colunas que são palavra reservada, e um comentário
        // com tudo que pode quebrar escape.
        $importLog = new TableDefinition(
            name: 'gsa_import_log',
            columns: [Ir::column('payload', 'TEXT'), Ir::column('at', 'TIMESTAMP')],
            primaryKey: null,
        );

        $reserved = new TableDefinition(
            name: 'gsa_order',
            columns: [
                Ir::column('select', 'INT', notNull: true),
                Ir::column('from', 'VARCHAR', 40),
                Ir::column('default', 'BOOLEAN', notNull: true, default: DefaultValue::literal(false)),
                Ir::column('user', 'VARCHAR', 40),
            ],
            primaryKey: new PrimaryKeyDefinition('gsa_order_pk', ['select']),
        );

        $hostile = new TableDefinition(
            name: 'gsa_audit_log',
            columns: [
                Ir::column('id', 'BIGINT', notNull: true, autoIncrement: true),
                Ir::column('detail', 'TEXT', comment: "aspa ' dupla \" barra \\ nova\nlinha acentuação 🎯"),
            ],
            primaryKey: new PrimaryKeyDefinition('gsa_audit_log_pk', ['id']),
        );

        $code = static fn (string $type, string|int|null $size, DefaultValue $default) => Ir::column(
            'code',
            $type,
            $size,
            notNull: true,
            default: $default,
        );

        return [
            new CreateTable($category),
            new CreateTable($catalogEntry),
            new CreateTable($product),
            new CreateTable($junction),
            new CreateTable($importLog),
            new CreateTable($reserved),
            new CreateTable($hostile),

            new SetTableComment('gsa_product', 'Catálogo revisado'),
            new SetTableComment('gsa_product', null),
            new SetTableOptions('gsa_product', new TableOptions(engine: 'InnoDB')),
            new SetTableOptions('gsa_product', new TableOptions()),

            new AddColumn('gsa_product', Ir::column('sku', 'VARCHAR', 32, notNull: true, comment: 'Código')),
            new AddColumn('gsa_product', Ir::column('note', 'VARCHAR', 80, default: DefaultValue::null())),
            new AddColumn('gsa_product', Ir::column(
                'updated_at',
                'TIMESTAMP',
                notNull: true,
                default: DefaultValue::expression('CURRENT_TIMESTAMP'),
            )),
            new AddColumn('gsa_product', $code('VARCHAR', 10, DefaultValue::literal('0'))),

            new AddUniqueConstraint('gsa_product', Ir::unique('gsa_product', ['sku'])),
            new CreateIndex('gsa_product', Ir::index('gsa_product', ['category'])),
            new CreateIndex('gsa_product', Ir::index('gsa_product', ['category', 'name'])),
            new AddCheckConstraint('gsa_product', Ir::check('gsa_product', 'price >= 0')),

            new AddForeignKey('gsa_product', Ir::foreignKey('gsa_product', ['category'], 'gsa_category')),
            // Auto-referência: legítima justamente porque a FK vem depois da tabela.
            new AddForeignKey('gsa_category', Ir::foreignKey(
                'gsa_category',
                ['parent'],
                'gsa_category',
                onDelete: ReferentialAction::SetNull,
            )),
            new AddForeignKey('gsa_product_category', Ir::foreignKey(
                'gsa_product_category',
                ['product', 'category'],
                'gsa_catalog_entry',
                ['product', 'category'],
                onDelete: ReferentialAction::Cascade,
            )),

            // Cada aspecto de AlterColumn, e o caso em que o default bloqueia a
            // conversão de tipo no PostgreSQL.
            new AlterColumn(
                'gsa_product',
                Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'Nome do produto'),
                Ir::column('name', 'VARCHAR', 200, notNull: true, comment: 'Nome comercial'),
            ),
            new AlterColumn(
                'gsa_product',
                Ir::column('description', 'TEXT'),
                Ir::column('description', 'TEXT', comment: 'Descrição'),
            ),
            new AlterColumn(
                'gsa_product',
                $code('VARCHAR', 10, DefaultValue::literal('0')),
                $code('INT', null, DefaultValue::literal(0)),
            ),
            new AlterColumn(
                'gsa_product',
                Ir::column('price', 'DECIMAL', '10,2', notNull: true, default: DefaultValue::literal(0)),
                Ir::column('price', 'DECIMAL', '10,2', notNull: true),
            ),

            new RenameColumn('gsa_product', 'name', 'title'),
            new RenameTable('gsa_import_log', 'gsa_import_journal'),

            new DropCheckConstraint('gsa_product', ConstraintNamer::check('gsa_product', 'price >= 0')),
            new DropIndex('gsa_product', ConstraintNamer::index('gsa_product', ['category', 'name'])),
            new DropForeignKey(
                'gsa_product',
                ConstraintNamer::foreignKey('gsa_product', ['category'], 'gsa_category', ['id']),
            ),
            new DropIndex('gsa_product', ConstraintNamer::index('gsa_product', ['category'])),
            new DropUniqueConstraint('gsa_product', ConstraintNamer::unique('gsa_product', ['sku'])),
            new DropColumn('gsa_product', 'note'),

            // No MySQL isto exige tirar o AUTO_INCREMENT primeiro; no PostgreSQL é
            // um DROP CONSTRAINT e nada mais.
            new DropPrimaryKey('gsa_product', 'gsa_product_pk', Ir::id()),

            new DropTable('gsa_product_category'),
            new DropTable('gsa_product'),
            new DropTable('gsa_catalog_entry'),
            new DropTable('gsa_category'),
            new DropTable('gsa_import_journal'),
            new DropTable('gsa_order'),
            new DropTable('gsa_audit_log'),
        ];
    }

    private function dialect(): Dialect
    {
        return DialectFactory::for($this->driver());
    }

    /**
     * Limpeza tolerante: aqui o `IF EXISTS` é legítimo, porque não é uma migração
     * descrevendo uma transição — é um teste garantindo que começa do zero
     * independentemente de como o anterior terminou.
     */
    private function dropScratchTables(): void
    {
        $pdo = $this->pdo();
        $previous = $pdo->getAttribute(PDO::ATTR_ERRMODE);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_SILENT);

        try {
            foreach (self::SCRATCH_TABLES as $table) {
                $pdo->exec('DROP TABLE IF EXISTS ' . $this->dialect()->quoteIdentifier($table) . ' CASCADE');
            }
        } finally {
            $pdo->setAttribute(PDO::ATTR_ERRMODE, $previous);
        }
    }
}
