<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Config;
use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\DatabaseConfig;
use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Dialect\DialectFactory;
use Diogodg\Neoorm\Migrations\Introspection\Introspector;
use Diogodg\Neoorm\Migrations\Runner\Executor;
use Diogodg\Neoorm\Migrations\Runner\LockProvider;
use Diogodg\Neoorm\Migrations\Runner\LockProviderFactory;
use Diogodg\Neoorm\Migrations\Runner\MigrationRepository;
use Diogodg\Neoorm\Migrations\Runner\MigrationRunner;
use Diogodg\Neoorm\Migrations\Runner\PdoExecutor;
use Diogodg\Neoorm\Migrations\Runner\PdoMigrationRepository;
use Diogodg\Neoorm\Migrations\Runner\PdoTransactions;
use Diogodg\Neoorm\Migrations\Runner\Transactions;
use Diogodg\Neoorm\Migrations\Seed\SeederLoader;
use Diogodg\Neoorm\Migrations\Snapshot\MigrationDirectory;
use Diogodg\Neoorm\Schema\ModelSchemaLoader;
use Diogodg\Neoorm\Support\Output\NullOutput;
use Diogodg\Neoorm\Support\Output\Output;
use PDO;

/**
 * Tudo que um comando de migração precisa, montado num lugar só.
 *
 * `fromConfig()` é a FRONTEIRA: é o único método deste namespace que lê os estáticos de
 * `Config` e abre conexão. Abaixo daqui nada consulta configuração global — cada
 * componente recebe o que precisa por construtor, e é justamente isso que torna IR,
 * snapshot, differ, dialetos e runner testáveis sem servidor nenhum.
 *
 * O PDO é preguiçoso de propósito. `migration:generate` é 100% offline — ele compara dois
 * arquivos JSON e escreve um `.sql` —, e um contexto que abrisse conexão no construtor
 * transformaria "gerar migração" numa operação que exige banco de pé. Era exatamente o
 * problema do sistema antigo, em que `Model::table()` abria conexão PDO.
 */
final class MigrationContext
{
    private ?PDO $pdo = null;

    private ?MigrationRunner $runner = null;

    public function __construct(
        public readonly DatabaseConfig $database,
        public readonly Dialect $dialect,
        public readonly MigrationDirectory $directory,
        public readonly ModelSchemaLoader $models,
        // Caminho vazio = nenhum seeder. É o default certo porque só `db:seed` usa este
        // campo: um teste de `generate` ou de `up` não deveria precisar declarar de onde
        // viriam dados que ele nunca lê.
        public readonly SeederLoader $seeders = new SeederLoader('', ''),
        public readonly string $migrationsTable = '_neoorm_migrations',
        public readonly Output $output = new NullOutput(),
        public readonly bool $strict = true,
        public readonly string $environment = 'dev',
        ?PDO $pdo = null,
    ) {
        $this->pdo = $pdo;
    }

    /**
     * @param string|null $migrationsPath sobrescreve `PATH_MIGRATIONS`
     * @param string|null $modelsPath sobrescreve `PATH_MODEL`
     * @param string|null $seedsPath sobrescreve `PATH_SEEDS`
     */
    public static function fromConfig(
        Output $output = new NullOutput(),
        ?string $migrationsPath = null,
        ?string $modelsPath = null,
        ?string $seedsPath = null,
    ): self {
        $database = DatabaseConfig::fromConfig();

        return new self(
            $database,
            DialectFactory::for($database->driver, Config::getSchema()),
            new MigrationDirectory($migrationsPath ?? Config::getPathMigrations(), $database->driver),
            new ModelSchemaLoader($modelsPath ?? Config::getPathModel(), Config::getModelNamespace()),
            new SeederLoader($seedsPath ?? Config::getPathSeeds(), Config::getSeederNamespace()),
            Config::getMigrationsTable(),
            $output,
            Config::isMigrationsStrict(),
            Config::getEnvironment(),
        );
    }

    /**
     * A conexão, aberta na primeira vez que alguém precisar dela.
     */
    public function pdo(): PDO
    {
        return $this->pdo ??= Connection::getConnection();
    }

    /**
     * Descarta a conexão memoizada e tudo que a segura.
     *
     * Existe para o `db:reset`, que derruba e recria o banco: o handle anterior aponta para um
     * banco que não existe mais, e sem isto o primeiro statement seguinte falharia com um erro
     * sobre o banco ausente, a duas camadas de distância da causa.
     */
    public function reconnect(): void
    {
        $this->pdo = null;
        $this->runner = null;
    }

    public function executor(): Executor
    {
        return new PdoExecutor($this->pdo());
    }

    public function transactions(): Transactions
    {
        return new PdoTransactions($this->pdo());
    }

    public function repository(): MigrationRepository
    {
        return new PdoMigrationRepository($this->pdo(), $this->dialect, $this->migrationsTable);
    }

    public function lock(): LockProvider
    {
        return LockProviderFactory::for($this->dialect, $this->pdo(), $this->database->database);
    }

    /**
     * O runner é memoizado porque ele guarda o estado "a sessão já foi ajustada".
     *
     * Um runner novo por chamada refaria `SET sql_mode` / `SET search_path` a cada vez —
     * inofensivo, mas ruído no log e um round-trip a mais por migração.
     */
    public function runner(): MigrationRunner
    {
        return $this->runner ??= new MigrationRunner(
            $this->dialect,
            $this->directory,
            $this->repository(),
            $this->executor(),
            $this->transactions(),
            $this->lock(),
            $this->output,
        );
    }

    public function introspector(): Introspector
    {
        return new Introspector($this->pdo(), $this->database);
    }

    public function isProduction(): bool
    {
        return in_array(strtolower($this->environment), ['prod', 'production'], true);
    }

    /**
     * Uma cópia com outra saída, para um comando compor outro sem herdar o log dele.
     */
    public function withOutput(Output $output): self
    {
        return new self(
            $this->database,
            $this->dialect,
            $this->directory,
            $this->models,
            $this->seeders,
            $this->migrationsTable,
            $output,
            $this->strict,
            $this->environment,
            $this->pdo,
        );
    }
}
