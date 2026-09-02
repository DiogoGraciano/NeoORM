<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Dialect;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;

/**
 * O único lugar do sistema que dá match na string do driver.
 *
 * Concentrar isso aqui é o que torna "adicionar um dialeto" uma mudança de um
 * arquivo. No sistema antigo a mesma decisão era tomada de novo em cada classe
 * que precisava saber se era mysql — e cada cópia era uma chance de as duas
 * discordarem.
 */
final class DialectFactory
{
    private function __construct()
    {
    }

    /**
     * @param string|null $schema o `DBSCHEMA`; usado só pelo PostgreSQL, para o
     *                            `search_path`. A fábrica não lê `Config`: quem chama, na
     *                            fronteira do CLI, é quem sabe a configuração.
     */
    public static function for(string $driver, ?string $schema = null): Dialect
    {
        return match (self::canonicalName($driver)) {
            'mysql' => self::mysql(),
            'pgsql' => self::pgsql($schema),
            default => throw new MigrationException(
                "Driver sem dialeto de migração: '{$driver}'. Suportados: mysql, pgsql.",
            ),
        };
    }

    public static function supports(string $driver): bool
    {
        return in_array(self::canonicalName($driver), ['mysql', 'pgsql'], true);
    }

    public static function mysql(): Dialect
    {
        return new MysqlDialect();
    }

    public static function pgsql(?string $schema = null): Dialect
    {
        return new PgsqlDialect($schema);
    }

    /**
     * Todos os dialetos, chaveados pelo nome canônico.
     *
     * Serve aos testes que afirmam invariantes válidas para qualquer dialeto —
     * "todo dialeto compila todas as 20 operações", "nenhum dialeto deixa passar
     * identificador com aspa". Um dialeto novo entra nesses testes só por existir
     * aqui, sem ninguém precisar lembrar de incluí-lo.
     *
     * @return array<string,Dialect>
     */
    public static function all(): array
    {
        return ['mysql' => self::mysql(), 'pgsql' => self::pgsql()];
    }

    /**
     * Aceita as grafias que PDO, `.env` e gente escrevendo à mão usam.
     */
    private static function canonicalName(string $driver): string
    {
        return match (strtolower(trim($driver))) {
            'mysql', 'mariadb' => 'mysql',
            'pgsql', 'postgres', 'postgresql' => 'pgsql',
            default => strtolower(trim($driver)),
        };
    }
}
