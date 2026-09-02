<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Introspection;

use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use PDO;

/**
 * O banco, lido como snapshot.
 *
 * Existir é a diferença central em relação ao sistema antigo. Lá, o differ comparava
 * o model contra cinco tabelas `_schema_*` dentro do próprio banco — tabelas que
 * registravam o que o código AFIRMOU ter feito. Quando o que ele afirmou e o que o
 * banco tinha divergiam, nada percebia: uma tabela criada por fora ficava
 * "sincronizada" para sempre, um índice que falhou continuava registrado como criado.
 *
 * Aqui a fonte é o catálogo. `differ(introspectado, declarado)` vazio é uma afirmação
 * sobre o banco, não sobre o histórico do código.
 */
final class Introspector
{
    private readonly CatalogReader $reader;

    public function __construct(PDO $pdo, private readonly DatabaseConfig $config)
    {
        $this->reader = match ($config->driver) {
            'mysql' => new MysqlCatalogReader($pdo, $config->database),
            'pgsql' => new PgsqlCatalogReader($pdo, $config->effectiveSchema()),
            default => throw new MigrationException(
                "Sem introspector para o driver '{$config->driver}'. Suportados: mysql, pgsql.",
            ),
        };
    }

    /**
     * @param list<string>|null $onlyTables
     */
    public function introspect(?array $onlyTables = null): Snapshot
    {
        return Snapshot::introspected($this->config->driver, $this->reader->read($onlyTables));
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        return $this->reader->tableNames();
    }

    /**
     * Tabelas que existem no banco e não são descritas por model nenhum.
     *
     * Ficam FORA do drift por default, e isso é deliberadamente o oposto do bug B3: lá,
     * uma tabela desconhecida fazia o sistema considerá-la sincronizada para sempre;
     * aqui ela é listada como desconhecida, e nenhum comando gera `DROP TABLE` para ela
     * sem `--include-unknown`. Uma tabela criada por outro sistema no mesmo banco não
     * pode ser destruída por um migrate.
     *
     * @param list<string> $declared
     * @return list<string>
     */
    public function unknownTables(array $declared, string $migrationsTable): array
    {
        $known = array_fill_keys(array_map('strtolower', $declared), true);
        $known[strtolower($migrationsTable)] = true;

        $unknown = array_values(array_filter(
            $this->tableNames(),
            static fn (string $table): bool => !isset($known[strtolower($table)]),
        ));

        sort($unknown, SORT_STRING);

        return $unknown;
    }
}
