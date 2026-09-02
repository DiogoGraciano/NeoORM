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
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\UniqueConstraintDefinition;
use Diogodg\Neoorm\Schema\Value\DefaultValue;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;
use PDO;

/**
 * Lê o `information_schema` do MySQL.
 *
 * A maior fonte de drift falso deste banco está em `STATISTICS`: lá, uma restrição
 * UNIQUE *é* um índice, a chave primária *é* um índice chamado `PRIMARY`, e o engine
 * cria um índice de apoio para toda foreign key que não tenha um. Reportar tudo isso
 * como índice faria a comparação acusar meia dúzia de índices que ninguém declarou, em
 * toda tabela com foreign key. As duas regras que resolvem estão em
 * `isConstraintBackedIndex()` e `isImplicitForeignKeyIndex()`.
 */
final class MysqlCatalogReader implements CatalogReader
{
    public function __construct(
        private readonly PDO $pdo,
        private readonly string $database,
    ) {
    }

    public function tableNames(): array
    {
        $rows = $this->query(
            'SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME',
            [$this->database],
        );

        return array_map(static fn (array $row): string => strtolower((string) $row['TABLE_NAME']), $rows);
    }

    public function read(?array $onlyTables = null): SchemaDefinition
    {
        $wanted = $onlyTables === null ? null : array_fill_keys(array_map('strtolower', $onlyTables), true);

        $tables = [];

        foreach ($this->tableRows() as $name => $meta) {
            if ($wanted !== null && !isset($wanted[$name])) {
                continue;
            }

            $columns = $this->columnsOf($name);

            if ($columns === []) {
                continue;
            }

            $constraints = $this->constraintsOf($name);

            $tables[] = new TableDefinition(
                name: $name,
                columns: $columns,
                primaryKey: $constraints['primaryKey'],
                uniqueConstraints: $constraints['uniques'],
                indexes: $this->indexesOf($name, $constraints),
                foreignKeys: $constraints['foreignKeys'],
                checks: $this->checksOf($name),
                comment: $meta['comment'],
                options: $meta['options'],
            );
        }

        return new SchemaDefinition($tables);
    }

    /**
     * @return array<string,array{comment:?string,options:TableOptions}>
     */
    private function tableRows(): array
    {
        $rows = $this->query(
            'SELECT TABLE_NAME, ENGINE, TABLE_COLLATION, TABLE_COMMENT
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = ? AND TABLE_TYPE = \'BASE TABLE\' ORDER BY TABLE_NAME',
            [$this->database],
        );

        $tables = [];

        foreach ($rows as $row) {
            $comment = (string) ($row['TABLE_COMMENT'] ?? '');

            $tables[strtolower((string) $row['TABLE_NAME'])] = [
                // O MySQL devolve string vazia para "sem comentário", não NULL.
                'comment' => $comment === '' ? null : $comment,
                // O valor REAL, sempre — inclusive quando é o default do servidor.
                //
                // Tentar reportar `null` para "é só o default" parece mais limpo e está
                // errado: o MySQL não registra intenção, então um model que declara
                // `engine: 'InnoDB'` explicitamente ficaria divergindo de um banco que
                // reportasse null. Reportar o valor real acerta os dois casos, porque
                // `null` do lado DECLARADO já significa "não compare".
                'options' => new TableOptions(
                    engine: self::nullIfEmpty($row['ENGINE'] ?? null),
                    collation: self::nullIfEmpty($row['TABLE_COLLATION'] ?? null),
                ),
            ];
        }

        return $tables;
    }

    /**
     * @return list<ColumnDefinition>
     */
    private function columnsOf(string $table): array
    {
        $rows = $this->query(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA,
                    COLLATION_NAME, COLUMN_COMMENT
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ? ORDER BY ORDINAL_POSITION',
            [$this->database, $table],
        );

        $columns = [];

        foreach ($rows as $row) {
            $extra = strtolower((string) ($row['EXTRA'] ?? ''));
            $autoIncrement = str_contains($extra, 'auto_increment');
            $comment = (string) ($row['COLUMN_COMMENT'] ?? '');

            $columns[] = new ColumnDefinition(
                name: (string) $row['COLUMN_NAME'],
                type: MysqlNormalizer::type((string) $row['COLUMN_TYPE']),
                notNull: strtoupper((string) $row['IS_NULLABLE']) === 'NO',
                autoIncrement: $autoIncrement,
                default: $autoIncrement
                    // Coluna auto incremento não tem default próprio; o valor vem da
                    // sequência. Reportar um default aqui criaria diferença contra um
                    // schema declarado que, corretamente, não declara nenhum.
                    ? DefaultValue::none()
                    : MysqlNormalizer::default(
                        $row['COLUMN_DEFAULT'],
                        $extra,
                        (string) $row['COLUMN_TYPE'],
                    ),
                comment: $comment === '' ? null : $comment,
                // Collation por coluna só é reportada quando difere da tabela; o
                // catálogo a repete em toda coluna textual, e reportá-la faria toda
                // coluna VARCHAR divergir de um schema que não a declara.
                collation: null,
            );
        }

        return $columns;
    }

    /**
     * @return array{primaryKey:?PrimaryKeyDefinition,uniques:list<UniqueConstraintDefinition>,foreignKeys:list<ForeignKeyDefinition>,names:array<string,string>}
     */
    private function constraintsOf(string $table): array
    {
        $rows = $this->query(
            'SELECT tc.CONSTRAINT_NAME, tc.CONSTRAINT_TYPE, kcu.COLUMN_NAME, kcu.ORDINAL_POSITION,
                    kcu.REFERENCED_TABLE_NAME, kcu.REFERENCED_COLUMN_NAME,
                    rc.DELETE_RULE, rc.UPDATE_RULE
             FROM information_schema.TABLE_CONSTRAINTS tc
             JOIN information_schema.KEY_COLUMN_USAGE kcu
               ON kcu.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
              AND kcu.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
              AND kcu.TABLE_NAME = tc.TABLE_NAME
             LEFT JOIN information_schema.REFERENTIAL_CONSTRAINTS rc
               ON rc.CONSTRAINT_SCHEMA = tc.CONSTRAINT_SCHEMA
              AND rc.CONSTRAINT_NAME = tc.CONSTRAINT_NAME
              AND rc.TABLE_NAME = tc.TABLE_NAME
             WHERE tc.TABLE_SCHEMA = ? AND tc.TABLE_NAME = ?
               AND tc.CONSTRAINT_TYPE IN (\'PRIMARY KEY\', \'UNIQUE\', \'FOREIGN KEY\')
             ORDER BY tc.CONSTRAINT_NAME, kcu.ORDINAL_POSITION',
            [$this->database, $table],
        );

        $grouped = [];

        foreach ($rows as $row) {
            $name = (string) $row['CONSTRAINT_NAME'];
            $grouped[$name]['type'] = (string) $row['CONSTRAINT_TYPE'];
            $grouped[$name]['columns'][] = (string) $row['COLUMN_NAME'];
            $grouped[$name]['referencedTable'] = self::nullIfEmpty($row['REFERENCED_TABLE_NAME'] ?? null);
            $grouped[$name]['referencedColumns'][] = self::nullIfEmpty($row['REFERENCED_COLUMN_NAME'] ?? null);
            $grouped[$name]['onDelete'] = (string) ($row['DELETE_RULE'] ?? 'NO ACTION');
            $grouped[$name]['onUpdate'] = (string) ($row['UPDATE_RULE'] ?? 'NO ACTION');
        }

        $primaryKey = null;
        $uniques = [];
        $foreignKeys = [];
        $names = [];

        foreach ($grouped as $name => $constraint) {
            $columns = array_values(array_unique($constraint['columns']));
            $names[strtolower($name)] = $constraint['type'];

            if ($constraint['type'] === 'PRIMARY KEY') {
                // O MySQL chama toda chave primária de `PRIMARY`. O nome é irrelevante:
                // o differ compara chave primária apenas pela lista de colunas, o que é
                // exatamente o que permite adotar um banco existente sem gerar migração.
                $primaryKey = new PrimaryKeyDefinition($table . '_pk', $columns);

                continue;
            }

            if ($constraint['type'] === 'UNIQUE') {
                $uniques[] = new UniqueConstraintDefinition($name, $columns);

                continue;
            }

            $referenced = $constraint['referencedTable'];

            if ($referenced === null) {
                continue;
            }

            $foreignKeys[] = new ForeignKeyDefinition(
                name: $name,
                columns: $columns,
                referencedTable: $referenced,
                referencedColumns: array_values(array_unique(array_filter(
                    $constraint['referencedColumns'],
                    static fn (?string $column): bool => $column !== null,
                ))),
                onDelete: ReferentialAction::canonical($constraint['onDelete']),
                onUpdate: ReferentialAction::canonical($constraint['onUpdate']),
            );
        }

        return [
            'primaryKey' => $primaryKey,
            'uniques' => $uniques,
            'foreignKeys' => $foreignKeys,
            'names' => $names,
        ];
    }

    /**
     * @param array{primaryKey:?PrimaryKeyDefinition,uniques:list<UniqueConstraintDefinition>,foreignKeys:list<ForeignKeyDefinition>,names:array<string,string>} $constraints
     * @return list<IndexDefinition>
     */
    private function indexesOf(string $table, array $constraints): array
    {
        $rows = $this->query(
            'SELECT INDEX_NAME, COLUMN_NAME, NON_UNIQUE, INDEX_TYPE
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$this->database, $table],
        );

        $grouped = [];

        foreach ($rows as $row) {
            $name = (string) $row['INDEX_NAME'];
            $grouped[$name]['columns'][] = (string) $row['COLUMN_NAME'];
            $grouped[$name]['unique'] = (int) $row['NON_UNIQUE'] === 0;
            $grouped[$name]['method'] = (string) ($row['INDEX_TYPE'] ?? 'BTREE');
        }

        $indexes = [];

        foreach ($grouped as $name => $index) {
            if (self::isConstraintBackedIndex($name, $constraints['names'])) {
                continue;
            }

            if (self::isImplicitForeignKeyIndex($index['columns'], $constraints['foreignKeys'])) {
                continue;
            }

            $indexes[] = new IndexDefinition(
                $name,
                $index['columns'],
                $index['unique'],
                MysqlNormalizer::indexMethod($index['method']),
            );
        }

        return $indexes;
    }

    /**
     * Regra 1: no MySQL a chave primária e cada restrição UNIQUE aparecem também em
     * `STATISTICS`, porque são implementadas como índice. Elas já foram lidas em
     * `TABLE_CONSTRAINTS`; reportá-las de novo aqui as duplicaria, e o differ acusaria
     * um índice a mais em toda tabela com chave.
     *
     * @param array<string,string> $constraintNames
     */
    private static function isConstraintBackedIndex(string $indexName, array $constraintNames): bool
    {
        return strtoupper($indexName) === 'PRIMARY' || isset($constraintNames[strtolower($indexName)]);
    }

    /**
     * Regra 2: o InnoDB cria um índice de apoio para toda foreign key que ainda não
     * tenha um, e o batiza com o nome da constraint. Ele não foi declarado por ninguém e
     * não pode ser removido enquanto a foreign key existir, então reportá-lo produziria
     * um `DROP INDEX` que o banco recusa — em toda tabela com foreign key.
     *
     * O casamento é pela LISTA DE COLUNAS ser exatamente o prefixo da foreign key, e não
     * pelo nome: um índice declarado à mão sobre as mesmas colunas é indistinguível do
     * implícito, e nesse caso perder o declarado é melhor que gerar DDL impossível.
     *
     * @param list<string>               $columns
     * @param list<ForeignKeyDefinition> $foreignKeys
     */
    private static function isImplicitForeignKeyIndex(array $columns, array $foreignKeys): bool
    {
        foreach ($foreignKeys as $foreignKey) {
            if ($foreignKey->columns === array_values($columns)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<CheckConstraintDefinition>
     */
    private function checksOf(string $table): array
    {
        // CHECK_CONSTRAINTS existe a partir do MySQL 8.0.16. Num servidor mais antigo a
        // consulta falha, e "não sei ler checks" é melhor que derrubar a introspecção
        // inteira: divergência de CHECK é advisória de qualquer forma.
        try {
            $rows = $this->query(
                'SELECT cc.CONSTRAINT_NAME, cc.CHECK_CLAUSE
                 FROM information_schema.CHECK_CONSTRAINTS cc
                 JOIN information_schema.TABLE_CONSTRAINTS tc
                   ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA
                  AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
                 WHERE cc.CONSTRAINT_SCHEMA = ? AND tc.TABLE_NAME = ?
                 ORDER BY cc.CONSTRAINT_NAME',
                [$this->database, $table],
            );
        } catch (\Throwable) {
            return [];
        }

        return array_map(
            static fn (array $row): CheckConstraintDefinition => new CheckConstraintDefinition(
                (string) $row['CONSTRAINT_NAME'],
                MysqlNormalizer::checkExpression((string) $row['CHECK_CLAUSE']),
            ),
            $rows,
        );
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

    private static function nullIfEmpty(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $string = (string) $value;

        return $string === '' ? null : $string;
    }
}
