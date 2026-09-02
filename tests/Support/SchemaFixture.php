<?php

declare(strict_types=1);

namespace Tests\Support;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Migrations\DatabaseAdmin;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Diff\DependencyGraph;
use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Migrations\Introspection\Introspector;
use Diogodg\Neoorm\Migrations\Runner\PdoExecutor;
use Diogodg\Neoorm\Migrations\SchemaApplier;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\SchemaRegistry;
use PDO;

/**
 * Monta o schema dos models no banco de teste, uma vez por processo.
 *
 * Substitui `(new Migrate)->execute(true)` em `setUpBeforeClass`, que derrubava e
 * recriava o BANCO INTEIRO duas vezes por execução da suíte — uma por classe de teste.
 * Aqui a montagem é idempotente: a primeira chamada converge o schema, as seguintes
 * não fazem nada.
 *
 * E substitui o `clearTables()` que dava `DROP TABLE` numa lista fixa de onze nomes
 * escrita à mão. Aquela lista era a causa direta da dependência de ordem dos testes de
 * ORM: ela derrubava as tabelas no meio da execução, e o teste seguinte só passava se
 * outro já as tivesse recriado.
 *
 * Note o que este arquivo NÃO faz: não normaliza nada, não conserta nada, não sabe
 * qual banco está rodando além de perguntar ao dialeto. Ele usa o mesmo caminho de
 * produção que o `Migrate` — carregar, validar, introspectar, comparar, aplicar. Se o
 * pipeline estiver errado, os testes de ORM falham, e é isso que se quer.
 */
final class SchemaFixture
{
    private static bool $ensured = false;

    private function __construct()
    {
    }

    /**
     * Converge o banco de teste com o schema declarado nos models.
     */
    public static function ensure(): void
    {
        if (self::$ensured) {
            return;
        }

        $config = DatabaseConfig::fromConfig();
        $dialect = DialectFactory::for($config->driver);

        (new DatabaseAdmin($config))->createIfMissing();

        $schema = self::schema();
        $current = (new Introspector(Connection::getConnection(), $config))
            ->introspect($schema->tableNames());

        $operations = (new SchemaDiffer())->diff(
            $current,
            Snapshot::initial($dialect->name(), $schema),
        );

        if (!$operations->isEmpty()) {
            (new SchemaApplier($dialect, new PdoExecutor(Connection::getConnection())))->apply($operations);
        }

        self::$ensured = true;
    }

    public static function schema(): SchemaDefinition
    {
        return self::loader()->loadValidated(DatabaseConfig::fromConfig()->driver);
    }

    public static function loader(): ModelSchemaLoader
    {
        return new ModelSchemaLoader(
            Config::getPathModel(),
            Config::getModelNamespace(),
        );
    }

    /**
     * Esvazia todas as tabelas e reinicia os contadores de auto incremento.
     *
     * Reiniciar os contadores é o que torna um teste reproduzível: sem isso, os ids
     * dependem de quantos testes rodaram antes, e sob ordem aleatória de execução isso é
     * outra forma de dependência de ordem.
     */
    public static function truncateAll(): void
    {
        self::ensure();

        $pdo = Connection::getConnection();
        $dialect = DialectFactory::for(DatabaseConfig::fromConfig()->driver);
        $schema = self::schema();

        $tables = array_map(
            static fn (string $table): string => $dialect->quoteIdentifier($table),
            DependencyGraph::fromSchema($schema)->reverse(),
        );

        if ($tables === []) {
            return;
        }

        if ($dialect->name() === 'pgsql') {
            // Um único TRUNCATE com todas as tabelas: CASCADE resolve as referências
            // entre elas sem precisar desligar verificação nenhuma.
            $pdo->exec('TRUNCATE ' . implode(', ', $tables) . ' RESTART IDENTITY CASCADE');

            return;
        }

        // O MySQL não tem TRUNCATE em cascata, e recusa truncar uma tabela referenciada.
        // Desligar a verificação para a sessão é o caminho documentado, e a ordem inversa
        // de dependência tornaria isso desnecessário se não fosse a auto-referência.
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                $pdo->exec('TRUNCATE TABLE ' . $table);
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    /**
     * Marca o schema como não montado. Só para os testes que mexem no próprio schema.
     */
    public static function forget(): void
    {
        self::$ensured = false;
        SchemaRegistry::flush();
    }

    /**
     * Derruba e recria o banco. Caro: só onde o teste precisa de um banco vazio de fato.
     */
    public static function recreate(): void
    {
        Connection::close();
        (new DatabaseAdmin(DatabaseConfig::fromConfig()))->recreate();
        self::forget();
        self::ensure();
    }

    public static function pdo(): PDO
    {
        return Connection::getConnection();
    }
}
