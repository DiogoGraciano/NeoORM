<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Schema\CheckConstraintDefinition;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\ForeignKeyDefinition;
use Diogodg\Neoorm\Schema\IndexDefinition;
use Diogodg\Neoorm\Schema\Naming\ConstraintNamer;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\TableOptions;
use Diogodg\Neoorm\Schema\UniqueConstraintDefinition;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;

/**
 * Builder de tabela: as colunas num MAPA `nome => Col`.
 *
 * ```php
 * Table::make('state', comment: 'States table')
 *     ->columns([
 *         'id'      => Col::id(),
 *         'name'    => Col::varchar(120)->notNull(),
 *         'country' => Col::int()->notNull()->references(Country::class),
 *     ])
 *     ->index('state_name_index', ['name']);
 * ```
 *
 * O mapa substitui a sequência de `addColumn(new Column(...))`. O nome deixa de ser
 * argumento de construtor e vira chave, que é o que torna a duplicata impossível de
 * escrever sem ser vista, e a foreign key passa a morar na coluna que ela restringe
 * em vez de num `addForeignKey()` a três linhas de distância.
 *
 * Duas propriedades de fundo continuam valendo, e são o que separa este builder do
 * da 1.x.
 *
 * Nada aqui abre conexão. Antes o construtor instanciava um driver que criava um
 * rastreador de schema, cujo construtor rodava cinco `CREATE TABLE IF NOT EXISTS` —
 * uma vez por model. No MySQL, onde DDL dá commit implícito, isso furava qualquer
 * transação aberta.
 *
 * Validação acontece em `build()`, não na chamada. É por isso que a ordem das
 * chamadas não significa nada: declarar um índice sobre uma coluna que só aparece
 * na chamada seguinte a `columns()` funciona.
 */
final class Table
{
    /** @var array<string,Col> nome => coluna, em ordem de declaração */
    private array $cols = [];

    /** @var list<array{name:string,columns:list<string>,method:?string}> */
    private array $indexes = [];

    /** @var list<UniqueConstraintDefinition> */
    private array $uniques = [];

    /** @var list<string> colunas declaradas como chave primária por primary() */
    private array $declaredPrimaryKey = [];

    /** @var list<array{columns:list<string>,table:string,references:list<string>,onDelete:ReferentialAction,onUpdate:ReferentialAction,name:?string}> */
    private array $foreignKeys = [];

    /** @var list<CheckConstraintDefinition> */
    private array $checks = [];

    private ?TableDefinition $built = null;

    private function __construct(
        private readonly string $table,
        private readonly string $comment,
        private readonly ?string $engine,
        private readonly ?string $collate,
    ) {
    }

    /**
     * `engine` e `collate` nulos significam "default do servidor".
     *
     * Eram `InnoDB` e `utf8mb4_general_ci` cravados no builder, e é a causa direta de
     * B2: o default assumido discordava permanentemente de um MySQL 8, cujo default é
     * `utf8mb4_0900_ai_ci`, então cada migrate gerava um ALTER de collation que a
     * comparação seguinte nunca aceitava. `null` no snapshot quer dizer "não compare",
     * e um MySQL sem `ENGINE=` na DDL usa InnoDB de qualquer forma.
     */
    public static function make(
        string $name,
        string $comment = '',
        ?string $engine = null,
        ?string $collate = null,
    ): static {
        return new static($name, $comment, $engine, $collate);
    }

    /**
     * As colunas, por nome.
     *
     * Pode ser chamado mais de uma vez — as colunas acumulam na ordem de declaração,
     * o que permite compor um bloco comum com `array_merge` ou separar as colunas por
     * assunto sem que a ordem final surpreenda.
     *
     * @param array<string,Col> $columns
     */
    public function columns(array $columns): static
    {
        foreach ($columns as $name => $col) {
            $this->column((string) $name, $col);
        }

        return $this;
    }

    /**
     * Uma coluna. É o que `columns()` chama por baixo.
     */
    public function column(string $name, Col $col): static
    {
        if (trim($name) === '') {
            throw new MigrationException(
                "Tabela '{$this->table}': coluna sem nome. A chave do mapa passado a columns() é o "
                . 'nome da coluna.',
            );
        }

        $key = strtolower(trim($name));

        if (isset($this->cols[$key])) {
            throw new MigrationException("Tabela '{$this->table}': coluna duplicada '{$key}'.");
        }

        $this->cols[$key] = $col;
        $this->built = null;

        return $this;
    }

    /**
     * `created_at` e `updated_at`.
     *
     * `updated_at` fica nulo e SEM default: manter a coluna em dia é trabalho do
     * UPDATE. O `ON UPDATE CURRENT_TIMESTAMP` que faria isso sozinho só existe no
     * MySQL, e o IR é neutro de dialeto de propósito — uma tabela que o usasse
     * divergiria para sempre no PostgreSQL.
     */
    public function timestamps(string $createdAt = 'created_at', string $updatedAt = 'updated_at'): static
    {
        return $this->columns([
            $createdAt => Col::timestamp()->notNull()->defaultRaw('CURRENT_TIMESTAMP'),
            $updatedAt => Col::timestamp(),
        ]);
    }

    /**
     * Índice comum sobre uma ou mais colunas.
     *
     * Para índice de uma coluna só, `Col::index()` diz a mesma coisa mais perto dela.
     * Para unicidade, `unique()` — e não `index(..., unique: true)`: no MySQL os dois
     * são o mesmo objeto de catálogo, então ter duas representações significaria que
     * uma delas divergiria do banco para sempre.
     *
     * @param list<string> $columns
     */
    public function index(string $name, array $columns, ?string $method = null): static
    {
        $this->indexes[] = [
            'name' => $name,
            'columns' => array_values($columns),
            'method' => $method,
        ];
        $this->built = null;

        return $this;
    }

    /**
     * Restrição de unicidade, possivelmente sobre várias colunas.
     *
     * @param list<string> $columns
     */
    public function unique(string $name, array $columns): static
    {
        $this->uniques[] = new UniqueConstraintDefinition($name, array_values($columns));
        $this->built = null;

        return $this;
    }

    /**
     * Chave primária composta. Para a de uma coluna só, `Col::primary()`.
     *
     * @param list<string> $columns
     */
    public function primary(array $columns): static
    {
        $this->declaredPrimaryKey = array_values($columns);
        $this->built = null;

        return $this;
    }

    /**
     * Restrição CHECK. A expressão é SQL e passa adiante como está.
     */
    public function check(string $expression, ?string $name = null): static
    {
        $this->checks[] = new CheckConstraintDefinition(
            $name ?? ConstraintNamer::check($this->table, $expression),
            $expression,
        );
        $this->built = null;

        return $this;
    }

    /**
     * Foreign key COMPOSTA — a de uma coluna só é `Col::references()`.
     *
     * @param string|list<string> $column
     * @param string|list<string> $foreignColumn
     */
    public function foreignKey(
        string $foreignTable,
        string|array $column,
        string|array $foreignColumn = 'id',
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'NO ACTION',
        ?string $name = null,
    ): static {
        $columns = array_values(is_array($column) ? $column : [$column]);
        $references = array_values(is_array($foreignColumn) ? $foreignColumn : [$foreignColumn]);

        // Uma chave composta que referencie uma única coluna é quase sempre erro de
        // digitação, e o default `'id'` do parâmetro esconderia isso: sem esta
        // checagem, `foreignKey('t', ['a','b'])` viraria duas colunas apontando
        // para uma.
        if (count($references) === 1 && count($columns) > 1 && $foreignColumn === 'id') {
            throw new MigrationException(
                "Foreign key composta em '{$this->table}' (" . implode(', ', $columns) . ') sem informar '
                . 'as colunas referenciadas. Passe foreignColumn com a mesma quantidade de colunas.',
            );
        }

        $this->foreignKeys[] = [
            'columns' => $columns,
            'table' => $foreignTable,
            'references' => $references,
            'onDelete' => ReferentialAction::canonical($onDelete),
            'onUpdate' => ReferentialAction::canonical($onUpdate),
            'name' => $name,
        ];
        $this->built = null;

        return $this;
    }

    /**
     * Monta a definição imutável da tabela. Memoizado.
     *
     * É aqui que toda validação de coerência acontece, e é por isso que a ordem das
     * chamadas no model não importa.
     */
    public function build(): TableDefinition
    {
        if ($this->built !== null) {
            return $this->built;
        }

        if ($this->cols === []) {
            throw new MigrationException(
                "Tabela '{$this->table}' não declara nenhuma coluna. Use columns(['id' => Col::id(), ...]).",
            );
        }

        $columns = [];

        foreach ($this->cols as $name => $col) {
            $columns[] = $col->build($name);
        }

        $primaryKeyColumns = $this->resolvePrimaryKeyColumns();

        return $this->built = new TableDefinition(
            name: $this->table,
            columns: $columns,
            primaryKey: $primaryKeyColumns === []
                ? null
                : new PrimaryKeyDefinition(ConstraintNamer::primaryKey($this->table), $primaryKeyColumns),
            uniqueConstraints: $this->resolveUniques(),
            indexes: $this->resolveIndexes(),
            foreignKeys: $this->resolveForeignKeys(),
            checks: $this->checks,
            comment: $this->comment,
            options: new TableOptions(engine: $this->engine, collation: $this->collate),
        );
    }

    /**
     * @return list<string>
     */
    private function resolvePrimaryKeyColumns(): array
    {
        if ($this->declaredPrimaryKey !== []) {
            return $this->declaredPrimaryKey;
        }

        $columns = [];

        foreach ($this->cols as $name => $col) {
            if ($col->isPrimaryKey()) {
                $columns[] = $name;
            }
        }

        return $columns;
    }

    /**
     * Uniques declaradas na tabela primeiro, depois as derivadas de `Col::unique()`.
     *
     * @return list<UniqueConstraintDefinition>
     */
    private function resolveUniques(): array
    {
        $uniques = $this->uniques;

        foreach ($this->cols as $name => $col) {
            if ($col->isUniqueColumn()) {
                $uniques[] = new UniqueConstraintDefinition(
                    ConstraintNamer::unique($this->table, [$name]),
                    [$name],
                );
            }
        }

        return $uniques;
    }

    /**
     * @return list<IndexDefinition>
     */
    private function resolveIndexes(): array
    {
        $indexes = array_map(
            static fn (array $index): IndexDefinition => new IndexDefinition(
                $index['name'],
                $index['columns'],
                false,
                $index['method'],
            ),
            $this->indexes,
        );

        foreach ($this->cols as $name => $col) {
            $wanted = $col->wantsIndex();

            if ($wanted === null) {
                continue;
            }

            $indexes[] = new IndexDefinition(
                $wanted['name'] ?? ConstraintNamer::index($this->table, [$name]),
                [$name],
                false,
                $wanted['method'],
            );
        }

        return $indexes;
    }

    /**
     * Nomes gerados por `ConstraintNamer`, e por isso únicos por (colunas, destino).
     *
     * O rastreador antigo indexava as foreign keys pela coluna REFERENCIADA, que é
     * `id` em praticamente todos os casos — então uma tabela com quatro foreign keys
     * apontando para `id` de quatro tabelas diferentes registrava apenas uma. É o
     * bug B5, e ele desaparece porque a chave inclui a coluna local e a tabela de
     * destino.
     *
     * @return list<ForeignKeyDefinition>
     */
    private function resolveForeignKeys(): array
    {
        $declared = $this->foreignKeys;

        foreach ($this->cols as $name => $col) {
            $reference = $col->wantsReference();

            if ($reference === null) {
                continue;
            }

            $declared[] = [
                'columns' => [$name],
                'table' => $reference['table'],
                'references' => [$reference['column']],
                'onDelete' => ReferentialAction::canonical($reference['onDelete']),
                'onUpdate' => ReferentialAction::canonical($reference['onUpdate']),
                'name' => $reference['name'],
            ];
        }

        return array_map(
            fn (array $foreignKey): ForeignKeyDefinition => new ForeignKeyDefinition(
                name: $foreignKey['name'] ?? ConstraintNamer::foreignKey(
                    $this->table,
                    $foreignKey['columns'],
                    $foreignKey['table'],
                    $foreignKey['references'],
                ),
                columns: $foreignKey['columns'],
                referencedTable: $foreignKey['table'],
                referencedColumns: $foreignKey['references'],
                onDelete: $foreignKey['onDelete'],
                onUpdate: $foreignKey['onUpdate'],
            ),
            $declared,
        );
    }

    // ------------------------------------------------------------- consultas

    public function getTable(): string
    {
        return $this->build()->name;
    }

    /**
     * Colunas em ordem de declaração, chaveadas por nome.
     *
     * @return array<string,ColumnDefinition>
     */
    public function getColumns(): array
    {
        return $this->build()->getColumns();
    }

    /**
     * @return list<string>
     */
    public function getPrimaryKey(): array
    {
        return $this->build()->getPrimaryKeyColumns();
    }

    public function getAutoIncrement(): bool
    {
        return $this->build()->hasAutoIncrement();
    }

    public function getEngine(): ?string
    {
        return $this->build()->options->engine;
    }

    public function getCollation(): ?string
    {
        return $this->build()->options->collation;
    }

    public function getComment(): ?string
    {
        return $this->build()->comment;
    }

    /**
     * @return array<string,IndexDefinition>
     */
    public function getIndexes(): array
    {
        return $this->build()->indexes;
    }

    /**
     * @return array<string,UniqueConstraintDefinition>
     */
    public function getConstraints(): array
    {
        return $this->build()->uniqueConstraints;
    }

    public function hasForeignKey(): bool
    {
        return $this->build()->foreignKeys !== [];
    }

    /**
     * @return list<string>
     */
    public function getForeignKeyTables(): array
    {
        return $this->build()->referencedTables();
    }

}
