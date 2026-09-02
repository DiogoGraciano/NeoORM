<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Seed;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use ReflectionClass;

/**
 * Lê os seeders de um diretório e os indexa por tabela.
 *
 * Espelha o `ModelSchemaLoader`: caminho e namespace por construtor, nunca `Config`,
 * varredura recursiva, PSR-4. A simetria não é estética — é o que permite ao teste
 * apontar para um root alternativo e isolar o caso de borda, em vez de depender do
 * diretório de seeders de verdade do projeto.
 *
 * Difere num ponto de propósito: diretório inexistente NÃO é erro. Projeto sem seeder
 * nenhum é o caso comum, e falhar ali transformaria `db:seed` num comando que só roda
 * depois de alguém criar uma pasta vazia. Diretório de models inexistente, ao contrário,
 * é sempre configuração errada — não há projeto sem model.
 */
final class SeederLoader
{
    private readonly string $path;

    private readonly string $namespace;

    /** @var array<string,class-string<Seeder>>|null */
    private ?array $cache = null;

    public function __construct(string $path, string $namespace)
    {
        $this->path = rtrim($path, '/\\');
        $this->namespace = trim($namespace, '\\');
    }

    public function path(): string
    {
        return $this->path;
    }

    public function namespace(): string
    {
        return $this->namespace;
    }

    /**
     * Mapa `tabela => classe`, em ordem alfabética de tabela.
     *
     * @return array<string,class-string<Seeder>>
     */
    public function seeders(): array
    {
        if ($this->cache !== null) {
            return $this->cache;
        }

        $byTable = [];
        $duplicates = [];

        foreach ($this->seederClasses() as $class) {
            $table = strtolower(trim($class::table()));

            if ($table === '') {
                throw new MigrationException(
                    "O seeder {$class} devolve uma tabela vazia. `table()` precisa devolver o nome da "
                    . 'tabela que ele popula — normalmente a constante do model, como `State::table`.',
                );
            }

            if (isset($byTable[$table])) {
                $duplicates[] = "'{$table}': {$byTable[$table]} e {$class}";
                continue;
            }

            $byTable[$table] = $class;
        }

        // Reunidos e reportados de uma vez, como o SchemaValidator faz: corrigir um por
        // execução é o modo de falha que a 2.0 removeu em todo lugar.
        if ($duplicates !== []) {
            throw new MigrationException(
                'Mais de um seeder para a mesma tabela — só pode haver um:' . "\n  - "
                . implode("\n  - ", $duplicates),
            );
        }

        ksort($byTable, SORT_STRING);

        return $this->cache = $byTable;
    }

    /**
     * Os seeders, conferidos contra o schema.
     *
     * Um seeder cuja tabela não existe é erro, e não algo a ignorar: hoje isso é
     * silêncio — o `SeedRunner` percorre as tabelas do schema, então um `table()` com
     * typo simplesmente nunca roda, e a única evidência é a linha que não apareceu no
     * banco.
     *
     * @return array<string,class-string<Seeder>>
     */
    public function seedersFor(SchemaDefinition $schema): array
    {
        $seeders = $this->seeders();
        $unknown = [];

        foreach ($seeders as $table => $class) {
            if ($schema->table($table) === null) {
                $unknown[] = "{$class} aponta para '{$table}'";
            }
        }

        if ($unknown !== []) {
            throw new MigrationException(
                "Seeder para tabela que não existe no schema:\n  - " . implode("\n  - ", $unknown)
                . "\nConfira o `table()` do seeder e o nome declarado no model.",
            );
        }

        return $seeders;
    }

    /**
     * @return list<class-string<Seeder>>
     */
    public function seederClasses(): array
    {
        if (!is_dir($this->path)) {
            return [];
        }

        $classes = [];

        foreach ($this->phpFiles() as $relativePath) {
            $class = $this->classFor($relativePath);

            if (!class_exists($class)) {
                continue;
            }

            if (!is_subclass_of($class, Seeder::class)) {
                continue;
            }

            if ((new ReflectionClass($class))->isAbstract()) {
                continue;
            }

            /** @var class-string<Seeder> $class */
            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $paths = [];
        $queue = [''];

        while ($queue !== []) {
            $relativeDir = array_shift($queue);
            $absoluteDir = $relativeDir === ''
                ? $this->path
                : $this->path . DIRECTORY_SEPARATOR . $relativeDir;

            $entries = scandir($absoluteDir);

            if ($entries === false) {
                throw new MigrationException("Não foi possível listar '{$absoluteDir}'.");
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $relative = $relativeDir === '' ? $entry : $relativeDir . DIRECTORY_SEPARATOR . $entry;

                if (is_dir($absoluteDir . DIRECTORY_SEPARATOR . $entry)) {
                    $queue[] = $relative;
                    continue;
                }

                if (str_ends_with($entry, '.php')) {
                    $paths[] = $relative;
                }
            }
        }

        sort($paths, SORT_STRING);

        return $paths;
    }

    private function classFor(string $relativePath): string
    {
        $withoutExtension = substr($relativePath, 0, -strlen('.php'));
        $segments = preg_split('#[/\\\\]#', $withoutExtension) ?: [$withoutExtension];

        return $this->namespace . '\\' . implode('\\', $segments);
    }
}
