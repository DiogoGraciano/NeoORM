<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Naming;

/**
 * A única fonte de nomes de constraint e índice.
 *
 * Dois motivos para centralizar. Primeiro, determinismo: o snapshot compara
 * nomes, então gerá-los ad hoc em cada lugar (como o sistema antigo fazia,
 * concatenando `{tabela}_{col}_{tabelaRef}_{colRef}` dentro do driver) faz o
 * mesmo schema produzir nomes diferentes conforme o caminho de código.
 *
 * Segundo, o limite de identificador: PostgreSQL corta em 63 caracteres e
 * MySQL em 64. Um nome longo demais era silenciosamente truncado pelo banco, e
 * duas FKs de nomes longos e parecidos colidiam depois do corte. O truncamento
 * aqui é explícito e usa hash, então nomes distintos continuam distintos.
 */
final class ConstraintNamer
{
    /** min(MySQL 64, PostgreSQL 63). */
    public const MAX_LENGTH = 63;

    /** Espaço reservado para `_` + 8 caracteres de hash. */
    private const HASH_LENGTH = 8;

    private function __construct()
    {
    }

    public static function primaryKey(string $table): string
    {
        return self::truncate("{$table}_pk");
    }

    /**
     * @param list<string> $columns
     */
    public static function unique(string $table, array $columns): string
    {
        return self::truncate($table . '_' . implode('_', $columns) . '_unique');
    }

    /**
     * @param list<string> $columns
     */
    public static function index(string $table, array $columns, bool $unique = false): string
    {
        $suffix = $unique ? '_unique_index' : '_index';

        return self::truncate($table . '_' . implode('_', $columns) . $suffix);
    }

    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public static function foreignKey(
        string $table,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
    ): string {
        return self::truncate(
            $table
            . '_' . implode('_', $columns)
            . '_' . $referencedTable
            . '_' . implode('_', $referencedColumns)
            . '_fk',
        );
    }

    public static function check(string $table, string $expression): string
    {
        return self::truncate($table . '_' . substr(sha1($expression), 0, self::HASH_LENGTH) . '_check');
    }

    /**
     * Encurta preservando distinção: mantém o prefixo legível e anexa um hash
     * do nome completo. Truncar sem hash faria dois nomes longos que só diferem
     * no fim colapsarem no mesmo identificador.
     */
    public static function truncate(string $name): string
    {
        if (strlen($name) <= self::MAX_LENGTH) {
            return $name;
        }

        $keep = self::MAX_LENGTH - self::HASH_LENGTH - 1;

        return substr($name, 0, $keep) . '_' . substr(sha1($name), 0, self::HASH_LENGTH);
    }
}
