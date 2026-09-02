<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Introspection;

use Diogodg\Neoorm\Schema\CheckConstraintDefinition;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\ForeignKeyDefinition;
use Diogodg\Neoorm\Schema\IndexDefinition;
use Diogodg\Neoorm\Schema\PrimaryKeyDefinition;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\TableOptions;
use Diogodg\Neoorm\Schema\UniqueConstraintDefinition;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;
use PDO;

/**
 * Lê os catálogos do PostgreSQL.
 *
 * Usa `pg_catalog` em vez de `information_schema`, e por razões concretas: só ali estão
 * `attidentity` (que distingue identity de coluna comum), `format_type` (que devolve a
 * precisão junto do tipo) e `conindid` (que diz qual índice pertence a qual restrição).
 * O `information_schema` é portável mas incompleto para o que a comparação precisa.
 *
 * `pg_constraint` é a autoridade sobre restrições. Todo índice que existe apenas para
 * dar suporte a uma chave primária ou a um UNIQUE é filtrado, senão a comparação
 * acusaria um índice a mais em toda tabela com chave.
 */
final class PgsqlCatalogReader implements CatalogReader
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $schema,
    ) {
    }

    public function tableNames(): array
    {
        $rows = $this->query(
            "SELECT c.relname
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relkind = 'r'
             ORDER BY c.relname",
            [$this->schema],
        );

        return array_map(static fn (array $row): string => (string) $row['relname'], $rows);
    }

    public function read(?array $onlyTables = null): SchemaDefinition
    {
        $wanted = $onlyTables === null ? null : array_fill_keys(array_map('strtolower', $onlyTables), true);

        $tables = [];

        foreach ($this->tableRows() as $name => $comment) {
            if ($wanted !== null && !isset($wanted[$name])) {
                continue;
            }

            $columnRows = $this->columnRows($name);

            if ($columnRows === []) {
                continue;
            }

            $columns = [];
            $byAttnum = [];

            foreach ($columnRows as $row) {
                $columns[] = $this->column($row);
                $byAttnum[(int) $row['attnum']] = (string) $row['column_name'];
            }

            $constraints = $this->constraintsOf($name, $byAttnum);

            $tables[] = new TableDefinition(
                name: $name,
                columns: $columns,
                primaryKey: $constraints['primaryKey'],
                uniqueConstraints: $constraints['uniques'],
                indexes: $this->indexesOf($name),
                foreignKeys: $constraints['foreignKeys'],
                checks: $constraints['checks'],
                comment: $comment,
                // Engine e collation de tabela não existem aqui, e `null` no snapshot
                // significa "default do servidor, não compare".
                options: new TableOptions(),
            );
        }

        return new SchemaDefinition($tables);
    }

    /**
     * @return array<string,?string>
     */
    private function tableRows(): array
    {
        $rows = $this->query(
            "SELECT c.relname, obj_description(c.oid, 'pg_class') AS comment
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = ? AND c.relkind = 'r'
             ORDER BY c.relname",
            [$this->schema],
        );

        $tables = [];

        foreach ($rows as $row) {
            $comment = $row['comment'] === null ? null : (string) $row['comment'];
            $tables[(string) $row['relname']] = ($comment === '') ? null : $comment;
        }

        return $tables;
    }

    /**
     * @return list<array<string,mixed>>
     */
    private function columnRows(string $table): array
    {
        return $this->query(
            "SELECT a.attname AS column_name,
                    a.attnum,
                    format_type(a.atttypid, a.atttypmod) AS type,
                    a.attnotnull,
                    a.attidentity,
                    pg_get_expr(d.adbin, d.adrelid) AS default_expr,
                    col_description(c.oid, a.attnum) AS comment
             FROM pg_attribute a
             JOIN pg_class c ON c.oid = a.attrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_attrdef d ON d.adrelid = c.oid AND d.adnum = a.attnum
             WHERE n.nspname = ? AND c.relname = ? AND c.relkind = 'r'
               AND a.attnum > 0 AND NOT a.attisdropped
             ORDER BY a.attnum",
            [$this->schema, $table],
        );
    }

    /**
     * @param array<string,mixed> $row
     */
    private function column(array $row): ColumnDefinition
    {
        $type = PgsqlNormalizer::type((string) $row['type']);
        $rawDefault = $row['default_expr'] === null ? null : (string) $row['default_expr'];

        // Duas formas de auto incremento chegam aqui. `GENERATED ... AS IDENTITY` aparece
        // em `attidentity` ('a' para ALWAYS, 'd' para BY DEFAULT), e a distinção entre as
        // duas é colapsada de propósito: o IR só tem um booleano, e o gerador sempre
        // emite BY DEFAULT para que INSERT com id explícito continue funcionando.
        //
        // A outra é `serial`, que NÃO existe no catálogo: é `integer` com default
        // `nextval(...)`. `PgsqlNormalizer::default()` devolve null nesse caso, e é isso
        // que faz o default desaparecer junto — se ele sobrevivesse, o schema declarado
        // (que só diz "auto incremento") divergiria para sempre.
        $identity = in_array((string) ($row['attidentity'] ?? ''), ['a', 'd'], true);
        $default = PgsqlNormalizer::default($rawDefault, $type);
        $autoIncrement = $identity || $default === null;

        $comment = $row['comment'] === null ? null : (string) $row['comment'];

        return new ColumnDefinition(
            name: (string) $row['column_name'],
            type: $type,
            // NOT NULL é implícito em coluna identity e precisa ser gravado
            // explicitamente, senão a coluna divergiria de um schema declarado onde
            // isPrimary() já força NOT NULL.
            notNull: self::isTrue($row['attnotnull']) || $identity,
            autoIncrement: $autoIncrement,
            default: $autoIncrement ? DefaultValue::none() : ($default ?? DefaultValue::none()),
            comment: ($comment === '') ? null : $comment,
            collation: null,
        );
    }

    /**
     * @param array<int,string> $byAttnum
     * @return array{primaryKey:?PrimaryKeyDefinition,uniques:list<UniqueConstraintDefinition>,foreignKeys:list<ForeignKeyDefinition>,checks:list<CheckConstraintDefinition>}
     */
    private function constraintsOf(string $table, array $byAttnum): array
    {
        $rows = $this->query(
            "SELECT con.conname, con.contype,
                    con.conkey::int[]::text AS local_columns,
                    con.confkey::int[]::text AS referenced_columns,
                    fc.relname AS referenced_table,
                    con.confdeltype, con.confupdtype,
                    pg_get_constraintdef(con.oid) AS definition
             FROM pg_constraint con
             JOIN pg_class c ON c.oid = con.conrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             LEFT JOIN pg_class fc ON fc.oid = con.confrelid
             WHERE n.nspname = ? AND c.relname = ? AND con.contype IN ('p', 'u', 'f', 'c')
             ORDER BY con.conname",
            [$this->schema, $table],
        );

        $primaryKey = null;
        $uniques = [];
        $foreignKeys = [];
        $checks = [];

        foreach ($rows as $row) {
            $name = (string) $row['conname'];
            $columns = PgsqlNormalizer::columnList(
                $row['local_columns'] === null ? null : (string) $row['local_columns'],
                $byAttnum,
            );

            switch ((string) $row['contype']) {
                case 'p':
                    $primaryKey = new PrimaryKeyDefinition($name, $columns);
                    break;

                case 'u':
                    $uniques[] = new UniqueConstraintDefinition($name, $columns);
                    break;

                case 'f':
                    $referencedTable = $row['referenced_table'] === null
                        ? null
                        : (string) $row['referenced_table'];

                    if ($referencedTable === null) {
                        break;
                    }

                    $foreignKeys[] = new ForeignKeyDefinition(
                        name: $name,
                        columns: $columns,
                        referencedTable: $referencedTable,
                        referencedColumns: PgsqlNormalizer::columnList(
                            $row['referenced_columns'] === null ? null : (string) $row['referenced_columns'],
                            $this->columnsByAttnum($referencedTable),
                        ),
                        onDelete: self::referentialAction((string) ($row['confdeltype'] ?? 'a')),
                        onUpdate: self::referentialAction((string) ($row['confupdtype'] ?? 'a')),
                    );
                    break;

                case 'c':
                    $checks[] = new CheckConstraintDefinition(
                        $name,
                        PgsqlNormalizer::checkExpression((string) $row['definition']),
                    );
                    break;
            }
        }

        return [
            'primaryKey' => $primaryKey,
            'uniques' => $uniques,
            'foreignKeys' => $foreignKeys,
            'checks' => $checks,
        ];
    }

    /**
     * @return array<int,string>
     */
    private function columnsByAttnum(string $table): array
    {
        $map = [];

        foreach ($this->columnRows($table) as $row) {
            $map[(int) $row['attnum']] = (string) $row['column_name'];
        }

        return $map;
    }

    /**
     * Índices que não existem para dar suporte a uma restrição.
     *
     * O filtro é `conindid`, que liga uma restrição ao índice que a implementa. Sem ele,
     * a chave primária e cada UNIQUE apareceriam também como índice, e a comparação
     * acusaria um índice a mais em toda tabela com chave.
     *
     * @return list<IndexDefinition>
     */
    private function indexesOf(string $table): array
    {
        $rows = $this->query(
            "SELECT i.relname AS index_name,
                    ix.indisunique,
                    am.amname AS method,
                    (SELECT array_to_string(array_agg(a.attname ORDER BY k.ord), ',')
                       FROM unnest(ix.indkey) WITH ORDINALITY AS k(attnum, ord)
                       JOIN pg_attribute a ON a.attrelid = c.oid AND a.attnum = k.attnum) AS columns
             FROM pg_index ix
             JOIN pg_class i ON i.oid = ix.indexrelid
             JOIN pg_class c ON c.oid = ix.indrelid
             JOIN pg_namespace n ON n.oid = c.relnamespace
             JOIN pg_am am ON am.oid = i.relam
             WHERE n.nspname = ? AND c.relname = ? AND c.relkind = 'r'
               AND NOT EXISTS (SELECT 1 FROM pg_constraint con WHERE con.conindid = i.oid)
             ORDER BY i.relname",
            [$this->schema, $table],
        );

        $indexes = [];

        foreach ($rows as $row) {
            $columns = array_values(array_filter(
                explode(',', (string) ($row['columns'] ?? '')),
                static fn (string $column): bool => trim($column) !== '',
            ));

            if ($columns === []) {
                // Índice de expressão (`lower(name)`), que o IR não representa. Ignorar é
                // melhor que reportar um índice sem colunas, que o differ tentaria
                // recriar sem saber sobre o quê.
                continue;
            }

            $indexes[] = new IndexDefinition(
                (string) $row['index_name'],
                $columns,
                self::isTrue($row['indisunique']),
                PgsqlNormalizer::indexMethod((string) ($row['method'] ?? 'btree')),
            );
        }

        return $indexes;
    }

    /**
     * `confdeltype` e `confupdtype` são um único caractere.
     *
     * 'a' (NO ACTION) e 'r' (RESTRICT) chegam ao mesmo enum: são o mesmo comportamento, e
     * o MySQL reporta regra omitida como RESTRICT enquanto este reporta NO ACTION.
     * Mantê-los distintos garantiria drift falso permanente em um dos dois bancos.
     */
    private static function referentialAction(string $code): ReferentialAction
    {
        return match ($code) {
            'c' => ReferentialAction::Cascade,
            'n' => ReferentialAction::SetNull,
            'd' => ReferentialAction::SetDefault,
            default => ReferentialAction::NoAction,
        };
    }

    /**
     * O PDO do pgsql devolve booleano como `'t'`/`'f'`, e o do mysql como `1`/`0`.
     */
    private static function isTrue(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        return in_array(strtolower((string) $value), ['t', 'true', '1', 'y', 'yes'], true);
    }

    /**
     * @param list<mixed> $bindings
     * @return list<array<string,mixed>>
     */
    private function query(string $sql, array $bindings): array
    {
        $statement = $this->pdo->prepare($sql);
        $statement->execute($bindings);

        /** @var list<array<string,mixed>> $rows */
        $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

        return $rows;
    }
}
