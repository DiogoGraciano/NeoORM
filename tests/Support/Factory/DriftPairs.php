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
 * O contrato do differ, escrito como texto.
 *
 * Cada entrada é uma mudança atômica: o schema antes, o schema depois, e a lista
 * exata de `describe()` que o differ tem que produzir — na ordem em que vai
 * produzir. Ler esse arquivo é ler o comportamento do differ inteiro sem abrir o
 * differ, e é o que torna revisável um componente com este número de casos.
 *
 * Os casos que esperam lista VAZIA são os mais importantes. Cada um deles é uma
 * fonte conhecida de migração espúria, e um deles é literalmente o bug B2 do
 * sistema antigo.
 */
final class DriftPairs
{
    private function __construct()
    {
    }

    /**
     * @return array<string,array{SchemaDefinition,SchemaDefinition,list<string>}>
     */
    public static function all(): array
    {
        return [
            ...self::tableLevel(),
            ...self::columnLevel(),
            ...self::primaryKeyLevel(),
            ...self::indexLevel(),
            ...self::foreignKeyLevel(),
            ...self::checkLevel(),
            ...self::nonChanges(),
            ...self::foreignKeyShield(),
        ];
    }

    // -------------------------------------------------------------- tabelas

    /**
     * @return array<string,array{SchemaDefinition,SchemaDefinition,list<string>}>
     */
    private static function tableLevel(): array
    {
        return [
            'create_table' => [
                Ir::schema([]),
                Ir::schema([self::city()]),
                ["cria a tabela 'city' com 2 colunas"],
            ],

            'drop_table' => [
                Ir::schema([self::city()]),
                Ir::schema([]),
                ["remove a tabela 'city'"],
            ],

            // Restrições de tabela nova NÃO vão dentro do CreateTable: saem como
            // operações próprias, nas fases P7 e P8. Um conceito, um lugar onde é
            // renderizado, valendo para tabela nova e tabela existente.
            'create_table_with_index' => [
                Ir::schema([]),
                Ir::schema([Ir::table(
                    'city',
                    [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)],
                    indexes: [Ir::index('city', ['name'])],
                )]),
                [
                    "cria a tabela 'city' com 2 colunas",
                    "cria o índice 'city_name_index' em 'city' (name)",
                ],
            ],

            'create_table_with_unique' => [
                Ir::schema([]),
                Ir::schema([Ir::table(
                    'city',
                    [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)],
                    uniques: [Ir::unique('city', ['name'])],
                )]),
                [
                    "cria a tabela 'city' com 2 colunas",
                    "adiciona a restrição de unicidade 'city_name_unique' em 'city' (name)",
                ],
            ],

            'create_table_with_check' => [
                Ir::schema([]),
                Ir::schema([Ir::table(
                    'city',
                    [Ir::id(), Ir::column('population', 'INT', notNull: true)],
                    checks: [Ir::check('city', 'population >= 0')],
                )]),
                [
                    "cria a tabela 'city' com 2 colunas",
                    "adiciona a restrição CHECK '" . self::checkName('city', 'population >= 0') . "' em 'city'",
                ],
            ],

            // A tabela referenciada vem antes da referente, por ordem topológica —
            // legibilidade, não correção: a FK só entra na P8, quando as duas já
            // existem.
            'create_table_with_foreign_key' => [
                Ir::schema([]),
                Ir::schema([self::country(), self::cityWithForeignKey()]),
                [
                    "cria a tabela 'country' com 2 colunas",
                    "cria a tabela 'city' com 3 colunas",
                    "adiciona a foreign key 'city_country_country_id_fk' em 'city' (country) -> country (id)",
                ],
            ],

            'set_table_comment' => [
                Ir::schema([self::city()]),
                Ir::schema([Ir::table(
                    'city',
                    [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)],
                    comment: 'Cidades',
                )]),
                ["define o comentário da tabela 'city'"],
            ],

            'remove_table_comment' => [
                Ir::schema([Ir::table(
                    'city',
                    [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)],
                    comment: 'Cidades',
                )]),
                Ir::schema([self::city()]),
                ["remove o comentário da tabela 'city'"],
            ],

            'set_table_collation' => [
                Ir::schema([self::city()]),
                Ir::schema([Ir::table(
                    'city',
                    [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)],
                    options: new TableOptions(collation: 'utf8mb4_bin'),
                )]),
                ["define collation utf8mb4_bin na tabela 'city'"],
            ],
        ];
    }

    // -------------------------------------------------------------- colunas

    /**
     * @return array<string,array{SchemaDefinition,SchemaDefinition,list<string>}>
     */
    private static function columnLevel(): array
    {
        return [
            'add_column' => [
                Ir::schema([self::city()]),
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('name', 'VARCHAR', 120, notNull: true),
                    Ir::column('ibge', 'INT'),
                ])]),
                ["adiciona a coluna 'city.ibge' (INT)"],
            ],

            'drop_column' => [
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('name', 'VARCHAR', 120, notNull: true),
                    Ir::column('ibge', 'INT'),
                ])]),
                Ir::schema([self::city()]),
                ["remove a coluna 'city.ibge'"],
            ],

            'alter_column_type' => [
                Ir::schema([self::city()]),
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('name', 'VARCHAR', 200, notNull: true),
                ])]),
                ["altera a coluna 'city.name' (tipo)"],
            ],

            'alter_column_nullability' => [
                Ir::schema([self::city()]),
                Ir::schema([Ir::table('city', [Ir::id(), Ir::column('name', 'VARCHAR', 120)])]),
                ["altera a coluna 'city.name' (nulidade)"],
            ],

            'alter_column_default' => [
                Ir::schema([self::city()]),
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('name', 'VARCHAR', 120, notNull: true, default: DefaultValue::literal('sem nome')),
                ])]),
                ["altera a coluna 'city.name' (default)"],
            ],

            'alter_column_remove_default' => [
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('name', 'VARCHAR', 120, notNull: true, default: DefaultValue::literal('x')),
                ])]),
                Ir::schema([self::city()]),
                ["altera a coluna 'city.name' (default)"],
            ],

            'alter_column_comment' => [
                Ir::schema([self::city()]),
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('name', 'VARCHAR', 120, notNull: true, comment: 'Nome da cidade'),
                ])]),
                ["altera a coluna 'city.name' (comentário)"],
            ],

            'alter_column_several_aspects_at_once' => [
                Ir::schema([self::city()]),
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('name', 'VARCHAR', 200, comment: 'Nome'),
                ])]),
                ["altera a coluna 'city.name' (tipo, nulidade, comentário)"],
            ],
        ];
    }

    // ---------------------------------------------------------- chave primária

    /**
     * @return array<string,array{SchemaDefinition,SchemaDefinition,list<string>}>
     */
    private static function primaryKeyLevel(): array
    {
        $withoutPk = new TableDefinition(
            name: 'log',
            columns: [Ir::column('id', 'INT', notNull: true), Ir::column('body', 'TEXT')],
            primaryKey: null,
        );

        $withPk = new TableDefinition(
            name: 'log',
            columns: [Ir::column('id', 'INT', notNull: true), Ir::column('body', 'TEXT')],
            primaryKey: new PrimaryKeyDefinition('log_pk', ['id']),
        );

        $composite = new TableDefinition(
            name: 'log',
            columns: [Ir::column('id', 'INT', notNull: true), Ir::column('body', 'TEXT')],
            primaryKey: new PrimaryKeyDefinition('log_pk', ['id', 'body']),
        );

        return [
            'add_primary_key' => [
                Ir::schema([$withoutPk]),
                Ir::schema([$withPk]),
                ["define a chave primária de 'log' em (id)"],
            ],

            'drop_primary_key' => [
                Ir::schema([$withPk]),
                Ir::schema([$withoutPk]),
                ["remove a chave primária de 'log'"],
            ],

            'change_primary_key' => [
                Ir::schema([$withPk]),
                Ir::schema([$composite]),
                [
                    "remove a chave primária de 'log'",
                    "define a chave primária de 'log' em (id, body)",
                ],
            ],

            // A regra da adoção. A chave primária é comparada apenas pela lista de
            // colunas, ignorando o nome da restrição, porque um banco existente tem a
            // PK batizada pelo servidor (`log_pkey` no PostgreSQL) e trocar o nome de
            // uma constraint não é uma mudança de schema que valha uma migração.
            'primary_key_name_only_is_not_a_change' => [
                Ir::schema([new TableDefinition(
                    name: 'log',
                    columns: [Ir::column('id', 'INT', notNull: true), Ir::column('body', 'TEXT')],
                    primaryKey: new PrimaryKeyDefinition('log_pkey', ['id']),
                )]),
                Ir::schema([$withPk]),
                [],
            ],
        ];
    }

    // ------------------------------------------------------- índices e uniques

    /**
     * @return array<string,array{SchemaDefinition,SchemaDefinition,list<string>}>
     */
    private static function indexLevel(): array
    {
        $plain = Ir::table('city', [
            Ir::id(),
            Ir::column('name', 'VARCHAR', 120, notNull: true),
            Ir::column('state', 'INT', notNull: true),
        ]);

        $withIndex = static fn (array $indexes): TableDefinition => Ir::table(
            'city',
            [
                Ir::id(),
                Ir::column('name', 'VARCHAR', 120, notNull: true),
                Ir::column('state', 'INT', notNull: true),
            ],
            indexes: $indexes,
        );

        return [
            // B1 e B8 juntos: o builder antigo recusava índice de menos de duas
            // colunas, e quando aceitava nunca chegava a emitir CREATE INDEX num
            // schema já existente — o extrator devolvia lista onde o comparador
            // esperava mapa, e os índices eram gravados com nome "0", "1".
            'add_single_column_index' => [
                Ir::schema([$plain]),
                Ir::schema([$withIndex([Ir::index('city', ['name'])])]),
                ["cria o índice 'city_name_index' em 'city' (name)"],
            ],

            'add_composite_index' => [
                Ir::schema([$plain]),
                Ir::schema([$withIndex([Ir::index('city', ['state', 'name'])])]),
                ["cria o índice 'city_state_name_index' em 'city' (state, name)"],
            ],

            'add_unique_index' => [
                Ir::schema([$plain]),
                Ir::schema([$withIndex([Ir::index('city', ['name'], unique: true)])]),
                ["cria o índice único 'city_name_unique_index' em 'city' (name)"],
            ],

            'drop_index' => [
                Ir::schema([$withIndex([Ir::index('city', ['name'])])]),
                Ir::schema([$plain]),
                ["remove o índice 'city_name_index' de 'city'"],
            ],

            'add_unique_constraint' => [
                Ir::schema([$plain]),
                Ir::schema([Ir::table(
                    'city',
                    [
                        Ir::id(),
                        Ir::column('name', 'VARCHAR', 120, notNull: true),
                        Ir::column('state', 'INT', notNull: true),
                    ],
                    uniques: [Ir::unique('city', ['name'])],
                )]),
                ["adiciona a restrição de unicidade 'city_name_unique' em 'city' (name)"],
            ],

            'drop_unique_constraint' => [
                Ir::schema([Ir::table(
                    'city',
                    [
                        Ir::id(),
                        Ir::column('name', 'VARCHAR', 120, notNull: true),
                        Ir::column('state', 'INT', notNull: true),
                    ],
                    uniques: [Ir::unique('city', ['name'])],
                )]),
                Ir::schema([$plain]),
                ["remove a restrição de unicidade 'city_name_unique' de 'city'"],
            ],
        ];
    }

    // --------------------------------------------------------- foreign keys

    /**
     * @return array<string,array{SchemaDefinition,SchemaDefinition,list<string>}>
     */
    private static function foreignKeyLevel(): array
    {
        $withoutFk = Ir::schema([self::country(), Ir::table('city', [
            Ir::id(),
            Ir::column('name', 'VARCHAR', 120, notNull: true),
            Ir::column('country', 'INT', notNull: true),
        ])]);

        return [
            'add_foreign_key' => [
                $withoutFk,
                Ir::schema([self::country(), self::cityWithForeignKey()]),
                ["adiciona a foreign key 'city_country_country_id_fk' em 'city' (country) -> country (id)"],
            ],

            'drop_foreign_key' => [
                Ir::schema([self::country(), self::cityWithForeignKey()]),
                $withoutFk,
                ["remove a foreign key 'city_country_country_id_fk' de 'city'"],
            ],

            // Mudança de ação referencial é derrubar e recriar: não existe
            // `ALTER CONSTRAINT` para isso em nenhum dos dois bancos.
            'change_referential_action' => [
                Ir::schema([self::country(), self::cityWithForeignKey()]),
                Ir::schema([
                    self::country(),
                    self::cityWithForeignKey(ReferentialAction::Cascade),
                ]),
                [
                    "remove a foreign key 'city_country_country_id_fk' de 'city'",
                    "adiciona a foreign key 'city_country_country_id_fk' em 'city' (country) -> country (id)",
                ],
            ],

            // RESTRICT e NO ACTION são o mesmo comportamento, e o MySQL reporta
            // regra omitida como RESTRICT enquanto o PostgreSQL reporta NO ACTION.
            // Mantê-los distintos garantiria drift falso eterno em um dos dois.
            'restrict_and_no_action_are_the_same' => [
                Ir::schema([self::country(), self::cityWithForeignKey(
                    ReferentialAction::canonical('RESTRICT'),
                )]),
                Ir::schema([self::country(), self::cityWithForeignKey(
                    ReferentialAction::canonical('NO ACTION'),
                )]),
                [],
            ],
        ];
    }

    // ---------------------------------------------------------------- checks

    /**
     * @return array<string,array{SchemaDefinition,SchemaDefinition,list<string>}>
     */
    private static function checkLevel(): array
    {
        $plain = Ir::table('product', [Ir::id(), Ir::column('price', 'INT', notNull: true)]);

        $withCheck = static fn (string $expression): TableDefinition => Ir::table(
            'product',
            [Ir::id(), Ir::column('price', 'INT', notNull: true)],
            checks: [Ir::check('product', $expression)],
        );

        return [
            'add_check' => [
                Ir::schema([$plain]),
                Ir::schema([$withCheck('price >= 0')]),
                ["adiciona a restrição CHECK '" . self::checkName('product', 'price >= 0') . "' em 'product'"],
            ],

            'drop_check' => [
                Ir::schema([$withCheck('price >= 0')]),
                Ir::schema([$plain]),
                ["remove a restrição CHECK '" . self::checkName('product', 'price >= 0') . "' de 'product'"],
            ],

            // Só espaçamento muda: a comparação é sobre a forma normalizada, então
            // reformatar a expressão no model não gera migração.
            'check_whitespace_is_not_a_change' => [
                Ir::schema([$withCheck('price >= 0')]),
                Ir::schema([Ir::table(
                    'product',
                    [Ir::id(), Ir::column('price', 'INT', notNull: true)],
                    checks: [new \Diogodg\Neoorm\Schema\CheckConstraintDefinition(
                        self::checkName('product', 'price >= 0'),
                        'price    >=    0',
                    )],
                )]),
                [],
            ],
        ];
    }

    // ------------------------------------------------------------ não-mudanças

    /**
     * Os casos que precisam devolver lista vazia.
     *
     * Cada um é uma fonte conhecida de migração espúria. O primeiro é o bug B2 do
     * sistema antigo, que gerava um ALTER de collation em toda execução e nunca
     * convergia.
     *
     * @return array<string,array{SchemaDefinition,SchemaDefinition,list<string>}>
     */
    private static function nonChanges(): array
    {
        return [
            'no_change_at_all' => [
                Ir::geographySchema(),
                Ir::geographySchema(),
                [],
            ],

            // B2. O snapshot anterior traz o collation real do servidor; o schema
            // declarado não diz nada sobre collation. `null` significa "default do
            // servidor, não compare" — e é o que impede o ALTER que a comparação
            // seguinte nunca aceitaria.
            'declared_null_collation_does_not_fight_the_server_default' => [
                Ir::schema([Ir::table(
                    'city',
                    [Ir::id()],
                    options: new TableOptions(engine: 'InnoDB', collation: 'utf8mb4_0900_ai_ci'),
                )]),
                Ir::schema([Ir::table('city', [Ir::id()], options: new TableOptions())]),
                [],
            ],

            'engine_case_is_not_a_change' => [
                Ir::schema([Ir::table('city', [Ir::id()], options: new TableOptions(engine: 'innodb'))]),
                Ir::schema([Ir::table('city', [Ir::id()], options: new TableOptions(engine: 'InnoDB'))]),
                [],
            ],

            // Ordem de coluna não vale um ALTER em nenhum dos dois bancos, e por isso
            // `columnOrder` existe no snapshot mas não é comparado. Sem esta regra,
            // reordenar declarações num model geraria migração.
            'column_declaration_order_is_not_a_change' => [
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('name', 'VARCHAR', 120, notNull: true),
                    Ir::column('ibge', 'INT'),
                ])]),
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('ibge', 'INT'),
                    Ir::column('name', 'VARCHAR', 120, notNull: true),
                ])]),
                [],
            ],

            // O catálogo do MySQL devolve todo default como string. Exigir que `0` e
            // `"0"` fossem distintos geraria drift falso em toda coluna numérica com
            // default.
            'numeric_default_written_as_string_is_not_a_change' => [
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('hits', 'INT', notNull: true, default: DefaultValue::literal(0)),
                ])]),
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('hits', 'INT', notNull: true, default: DefaultValue::literal('0')),
                ])]),
                [],
            ],

            // `now()` do catálogo do PostgreSQL e `CURRENT_TIMESTAMP` do model são a
            // mesma coisa, e `DefaultValue` canonicaliza as duas grafias.
            'equivalent_default_expressions_are_not_a_change' => [
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column('at', 'TIMESTAMP', notNull: true, default: DefaultValue::expression('now()')),
                ])]),
                Ir::schema([Ir::table('city', [
                    Ir::id(),
                    Ir::column(
                        'at',
                        'TIMESTAMP',
                        notNull: true,
                        default: DefaultValue::expression('CURRENT_TIMESTAMP'),
                    ),
                ])]),
                [],
            ],

            // Largura de exibição de tipo integral não é parte da identidade do tipo,
            // e o MySQL 8.0.19 parou de reportá-la. `INT(11)` e `INT` são o mesmo INT.
            'integer_display_width_is_not_a_change' => [
                Ir::schema([Ir::table('city', [Ir::id(), Ir::column('hits', 'INT', 11)])]),
                Ir::schema([Ir::table('city', [Ir::id(), Ir::column('hits', 'INT')])]),
                [],
            ],
        ];
    }

    // ------------------------------------------------------ escudo de foreign key

    /**
     * Os dois casos em que uma foreign key intacta ainda assim precisa sair da
     * frente e voltar depois.
     *
     * @return array<string,array{SchemaDefinition,SchemaDefinition,list<string>}>
     */
    private static function foreignKeyShield(): array
    {
        $countryBig = Ir::table('country', [
            Ir::column('id', 'BIGINT', notNull: true, autoIncrement: true),
            Ir::column('name', 'VARCHAR', 120, notNull: true),
        ]);

        $cityBig = Ir::table(
            'city',
            [
                Ir::id(),
                Ir::column('name', 'VARCHAR', 120, notNull: true),
                Ir::column('country', 'BIGINT', notNull: true),
            ],
            foreignKeys: [Ir::foreignKey('city', ['country'], 'country')],
        );

        $cityCompositePk = new TableDefinition(
            name: 'city',
            columns: [
                Ir::id(),
                Ir::column('name', 'VARCHAR', 120, notNull: true),
                Ir::column('country', 'INT', notNull: true),
            ],
            primaryKey: new PrimaryKeyDefinition('city_pk', ['id', 'country']),
            foreignKeys: [Ir::foreignKey('city', ['country'], 'country')],
        );

        return [
            // Trocar o tipo das duas pontas de uma referência: o MySQL recusa o ALTER
            // com "referencing column and referenced column are incompatible",
            // porque as duas colunas têm que casar e o ALTER mexe numa só.
            'type_change_on_both_sides_of_a_foreign_key' => [
                Ir::schema([self::country(), self::cityWithForeignKey()]),
                Ir::schema([$countryBig, $cityBig]),
                [
                    "remove a foreign key 'city_country_country_id_fk' de 'city'",
                    "altera a coluna 'city.country' (tipo)",
                    "altera a coluna 'country.id' (tipo)",
                    "adiciona a foreign key 'city_country_country_id_fk' em 'city' (country) -> country (id)",
                ],
            ],

            // Mexer na chave primária de uma tabela que tem foreign key: a FK depende
            // do índice da PK, e derrubá-lo com a referência de pé é recusado.
            'primary_key_change_with_a_foreign_key_present' => [
                Ir::schema([self::country(), self::cityWithForeignKey()]),
                Ir::schema([self::country(), $cityCompositePk]),
                [
                    "remove a foreign key 'city_country_country_id_fk' de 'city'",
                    "remove a chave primária de 'city'",
                    "define a chave primária de 'city' em (id, country)",
                    "adiciona a foreign key 'city_country_country_id_fk' em 'city' (country) -> country (id)",
                ],
            ],
        ];
    }

    // ------------------------------------------------------------- auxiliares

    private static function city(): TableDefinition
    {
        return Ir::table('city', [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)]);
    }

    private static function country(): TableDefinition
    {
        return Ir::table('country', [Ir::id(), Ir::column('name', 'VARCHAR', 120, notNull: true)]);
    }

    private static function cityWithForeignKey(
        ReferentialAction $onDelete = ReferentialAction::NoAction,
    ): TableDefinition {
        return Ir::table(
            'city',
            [
                Ir::id(),
                Ir::column('name', 'VARCHAR', 120, notNull: true),
                Ir::column('country', 'INT', notNull: true),
            ],
            foreignKeys: [Ir::foreignKey('city', ['country'], 'country', onDelete: $onDelete)],
        );
    }

    private static function checkName(string $table, string $expression): string
    {
        return \Diogodg\Neoorm\Schema\Naming\ConstraintNamer::check($table, $expression);
    }
}
