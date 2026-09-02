<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

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
use Diogodg\Neoorm\Migrations\Operation\RawSql;
use Diogodg\Neoorm\Migrations\Operation\RenameColumn;
use Diogodg\Neoorm\Migrations\Operation\RenameTable;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Migrations\Operation\SetTableComment;
use Diogodg\Neoorm\Migrations\Operation\SetTableOptions;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\Naming\ConstraintNamer;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\TableOptions;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;

/**
 * O catálogo de operações que os testes de dialeto percorrem.
 *
 * `catalog()` tem exatamente uma entrada por classe de operação, e um teste
 * confere isso varrendo o diretório `src/Migrations/Operation/`: acrescentar uma
 * operação sem acrescentá-la aqui derruba a suíte. É o que faz "todo dialeto
 * compila toda operação" ser uma prova de cobertura, e não uma amostra que
 * envelhece.
 *
 * Só tipos portáveis nas duas listas. As duas engines compilam o mesmo catálogo,
 * então um ENUM (que o PostgreSQL recusa) ou um JSONB (que o MySQL recusa) não
 * cabe aqui — divergência de tipo tem teste próprio em cada dialeto.
 */
final class Ops
{
    private function __construct()
    {
    }

    public static function productTable(): TableDefinition
    {
        return new TableDefinition(
            name: 'product',
            columns: [
                Ir::id(),
                Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'Nome do produto'),
                Ir::column('price', 'DECIMAL', '10,2', notNull: true, default: DefaultValue::literal(0)),
                Ir::column('active', 'BOOLEAN', notNull: true, default: DefaultValue::literal(true)),
                Ir::column('description', 'TEXT'),
                Ir::column(
                    'created_at',
                    'TIMESTAMP',
                    notNull: true,
                    default: DefaultValue::expression('CURRENT_TIMESTAMP'),
                ),
                Ir::column('category', 'INT'),
            ],
            primaryKey: new PrimaryKeyDefinition('product_pk', ['id']),
            comment: 'Catálogo de produtos',
            options: new TableOptions(engine: 'InnoDB'),
        );
    }

    /**
     * Uma operação por classe, em ordem fixa.
     *
     * A ordem não é a ordem em que rodariam numa migração de verdade — isso é
     * assunto do `OperationSorter`. É só uma ordem estável, para o golden file não
     * se reorganizar entre execuções.
     *
     * @return array<string,SchemaOperation>
     */
    public static function catalog(): array
    {
        $product = self::productTable();

        return [
            'create_table' => new CreateTable($product),
            'drop_table' => new DropTable('product'),
            'rename_table' => new RenameTable('product', 'item'),
            'set_table_comment' => new SetTableComment('product', 'Catálogo revisado'),
            'set_table_options' => new SetTableOptions(
                'product',
                new TableOptions(engine: 'InnoDB', collation: 'utf8mb4_0900_ai_ci'),
            ),
            'add_column' => new AddColumn(
                'product',
                Ir::column('sku', 'VARCHAR', 32, notNull: true, comment: 'Código do produto'),
            ),
            'drop_column' => new DropColumn('product', 'description'),
            'rename_column' => new RenameColumn('product', 'name', 'title'),
            'alter_column' => new AlterColumn(
                'product',
                Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'Nome do produto'),
                Ir::column('name', 'VARCHAR', 200, notNull: true, comment: 'Nome comercial'),
            ),
            'add_primary_key' => new AddPrimaryKey('product', new PrimaryKeyDefinition('product_pk', ['id'])),
            'drop_primary_key' => new DropPrimaryKey('product', 'product_pk'),
            'add_unique_constraint' => new AddUniqueConstraint('product', Ir::unique('product', ['sku'])),
            'drop_unique_constraint' => new DropUniqueConstraint('product', 'product_sku_unique'),
            'create_index' => new CreateIndex('product', Ir::index('product', ['category'])),
            'drop_index' => new DropIndex('product', 'product_category_index'),
            'add_foreign_key' => new AddForeignKey(
                'product',
                Ir::foreignKey('product', ['category'], 'category'),
            ),
            'drop_foreign_key' => new DropForeignKey('product', 'product_category_category_id_fk'),
            'add_check_constraint' => new AddCheckConstraint('product', Ir::check('product', 'price >= 0')),
            'drop_check_constraint' => new DropCheckConstraint(
                'product',
                ConstraintNamer::check('product', 'price >= 0'),
            ),
            'raw_sql' => new RawSql(
                'CREATE VIEW active_product AS SELECT id FROM product WHERE active = 1',
                'product',
            ),
        ];
    }

    /**
     * Os casos de borda que fazem a divergência entre os dois dialetos aparecer.
     *
     * Não entram na conferência de completude — entram no golden file, que é onde
     * dá para ler de uma vez o que cada engine faz com cada um deles.
     *
     * @return array<string,SchemaOperation>
     */
    public static function variants(): array
    {
        return [
            // Tabela sem comentário, sem opções e com PK composta sem auto
            // incremento: o caso de tabela de junção, e o caso em que o MySQL não
            // precisa da acrobacia do AUTO_INCREMENT.
            'create_table_composite_pk' => new CreateTable(new TableDefinition(
                name: 'product_category',
                columns: [
                    Ir::column('product', 'INT', notNull: true),
                    Ir::column('category', 'INT', notNull: true),
                ],
                primaryKey: new PrimaryKeyDefinition('product_category_pk', ['product', 'category']),
            )),

            // Sem chave primária nenhuma: legal nos dois bancos, e o gerador não
            // pode inventar uma.
            'create_table_without_primary_key' => new CreateTable(new TableDefinition(
                name: 'import_log',
                columns: [Ir::column('payload', 'TEXT'), Ir::column('at', 'TIMESTAMP')],
                primaryKey: null,
            )),

            // Nomes que são palavra reservada nas duas engines. Como tudo é sempre
            // citado, isto simplesmente funciona — não há lista de exceções para
            // manter atualizada.
            'create_table_reserved_words' => new CreateTable(new TableDefinition(
                name: 'order',
                columns: [
                    Ir::column('select', 'INT', notNull: true),
                    Ir::column('from', 'VARCHAR', 40),
                    Ir::column('default', 'BOOLEAN', notNull: true, default: DefaultValue::literal(false)),
                    Ir::column('user', 'VARCHAR', 40),
                ],
                primaryKey: new PrimaryKeyDefinition('order_pk', ['select']),
            )),

            // Comentário com tudo que pode quebrar escape: aspa simples, aspa
            // dupla, barra invertida, quebra de linha, acento e emoji.
            'create_table_hostile_comment' => new CreateTable(new TableDefinition(
                name: 'audit_log',
                columns: [
                    Ir::column('id', 'BIGINT', notNull: true, autoIncrement: true),
                    Ir::column(
                        'detail',
                        'TEXT',
                        comment: "aspa ' dupla \" barra \\ nova\nlinha acentuação 🎯",
                    ),
                ],
                primaryKey: new PrimaryKeyDefinition('audit_log_pk', ['id']),
            )),

            'set_table_comment_removed' => new SetTableComment('product', null),

            // Tudo nulo significa "default do servidor". O MySQL não tem o que
            // emitir e o PostgreSQL nunca teve.
            'set_table_options_empty' => new SetTableOptions('product', new TableOptions()),

            'add_column_default_empty_string' => new AddColumn(
                'product',
                Ir::column('note', 'VARCHAR', 80, notNull: true, default: DefaultValue::literal('')),
            ),
            'add_column_expression_default' => new AddColumn(
                'product',
                Ir::column(
                    'updated_at',
                    'TIMESTAMP',
                    notNull: true,
                    default: DefaultValue::expression('CURRENT_TIMESTAMP'),
                ),
            ),

            // O caso que obriga o PostgreSQL a tirar o default antes de trocar o
            // tipo: um default de texto não converte automaticamente para inteiro.
            'alter_column_type_and_default' => new AlterColumn(
                'product',
                Ir::column('code', 'VARCHAR', 10, notNull: true, default: DefaultValue::literal('0')),
                Ir::column('code', 'INT', notNull: true, default: DefaultValue::literal(0)),
            ),
            'alter_column_comment_only' => new AlterColumn(
                'product',
                Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'antes'),
                Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'depois'),
            ),
            'alter_column_drop_not_null' => new AlterColumn(
                'product',
                Ir::column('description', 'TEXT', notNull: true),
                Ir::column('description', 'TEXT'),
            ),
            'alter_column_drop_default' => new AlterColumn(
                'product',
                Ir::column('price', 'DECIMAL', '10,2', notNull: true, default: DefaultValue::literal(0)),
                Ir::column('price', 'DECIMAL', '10,2', notNull: true),
            ),
            'alter_column_add_identity' => new AlterColumn(
                'product',
                Ir::column('id', 'INT', notNull: true),
                Ir::column('id', 'INT', notNull: true, autoIncrement: true),
            ),
            'alter_column_drop_identity' => new AlterColumn(
                'product',
                Ir::column('id', 'INT', notNull: true, autoIncrement: true),
                Ir::column('id', 'INT', notNull: true),
            ),

            // Onde o MySQL precisa remover o AUTO_INCREMENT antes de soltar a PK.
            'drop_primary_key_with_auto_increment' => new DropPrimaryKey(
                'product',
                'product_pk',
                Ir::id(),
            ),

            'create_index_unique' => new CreateIndex('product', Ir::index('product', ['sku'], unique: true)),
            'create_index_multi_column' => new CreateIndex(
                'product',
                Ir::index('product', ['category', 'name']),
            ),

            'add_foreign_key_cascade' => new AddForeignKey('product', Ir::foreignKey(
                'product',
                ['category'],
                'category',
                onDelete: ReferentialAction::Cascade,
                onUpdate: ReferentialAction::Cascade,
            )),
            'add_foreign_key_set_null' => new AddForeignKey('product', Ir::foreignKey(
                'product',
                ['category'],
                'category',
                onDelete: ReferentialAction::SetNull,
            )),
            'add_foreign_key_composite' => new AddForeignKey('product_category', Ir::foreignKey(
                'product_category',
                ['product', 'category'],
                'catalog_entry',
                ['product', 'category'],
                onDelete: ReferentialAction::Cascade,
            )),

            // Auto-referência: SQL perfeitamente legítimo, e legítimo justamente
            // porque toda FK sai na fase P8, depois de a tabela existir.
            'add_foreign_key_self_reference' => new AddForeignKey('category', Ir::foreignKey(
                'category',
                ['parent'],
                'category',
                onDelete: ReferentialAction::SetNull,
            )),
        ];
    }

    /**
     * @return array<string,ColumnDefinition>
     */
    public static function columnVariants(): array
    {
        return [
            'int_auto_increment' => Ir::id(),
            'varchar_not_null_comment' => Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'Nome'),
            'varchar_nullable' => Ir::column('nickname', 'VARCHAR', 40),
            'text_nullable' => Ir::column('body', 'TEXT'),
            'decimal' => Ir::column('price', 'DECIMAL', '10,2', notNull: true),
            'boolean_default_true' => Ir::column('active', 'BOOLEAN', notNull: true, default: DefaultValue::literal(true)),
            'boolean_default_false' => Ir::column('locked', 'BOOLEAN', notNull: true, default: DefaultValue::literal(false)),
            'int_default_zero' => Ir::column('hits', 'INT', notNull: true, default: DefaultValue::literal(0)),
            'int_default_negative' => Ir::column('balance', 'INT', notNull: true, default: DefaultValue::literal(-5)),
            'float_default' => Ir::column('ratio', 'DOUBLE', notNull: true, default: DefaultValue::literal(0.5)),
            'string_default_with_quote' => Ir::column(
                'motto',
                'VARCHAR',
                60,
                notNull: true,
                default: DefaultValue::literal("d'água"),
            ),
            'default_expression' => Ir::column(
                'created_at',
                'TIMESTAMP',
                notNull: true,
                default: DefaultValue::expression('CURRENT_TIMESTAMP'),
            ),
            'bigint_unsigned_free' => Ir::column('bytes', 'BIGINT', notNull: true),
            'char_fixed' => Ir::column('hash', 'CHAR', 64, notNull: true),
            'date' => Ir::column('born_on', 'DATE'),
            'json' => Ir::column('payload', 'JSON'),
        ];
    }
}
