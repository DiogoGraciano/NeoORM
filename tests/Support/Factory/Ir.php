<?php

declare(strict_types=1);

namespace Tests\Support\Factory;

use Diogodg\Neoorm\Schema\CheckConstraintDefinition;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\ForeignKeyDefinition;
use Diogodg\Neoorm\Schema\IndexDefinition;
use Diogodg\Neoorm\Schema\Naming\ConstraintNamer;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\TableOptions;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\UniqueConstraintDefinition;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;

/**
 * Atalhos para montar IR em teste.
 *
 * Um schema de teste tem que caber em poucas linhas, senão os casos viram
 * ruído e ninguém acrescenta o caso de borda que faltava. Isto aqui é açúcar
 * puro sobre os construtores — nenhuma regra de negócio mora aqui, porque
 * regra em fixture é regra que não está sendo testada.
 */
final class Ir
{
    private function __construct()
    {
    }

    public static function column(
        string $name,
        string $type = 'INT',
        string|int|null $size = null,
        bool $notNull = false,
        bool $autoIncrement = false,
        ?DefaultValue $default = null,
        ?string $comment = null,
        ?string $collation = null,
    ): ColumnDefinition {
        return new ColumnDefinition(
            name: $name,
            type: TypeSpec::parse($type, $size),
            notNull: $notNull,
            autoIncrement: $autoIncrement,
            default: $default,
            comment: $comment,
            collation: $collation,
        );
    }

    /** Coluna `id` INT NOT NULL com auto incremento — a PK de quase todo model. */
    public static function id(string $name = 'id'): ColumnDefinition
    {
        return self::column($name, 'INT', notNull: true, autoIncrement: true);
    }

    /**
     * @param list<ColumnDefinition>           $columns
     * @param list<string>                     $primaryKey
     * @param list<UniqueConstraintDefinition> $uniques
     * @param list<IndexDefinition>            $indexes
     * @param list<ForeignKeyDefinition>       $foreignKeys
     * @param list<CheckConstraintDefinition>  $checks
     */
    public static function table(
        string $name,
        array $columns,
        array $primaryKey = ['id'],
        array $uniques = [],
        array $indexes = [],
        array $foreignKeys = [],
        array $checks = [],
        ?string $comment = null,
        ?TableOptions $options = null,
    ): TableDefinition {
        $pk = null;

        if ($primaryKey !== []) {
            $pk = new PrimaryKeyDefinition(ConstraintNamer::primaryKey($name), $primaryKey);
        }

        return new TableDefinition(
            name: $name,
            columns: $columns,
            primaryKey: $pk,
            uniqueConstraints: $uniques,
            indexes: $indexes,
            foreignKeys: $foreignKeys,
            checks: $checks,
            comment: $comment,
            options: $options ?? new TableOptions(),
        );
    }

    /**
     * @param list<TableDefinition> $tables
     */
    public static function schema(array $tables): SchemaDefinition
    {
        return new SchemaDefinition($tables);
    }

    /**
     * @param list<string> $columns
     */
    public static function unique(string $table, array $columns): UniqueConstraintDefinition
    {
        return new UniqueConstraintDefinition(ConstraintNamer::unique($table, $columns), $columns);
    }

    /**
     * @param list<string> $columns
     */
    public static function index(string $table, array $columns, bool $unique = false): IndexDefinition
    {
        return new IndexDefinition(ConstraintNamer::index($table, $columns, $unique), $columns, $unique);
    }

    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public static function foreignKey(
        string $table,
        array $columns,
        string $referencedTable,
        array $referencedColumns = ['id'],
        ReferentialAction $onDelete = ReferentialAction::NoAction,
        ReferentialAction $onUpdate = ReferentialAction::NoAction,
    ): ForeignKeyDefinition {
        return new ForeignKeyDefinition(
            name: ConstraintNamer::foreignKey($table, $columns, $referencedTable, $referencedColumns),
            columns: $columns,
            referencedTable: $referencedTable,
            referencedColumns: $referencedColumns,
            onDelete: $onDelete,
            onUpdate: $onUpdate,
        );
    }

    public static function check(string $table, string $expression): CheckConstraintDefinition
    {
        return new CheckConstraintDefinition(ConstraintNamer::check($table, $expression), $expression);
    }

    /**
     * O schema de domínio reduzido: country -> state -> city, com FK e unique.
     * Serve de caso "realista" onde um schema sintético não convence.
     */
    public static function geographySchema(): SchemaDefinition
    {
        return self::schema([
            self::table('country', [
                self::id(),
                self::column('name', 'VARCHAR', 120, notNull: true, comment: 'Country name'),
                self::column('abbreviation', 'VARCHAR', 2, notNull: true),
            ], comment: 'Countries table'),

            self::table('state', [
                self::id(),
                self::column('name', 'VARCHAR', 120, notNull: true),
                self::column('country', 'INT', notNull: true),
                self::column('ibge', 'INT'),
            ],
                uniques: [self::unique('state', ['ibge'])],
                foreignKeys: [self::foreignKey('state', ['country'], 'country')],
                comment: 'States table',
            ),

            self::table('city', [
                self::id(),
                self::column('name', 'VARCHAR', 120, notNull: true, comment: 'City name'),
                self::column('state', 'INT', notNull: true),
                self::column('ibge', 'INT'),
            ],
                uniques: [self::unique('city', ['ibge'])],
                foreignKeys: [self::foreignKey('city', ['state'], 'state')],
                comment: 'Cities table',
            ),
        ]);
    }
}
