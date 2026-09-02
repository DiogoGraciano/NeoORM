<?php

namespace Diogodg\Neoorm;

final class Config
{
    /**
     * O layout padrão de um projeto NeoFramework, e a única definição dele.
     *
     * Todo caminho e namespace de configuração tem default AQUI. Antes só
     * `PATH_MIGRATIONS` e os de seeder tinham: `PATH_MODEL` ausente virava string
     * vazia, que descia até o loader como "diretório '' não encontrado", e
     * `MODEL_NAMESPACE` ausente era remendado com um `?: 'App\Models'` repetido em
     * cada chamador — dois defaults para a mesma chave, um deles invisível para
     * `PATH_GENERATED`, que preferia lançar exceção a assumir o mesmo padrão.
     *
     * Os caminhos são relativos de propósito: `resolvePath()` os ancora na raiz do
     * projeto, então o default vale igual rodando o CLI de qualquer subdiretório.
     *
     * @var array<string,string>
     */
    private const DEFAULTS = [
        'PATH_MODEL' => './App/Models',
        'MODEL_NAMESPACE' => 'App\\Models',
        'PATH_SEEDS' => './App/Seeders',
        'SEEDER_NAMESPACE' => 'App\\Seeders',
        'PATH_MIGRATIONS' => './Migrations',
    ];

    /**
     * Valores lidos do arquivo .env, carregados uma única vez.
     *
     * @var array<string,string>|null
     */
    private static ?array $fileEnv = null;

    /**
     * Raiz do projeto, resolvida uma única vez e usada como âncora de todo
     * caminho relativo da configuração.
     */
    private static ?string $projectRoot = null;

    private function __construct(){
    }

    /**
     * Carrega o .env do projeto, se ainda não tiver sido carregado.
     *
     * Os valores de $_ENV/$_SERVER têm precedência sobre o arquivo, para que
     * variáveis de ambiente (container, CI, phpunit.xml) possam sobrescrever.
     */
    private static function init(): void
    {
        if (self::$fileEnv !== null) {
            return;
        }

        // findEnvFile() sobe até 8 diretórios. Numa suíte de testes isso alcança
        // pastas fora do repositório, e um .env de outro projeto apontaria os
        // testes para um banco de verdade. A flag desliga a busca por completo.
        if (self::isTruthy($_ENV['NEOORM_DISABLE_DOTENV'] ?? $_SERVER['NEOORM_DISABLE_DOTENV'] ?? null)) {
            self::$fileEnv = [];
            return;
        }

        $path = self::findEnvFile();

        if ($path === null) {
            self::$fileEnv = [];
            return;
        }

        // INI_SCANNER_RAW preserva senhas com #, $, aspas e outros caracteres
        // que o parser padrão do parse_ini_file interpretaria.
        $parsed = parse_ini_file($path, false, INI_SCANNER_RAW);

        self::$fileEnv = $parsed === false ? [] : $parsed;
    }

    /**
     * Localiza o .env do projeto.
     *
     * Primeiro na raiz resolvida — que é onde ele de fato está — e só depois
     * subindo a partir deste arquivo. A busca a partir de __DIR__ sozinha não
     * basta: com o pacote instalado por path repository e symlink, __DIR__
     * aponta para o repositório da própria lib, e subir dali nunca chega ao
     * projeto que a consome.
     */
    private static function findEnvFile(): ?string
    {
        $candidate = self::getProjectRoot() . '.env';

        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }

        return self::findUpFile(__DIR__, '.env');
    }

    private static function findUpFile(string $from, string $file): ?string
    {
        $dir = $from;

        for ($i = 0; $i < 8; $i++) {
            $candidate = $dir . DIRECTORY_SEPARATOR . $file;

            if (is_file($candidate) && is_readable($candidate)) {
                return $candidate;
            }

            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }

        return null;
    }

    private static function isTruthy(mixed $value): bool
    {
        if (!is_scalar($value)) {
            return false;
        }

        return !in_array(strtolower(trim((string) $value)), ['', '0', 'false', 'off', 'no'], true);
    }

    /**
     * Lê uma configuração, com precedência: $_ENV > $_SERVER > arquivo .env.
     */
    private static function get(string $key, string $default = ""): string
    {
        self::init();

        $value = $_ENV[$key] ?? $_SERVER[$key] ?? self::$fileEnv[$key] ?? $default;

        return is_scalar($value) ? (string) $value : $default;
    }

    /**
     * Lê uma chave de `DEFAULTS`, tratando valor vazio como valor ausente.
     *
     * A distinção importa porque um `.env` copiado do `.env.example` costuma ter a
     * chave declarada e apagada (`PATH_MODEL=`), e o `??` de `get()` devolveria a
     * string vazia — configuração pela metade seguindo adiante como se fosse
     * escolha deliberada. Aqui só existem dois estados: o valor que o projeto
     * declarou, ou o padrão.
     */
    private static function getConfigured(string $key): string
    {
        $value = trim(self::get($key, self::DEFAULTS[$key]));

        return $value === '' ? self::DEFAULTS[$key] : $value;
    }

    /**
     * Limpa o cache do .env e da raiz do projeto. Útil em testes que trocam a
     * configuração.
     */
    public static function reset(): void
    {
        self::$fileEnv = null;
        self::$projectRoot = null;
    }

    /**
     * Raiz do projeto, sempre terminada em separador de diretório.
     *
     * `PATH_MODEL=./App/Models` só funcionava porque se rodava o CLI da raiz do
     * projeto — de um subdiretório, a migração falhava com "diretório de models
     * não encontrado". Agora todo caminho relativo é resolvido contra esta
     * âncora, o que também é pré-requisito para escrever arquivos de migração
     * no repositório de quem usa a lib.
     *
     * Ordem: NEOORM_ROOT explícito, ancestral mais próximo do diretório de
     * trabalho com composer.json, ancestral de __DIR__ com composer.json fora
     * de vendor/, diretório de trabalho.
     *
     * A busca a partir do diretório de trabalho vem primeiro porque é a única
     * que sobrevive a `"symlink": true` num path repository: nesse arranjo o
     * __DIR__ resolve para o repositório real da lib, e subir dali elegeria a
     * própria NeoORM como "projeto" — silenciosamente, escrevendo as migrações
     * do usuário dentro do diretório da dependência.
     */
    public static function getProjectRoot(): string
    {
        if (self::$projectRoot !== null) {
            return self::$projectRoot;
        }

        // Lê a variável direto do ambiente: passar por get() chamaria init(),
        // que chama findEnvFile(), que chama este método.
        $explicit = $_ENV['NEOORM_ROOT'] ?? $_SERVER['NEOORM_ROOT'] ?? null;

        if (is_string($explicit) && $explicit !== '') {
            return self::$projectRoot = self::withTrailingSeparator($explicit);
        }

        $cwd = getcwd();

        $found = ($cwd !== false ? self::findUpDir($cwd, 'composer.json') : null)
            ?? self::findUpDir(__DIR__, 'composer.json');

        if ($found !== null) {
            return self::$projectRoot = self::withTrailingSeparator($found);
        }

        return self::$projectRoot = self::withTrailingSeparator($cwd === false ? __DIR__ : $cwd);
    }

    /**
     * Fixa a raiz do projeto. Existe para testes e para embutir a lib em
     * layouts que a busca automática não cobre.
     */
    public static function setProjectRoot(?string $path): void
    {
        self::$projectRoot = $path === null ? null : self::withTrailingSeparator($path);
    }

    /**
     * Sobe a árvore procurando o diretório que contém um marcador.
     *
     * Ignora candidatos dentro de vendor/: instalada como dependência, a
     * própria lib tem composer.json, e parar ali devolveria o diretório do
     * pacote em vez do projeto.
     */
    private static function findUpDir(string $from, string $marker): ?string
    {
        $dir = $from;

        for ($i = 0; $i < 16; $i++) {
            $inVendor = str_contains(
                $dir . DIRECTORY_SEPARATOR,
                DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR,
            );

            if (!$inVendor && is_file($dir . DIRECTORY_SEPARATOR . $marker)) {
                return $dir;
            }

            $parent = dirname($dir);

            if ($parent === $dir) {
                break;
            }

            $dir = $parent;
        }

        return null;
    }

    /**
     * Torna absoluto um caminho de configuração, ancorando-o na raiz do projeto
     * em vez do diretório de trabalho do processo.
     */
    private static function resolvePath(string $path): string
    {
        if ($path === '') {
            return $path;
        }

        $isAbsolute = $path[0] === '/'
            || $path[0] === '\\'
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;

        if ($isAbsolute) {
            return $path;
        }

        return self::getProjectRoot() . ltrim((string) preg_replace('#^\./#', '', $path), '/\\');
    }

    private static function withTrailingSeparator(string $path): string
    {
        return rtrim($path, '/\\') . DIRECTORY_SEPARATOR;
    }

    public static function getDriver():string
    {
        return self::get("DRIVER");
    }

    public static function getHost():string
    {
        return self::get("DBHOST");
    }

    public static function getPort():string
    {
        return self::get("DBPORT");
    }

    public static function getDbName():string
    {
        return self::get("DBNAME");
    }

    public static function getCharset():string
    {
        return self::get("DBCHARSET");
    }

    public static function getUser():string
    {
        return self::get("DBUSER");
    }

    public static function getPassword():string
    {
        return self::get("DBPASSWORD");
    }

    /**
     * Diretório dos models, varrido recursivamente. Padrão: `./App/Models`.
     */
    public static function getPathModel():string
    {
        return self::resolvePath(self::getConfigured("PATH_MODEL"));
    }

    /**
     * Namespace raiz dos models, par do `PATH_MODEL` no mapeamento PSR-4.
     * Padrão: `App\Models`.
     */
    public static function getModelNamespace():string
    {
        return trim(self::getConfigured("MODEL_NAMESPACE"), "\\");
    }

    /**
     * O ambiente em que o processo roda.
     *
     * Serve para os comandos que mexem no banco direto — `db:push` e `db:reset` — se
     * recusarem em produção. Sem default de "prod": o default é o ambiente PERMISSIVO,
     * porque tratar configuração ausente como produção faria a biblioteca recusar
     * trabalho num projeto que só esqueceu de declarar a chave, e o conserto óbvio seria
     * declarar `dev` — ou seja, a proteção não protegeria ninguém e só atrapalharia.
     */
    public static function getEnvironment():string
    {
        return self::get("ENVIRONMENT", "dev");
    }

    /**
     * Diretório dos artefatos de migração: os .sql numerados, o journal.json e
     * os snapshots em meta/. É código-fonte, versionado junto com o projeto.
     */
    public static function getPathMigrations():string
    {
        return self::resolvePath(self::getConfigured("PATH_MIGRATIONS"));
    }

    /**
     * Diretório do código gerado. Padrão: `{PATH_MODEL}/Generated`.
     *
     * Fica debaixo de PATH_MODEL porque o consumidor já tem o mapeamento PSR-4 de
     * MODEL_NAMESPACE para lá — então `Generated/` é autoloada sem editar o
     * composer.json, que seria a falha de configuração mais comum se exigisse
     * entrada nova.
     */
    public static function getPathGenerated():string
    {
        $explicit = trim(self::get("PATH_GENERATED"));

        if ($explicit !== "") {
            return self::resolvePath($explicit);
        }

        return rtrim(self::getPathModel(), "/\\") . DIRECTORY_SEPARATOR . "Generated";
    }

    /**
     * Namespace do código gerado. Padrão: `{MODEL_NAMESPACE}\Generated`.
     */
    public static function getGeneratedNamespace():string
    {
        $explicit = trim(self::get("GENERATED_NAMESPACE"));

        if ($explicit !== "") {
            return trim($explicit, "\\");
        }

        return self::getModelNamespace() . "\\Generated";
    }

    /**
     * Diretório dos seeders. Padrão: `./App/Seeders`.
     *
     * Fora de PATH_MODEL de propósito: um seeder não descreve tabela, e deixá-lo lá
     * faria o `ModelSchemaLoader` carregá-lo em toda execução só para descartá-lo. O
     * padrão fica sob `App\` porque o skeleton já mapeia esse namespace no PSR-4 —
     * exigir entrada nova no composer.json seria a falha de configuração mais comum.
     */
    public static function getPathSeeds():string
    {
        return self::resolvePath(self::getConfigured("PATH_SEEDS"));
    }

    /**
     * Namespace dos seeders. Padrão: `App\Seeders`.
     */
    public static function getSeederNamespace():string
    {
        return trim(self::getConfigured("SEEDER_NAMESPACE"), "\\");
    }

    /**
     * Tabela de controle das migrações aplicadas.
     *
     * O nome entra em DDL, onde não há como parametrizar, então é validado
     * aqui — na fronteira — em vez de confiar em quem chama.
     */
    public static function getMigrationsTable():string
    {
        $table = self::get("MIGRATIONS_TABLE", "_neoorm_migrations");

        if (!preg_match('/^[a-zA-Z_][a-zA-Z0-9_]*$/', $table)) {
            throw new \InvalidArgumentException("MIGRATIONS_TABLE inválido: {$table}");
        }

        return $table;
    }

    /**
     * Schema do PostgreSQL. O introspector precisa dele para filtrar: o leitor
     * antigo filtrava tabelas pgsql por table_catalog, que é o *banco*, e por
     * isso enxergava tabelas de todos os schemas.
     */
    public static function getSchema():string
    {
        return self::get("DBSCHEMA", self::getDriver() === 'pgsql' ? 'public' : '');
    }

    /**
     * Com modo estrito, comandos que alteram o banco diretamente recusam DDL
     * destrutivo sem confirmação explícita.
     */
    public static function isMigrationsStrict():bool
    {
        return self::isTruthy(self::get("MIGRATIONS_STRICT", "true"));
    }
}
