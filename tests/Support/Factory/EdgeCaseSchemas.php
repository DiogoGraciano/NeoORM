<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\TableOptions;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;

/**
 * Schemas nomeados que exercitam cada canto do IR.
 *
 * São a entrada das duas propriedades que sustentam o sistema: `diff(s, s)` é
 * vazio para todos eles, e `toJson(fromJson(toJson(s)))` é igual byte a byte para
 * todos eles. Um caso que sobrevive a esses dois testes é um caso que não vai
 * produzir migração espúria.
 *
 * Só tipos portáveis, para que os mesmos schemas sirvam depois aos testes de
 * round-trip nos dois bancos.
 */
final class EdgeCaseSchemas
{
    private function __construct()
    {
    }

    /**
     * @return array<string,SchemaDefinition>
     */
    public static function all(): array
    {
        return [
            'empty' => SchemaDefinition::empty(),
            'single_table' => self::singleTable(),
            'geography' => Ir::geographySchema(),
            'default_variants' => self::defaultVariants(),
            'nullable_matrix' => self::nullableMatrix(),
            'type_variants' => self::typeVariants(),
            'index_variants' => self::indexVariants(),
            'unique_variants' => self::uniqueVariants(),
            'pk_variants' => self::primaryKeyVariants(),
            'fk_variants' => self::foreignKeyVariants(),
            'check_variants' => self::checkVariants(),
            'comment_variants' => self::commentVariants(),
            'table_options' => self::tableOptions(),
            'truncation_collision' => self::truncationCollision(),
            'cyclic' => self::cyclic(),
            'self_reference' => self::selfReference(),
            'deep_chain' => self::deepChain(),
            'reserved_words' => self::reservedWords(),
            'composite_everything' => self::compositeEverything(),
        ];
    }

    public static function get(string $name): SchemaDefinition
    {
        return self::all()[$name] ?? throw new \InvalidArgumentException("Schema desconhecido: '{$name}'");
    }

    private static function singleTable(): SchemaDefinition
    {
        return Ir::schema([Ir::table('city', [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)])]);
    }

    /**
     * Os tipos de literal que o JSON precisa preservar. `1.0` é o caso que exige
     * `JSON_PRESERVE_ZERO_FRACTION`: sem ele viraria `1` e o snapshot deixaria de bater
     * com ele mesmo.
     *
     * `DEFAULT NULL` em coluna nullable NÃO está aqui de propósito: nenhum dos dois
     * bancos guarda a diferença entre ela e ausência de default, então o estado não é
     * representável e o SchemaValidator o recusa. Um schema inválido não serve de
     * fixture para round-trip.
     */
    private static function defaultVariants(): SchemaDefinition
    {
        return Ir::schema([Ir::table('setting', [
            Ir::id(),
            Ir::column('no_default', 'VARCHAR', 40),
            Ir::column('empty_string', 'VARCHAR', 40, notNull: true, default: DefaultValue::literal('')),
            Ir::column('text_literal', 'VARCHAR', 40, notNull: true, default: DefaultValue::literal('scheduled')),
            Ir::column('quoted_literal', 'VARCHAR', 40, notNull: true, default: DefaultValue::literal("d'água")),
            Ir::column('zero', 'INT', notNull: true, default: DefaultValue::literal(0)),
            Ir::column('negative', 'INT', notNull: true, default: DefaultValue::literal(-42)),
            Ir::column('float_whole', 'DOUBLE', notNull: true, default: DefaultValue::literal(1.0)),
            Ir::column('float_fraction', 'DOUBLE', notNull: true, default: DefaultValue::literal(0.125)),
            Ir::column('true_flag', 'BOOLEAN', notNull: true, default: DefaultValue::literal(true)),
            Ir::column('false_flag', 'BOOLEAN', notNull: true, default: DefaultValue::literal(false)),
            Ir::column(
                'now',
                'TIMESTAMP',
                notNull: true,
                default: DefaultValue::expression('CURRENT_TIMESTAMP'),
            ),
        ])]);
    }

    private static function nullableMatrix(): SchemaDefinition
    {
        return Ir::schema([Ir::table('flags', [
            Ir::id(),
            Ir::column('null_no_default', 'VARCHAR', 20),
            Ir::column('null_with_default', 'VARCHAR', 20, default: DefaultValue::literal('x')),
            Ir::column('notnull_no_default', 'VARCHAR', 20, notNull: true),
            Ir::column('notnull_with_default', 'VARCHAR', 20, notNull: true, default: DefaultValue::literal('x')),
        ])]);
    }

    private static function typeVariants(): SchemaDefinition
    {
        return Ir::schema([Ir::table('kinds', [
            Ir::id(),
            Ir::column('small', 'SMALLINT'),
            Ir::column('big', 'BIGINT'),
            Ir::column('money', 'DECIMAL', '10,2'),
            Ir::column('precise', 'DECIMAL', '19,6'),
            Ir::column('ratio', 'DOUBLE'),
            Ir::column('flag', 'BOOLEAN'),
            Ir::column('fixed', 'CHAR', 64),
            Ir::column('short', 'VARCHAR', 1),
            Ir::column('long', 'VARCHAR', 4000),
            Ir::column('body', 'TEXT'),
            Ir::column('born_on', 'DATE'),
            Ir::column('at', 'TIME'),
            Ir::column('stamped', 'TIMESTAMP'),
            Ir::column('payload', 'JSON'),
        ])]);
    }

    /**
     * Inclui índice de UMA coluna: era proibido pelo builder antigo e, quando
     * declarado, nunca chegava a ser criado num schema já existente.
     */
    private static function indexVariants(): SchemaDefinition
    {
        return Ir::schema([Ir::table(
            'article',
            [
                Ir::id(),
                Ir::column('slug', 'VARCHAR', 120, notNull: true),
                Ir::column('author', 'INT', notNull: true),
                Ir::column('published_at', 'TIMESTAMP'),
            ],
            uniques: [Ir::unique('article', ['published_at'])],
            indexes: [
                Ir::index('article', ['slug']),
                Ir::index('article', ['author', 'published_at']),
            ],
        )]);
    }

    private static function uniqueVariants(): SchemaDefinition
    {
        return Ir::schema([Ir::table(
            'account',
            [
                Ir::id(),
                Ir::column('email', 'VARCHAR', 190, notNull: true),
                Ir::column('tenant', 'INT', notNull: true),
                Ir::column('handle', 'VARCHAR', 60, notNull: true),
            ],
            uniques: [
                Ir::unique('account', ['email']),
                Ir::unique('account', ['tenant', 'handle']),
            ],
        )]);
    }

    /**
     * PK simples, PK composta sem auto incremento, PK natural de texto, e tabela sem
     * PK nenhuma — legal nos dois bancos, e o gerador não pode inventar uma.
     */
    private static function primaryKeyVariants(): SchemaDefinition
    {
        return Ir::schema([
            Ir::table('simple_pk', [Ir::id(), Ir::column('name', 'VARCHAR', 40, notNull: true)]),

            new TableDefinition(
                name: 'composite_pk',
                columns: [
                    Ir::column('left_side', 'INT', notNull: true),
                    Ir::column('right_side', 'INT', notNull: true),
                    Ir::column('weight', 'INT'),
                ],
                primaryKey: new PrimaryKeyDefinition('composite_pk_pk', ['left_side', 'right_side']),
            ),

            new TableDefinition(
                name: 'natural_pk',
                columns: [
                    Ir::column('code', 'VARCHAR', 64, notNull: true),
                    Ir::column('value', 'VARCHAR', 255, notNull: true, default: DefaultValue::literal('')),
                ],
                primaryKey: new PrimaryKeyDefinition('natural_pk_pk', ['code']),
            ),

            new TableDefinition(
                name: 'no_pk',
                columns: [Ir::column('payload', 'TEXT'), Ir::column('at', 'TIMESTAMP')],
                primaryKey: null,
            ),
        ]);
    }

    /**
     * Uma FK por ação referencial, duas FKs da mesma tabela para a mesma tabela
     * (o cenário em que o rastreador antigo só registrava uma), e FK composta.
     */
    private static function foreignKeyVariants(): SchemaDefinition
    {
        return Ir::schema([
            Ir::table('target', [Ir::id(), Ir::column('name', 'VARCHAR', 40, notNull: true)]),

            new TableDefinition(
                name: 'pair_target',
                columns: [
                    Ir::column('left_side', 'INT', notNull: true),
                    Ir::column('right_side', 'INT', notNull: true),
                ],
                primaryKey: new PrimaryKeyDefinition('pair_target_pk', ['left_side', 'right_side']),
            ),

            Ir::table(
                'source',
                [
                    Ir::id(),
                    Ir::column('cascaded', 'INT', notNull: true),
                    Ir::column('nulled', 'INT'),
                    Ir::column('defaulted', 'INT'),
                    Ir::column('untouched', 'INT', notNull: true),
                    Ir::column('left_side', 'INT', notNull: true),
                    Ir::column('right_side', 'INT', notNull: true),
                ],
                foreignKeys: [
                    Ir::foreignKey('source', ['cascaded'], 'target', onDelete: ReferentialAction::Cascade),
                    Ir::foreignKey('source', ['nulled'], 'target', onDelete: ReferentialAction::SetNull),
                    Ir::foreignKey('source', ['defaulted'], 'target', onDelete: ReferentialAction::SetDefault),
                    Ir::foreignKey('source', ['untouched'], 'target'),
                    Ir::foreignKey(
                        'source',
                        ['left_side', 'right_side'],
                        'pair_target',
                        ['left_side', 'right_side'],
                        onDelete: ReferentialAction::Cascade,
                        onUpdate: ReferentialAction::Cascade,
                    ),
                ],
            ),
        ]);
    }

    private static function checkVariants(): SchemaDefinition
    {
        return Ir::schema([Ir::table(
            'product',
            [
                Ir::id(),
                Ir::column('price', 'DECIMAL', '10,2', notNull: true),
                Ir::column('status', 'VARCHAR', 20, notNull: true),
            ],
            checks: [
                Ir::check('product', 'price >= 0'),
                Ir::check('product', "status in ('novo', 'usado')"),
            ],
        )]);
    }

    private static function commentVariants(): SchemaDefinition
    {
        return Ir::schema([Ir::table(
            'documented',
            [
                Ir::id(),
                Ir::column('plain', 'VARCHAR', 40, comment: 'Comentário simples'),
                Ir::column('accented', 'VARCHAR', 40, comment: 'Acentuação e cedilha: ção'),
                Ir::column('quoted', 'VARCHAR', 40, comment: "com aspa ' simples"),
                Ir::column('double_quoted', 'VARCHAR', 40, comment: 'com aspa " dupla'),
                Ir::column('backslash', 'VARCHAR', 40, comment: 'com barra \\ invertida'),
                Ir::column('multiline', 'VARCHAR', 40, comment: "primeira linha\nsegunda linha"),
                Ir::column('emoji', 'VARCHAR', 40, comment: 'com emoji 🎯 no meio'),
                Ir::column('no_comment', 'VARCHAR', 40),
            ],
            comment: 'Tabela com comentários hostis: aspa \' e barra \\',
        )]);
    }

    private static function tableOptions(): SchemaDefinition
    {
        return Ir::schema([
            Ir::table('server_default', [Ir::id()], options: new TableOptions()),
            Ir::table('explicit_engine', [Ir::id()], options: new TableOptions(engine: 'InnoDB')),
            Ir::table('explicit_collation', [Ir::id()], options: new TableOptions(collation: 'utf8mb4_bin')),
            Ir::table(
                'both_options',
                [Ir::id()],
                options: new TableOptions(engine: 'InnoDB', collation: 'utf8mb4_0900_ai_ci'),
            ),
        ]);
    }

    /**
     * Duas foreign keys cujos nomes gerados só diferem depois do ponto em que o
     * truncamento em 63 caracteres corta. Sem o hash no fim, as duas colapsariam no
     * mesmo identificador e a segunda falharia no banco — ou pior, sobrescreveria a
     * primeira.
     */
    private static function truncationCollision(): SchemaDefinition
    {
        $long = str_repeat('a', 40);

        return Ir::schema([
            Ir::table($long . '_target', [Ir::id()]),
            Ir::table(
                $long . '_source',
                [
                    Ir::id(),
                    Ir::column($long . '_primeira_coluna', 'INT', notNull: true),
                    Ir::column($long . '_segunda_coluna', 'INT', notNull: true),
                ],
                foreignKeys: [
                    Ir::foreignKey($long . '_source', [$long . '_primeira_coluna'], $long . '_target'),
                    Ir::foreignKey($long . '_source', [$long . '_segunda_coluna'], $long . '_target'),
                ],
            ),
        ]);
    }

    /** Foreign key mútua: SQL legítimo, porque toda FK é adicionada depois das tabelas. */
    private static function cyclic(): SchemaDefinition
    {
        return Ir::schema([
            Ir::table(
                'cyclic_a',
                [Ir::id(), Ir::column('b', 'INT')],
                foreignKeys: [Ir::foreignKey('cyclic_a', ['b'], 'cyclic_b', onDelete: ReferentialAction::SetNull)],
            ),
            Ir::table(
                'cyclic_b',
                [Ir::id(), Ir::column('a', 'INT')],
                foreignKeys: [Ir::foreignKey('cyclic_b', ['a'], 'cyclic_a', onDelete: ReferentialAction::SetNull)],
            ),
        ]);
    }

    private static function selfReference(): SchemaDefinition
    {
        return Ir::schema([Ir::table(
            'category',
            [Ir::id(), Ir::column('name', 'VARCHAR', 60, notNull: true), Ir::column('parent', 'INT')],
            foreignKeys: [Ir::foreignKey('category', ['parent'], 'category', onDelete: ReferentialAction::SetNull)],
        )]);
    }

    /**
     * Dez tabelas em cadeia, nomeadas na ordem INVERSA da dependência: `chain_01`
     * depende de `chain_02`, e assim por diante. Prova que a ordenação vem do grafo
     * e não do nome — a ordem alfabética dos arquivos de model era exatamente o que
     * o sistema antigo usava, e é por isso que precisava de um segundo passe.
     */
    private static function deepChain(): SchemaDefinition
    {
        $tables = [];

        for ($level = 1; $level <= 10; $level++) {
            $name = 'chain_' . str_pad((string) $level, 2, '0', STR_PAD_LEFT);

            if ($level === 10) {
                $tables[] = Ir::table($name, [Ir::id()]);

                continue;
            }

            $parent = 'chain_' . str_pad((string) ($level + 1), 2, '0', STR_PAD_LEFT);

            $tables[] = Ir::table(
                $name,
                [Ir::id(), Ir::column('parent', 'INT', notNull: true)],
                foreignKeys: [Ir::foreignKey($name, ['parent'], $parent)],
            );
        }

        return Ir::schema($tables);
    }

    /**
     * Nome de tabela e de coluna que são palavra reservada nas duas engines. Como
     * todo identificador é sempre citado, isto simplesmente funciona — não há lista
     * de exceções para manter.
     */
    private static function reservedWords(): SchemaDefinition
    {
        return Ir::schema([new TableDefinition(
            name: 'order',
            columns: [
                Ir::column('select', 'INT', notNull: true),
                Ir::column('from', 'VARCHAR', 40),
                Ir::column('default', 'BOOLEAN', notNull: true, default: DefaultValue::literal(false)),
                Ir::column('user', 'VARCHAR', 40),
                Ir::column('table', 'VARCHAR', 40),
            ],
            primaryKey: new PrimaryKeyDefinition('order_pk', ['select']),
            indexes: [Ir::index('order', ['from'])],
        )]);
    }

    /** Tudo junto numa tabela só, para o caso em que os recursos interagem. */
    private static function compositeEverything(): SchemaDefinition
    {
        return Ir::schema([
            Ir::table('parent', [Ir::id(), Ir::column('name', 'VARCHAR', 60, notNull: true)]),
            Ir::table(
                'everything',
                [
                    Ir::id('key_id'),
                    Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'Nome'),
                    Ir::column('slug', 'VARCHAR', 120, notNull: true),
                    Ir::column('parent', 'INT'),
                    Ir::column('price', 'DECIMAL', '10,2', notNull: true, default: DefaultValue::literal(0)),
                    Ir::column('active', 'BOOLEAN', notNull: true, default: DefaultValue::literal(true)),
                    Ir::column(
                        'created_at',
                        'TIMESTAMP',
                        notNull: true,
                        default: DefaultValue::expression('CURRENT_TIMESTAMP'),
                    ),
                ],
                primaryKey: ['key_id'],
                uniques: [Ir::unique('everything', ['slug'])],
                indexes: [Ir::index('everything', ['name']), Ir::index('everything', ['parent', 'active'])],
                foreignKeys: [
                    Ir::foreignKey('everything', ['parent'], 'parent', onDelete: ReferentialAction::SetNull),
                ],
                checks: [Ir::check('everything', 'price >= 0')],
                comment: 'Tabela com tudo',
                options: new TableOptions(engine: 'InnoDB'),
            ),
        ]);
    }
}
