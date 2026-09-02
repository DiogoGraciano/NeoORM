<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;

/**
 * Lê os models de um diretório e monta o schema inteiro.
 *
 * Recebe caminho e namespace explicitamente, e nunca consulta `Config`. Não é
 * pureza por esporte: `PATH_MODEL` é varrido por INTEIRO, então um model com chave
 * primária composta ou foreign key auto-referente colocado em `tests/App/Models/`
 * para exercitar o differ entraria também no schema dos testes de ORM. Com o
 * caminho vindo por construtor, os testes apontam para roots alternativos e os
 * casos de borda ficam isolados.
 *
 * A varredura é RECURSIVA e assume PSR-4: `{path}/Billing/Invoice.php` é
 * `{namespace}\Billing\Invoice`. Antes era um `scandir` plano, e a consequência não
 * era um erro e sim um silêncio — um model numa subpasta simplesmente não existia
 * para o schema, então a migração seguinte propunha dropar a tabela dele.
 *
 * É a única porta de I/O de `Schema\`, e é I/O de leitura de diretório — nada de
 * banco.
 */
final class ModelSchemaLoader
{
    /**
     * Diretórios ignorados na varredura, comparados sem diferenciar caixa.
     *
     * `Generated` está aqui porque `PATH_GENERATED` mora sob `PATH_MODEL` por padrão:
     * sem a exclusão, todo comando passaria a carregar o código gerado inteiro para
     * descartá-lo em seguida — e bastaria uma classe gerada ganhar um método `table()`
     * para o descarte virar inclusão silenciosa.
     *
     * @var list<string>
     */
    private const SKIP_DIRECTORIES = ['generated'];

    private readonly string $path;

    private readonly string $namespace;

    /** @var list<string> */
    private readonly array $skip;

    /**
     * @param list<string>|null $skipDirectories sobrescreve os diretórios ignorados
     */
    public function __construct(string $path, string $namespace, ?array $skipDirectories = null)
    {
        $this->path = rtrim($path, '/\\');
        $this->namespace = trim($namespace, '\\');
        $this->skip = array_map(
            'strtolower',
            $skipDirectories ?? self::SKIP_DIRECTORIES,
        );
    }

    public function load(): SchemaDefinition
    {
        $tables = [];

        foreach ($this->modelClasses() as $class) {
            $tables[] = SchemaRegistry::for($class);
        }

        return new SchemaDefinition($tables);
    }

    /**
     * Os models encontrados, em ordem alfabética de caminho relativo — o que põe
     * `Billing/Invoice.php` antes de `User.php`.
     *
     * @return list<class-string>
     */
    public function modelClasses(): array
    {
        if (!is_dir($this->path)) {
            throw new SchemaException(
                "Diretório de models não encontrado: '{$this->path}'. Confira PATH_MODEL no .env — "
                . 'caminhos relativos são resolvidos a partir da raiz do projeto, não do diretório atual.',
            );
        }

        $relativePaths = $this->phpFiles();
        $classes = [];

        foreach ($relativePaths as $relativePath) {
            $class = $this->classFor($relativePath);

            if (!class_exists($class)) {
                continue;
            }

            // Um arquivo em PATH_MODEL que não descreve tabela não é erro: pode ser uma
            // classe base, um enum, um trait. Só entra no schema quem tem table().
            if (!method_exists($class, 'table')) {
                continue;
            }

            $reflection = new \ReflectionClass($class);

            if ($reflection->isAbstract() || $reflection->isInterface()) {
                continue;
            }

            $classes[] = $class;
        }

        return $classes;
    }

    /**
     * Os `.php` sob `path`, em caminho relativo e ordem estável.
     *
     * A ordem não muda o resultado — `SchemaDefinition` reordena por nome de tabela e
     * o grafo de dependência é que define a ordem de criação —, mas ser
     * determinística é o que torna reproduzível a mensagem de erro de um schema
     * inválido.
     *
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $paths = [];
        $queue = [''];

        // Fila explícita em vez de RecursiveIteratorIterator porque a poda precisa
        // acontecer ANTES de descer: um `Generated/` grande custa uma listagem de
        // diretório aqui e uma árvore inteira lá.
        while ($queue !== []) {
            $relativeDir = array_shift($queue);
            $absoluteDir = $relativeDir === ''
                ? $this->path
                : $this->path . DIRECTORY_SEPARATOR . $relativeDir;

            $entries = scandir($absoluteDir);

            if ($entries === false) {
                throw new SchemaException("Não foi possível listar '{$absoluteDir}'.");
            }

            foreach ($entries as $entry) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }

                $relative = $relativeDir === '' ? $entry : $relativeDir . DIRECTORY_SEPARATOR . $entry;

                if (is_dir($absoluteDir . DIRECTORY_SEPARATOR . $entry)) {
                    if (!in_array(strtolower($entry), $this->skip, true)) {
                        $queue[] = $relative;
                    }

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

    /**
     * Caminho relativo vira class-string pela regra do PSR-4: cada diretório é um
     * segmento de namespace.
     */
    private function classFor(string $relativePath): string
    {
        $withoutExtension = substr($relativePath, 0, -strlen('.php'));
        $segments = preg_split('#[/\\\\]#', $withoutExtension) ?: [$withoutExtension];

        return $this->namespace . '\\' . implode('\\', $segments);
    }

    /**
     * Valida o schema para um dialeto e lança com TODOS os problemas de uma vez.
     *
     * Reunir os erros em vez de parar no primeiro é o oposto do que o builder antigo
     * fazia — ele lançava dentro do construtor de `Column`, um por execução, e cada
     * correção revelava o seguinte.
     */
    public function loadValidated(string $dialect): SchemaDefinition
    {
        $schema = $this->load();
        $errors = (new SchemaValidator())->validate($schema, $dialect);

        if ($errors !== []) {
            throw new SchemaException(
                "O schema declarado nos models tem " . count($errors) . " problema(s) para {$dialect}:\n  - "
                . implode("\n  - ", $errors),
            );
        }

        return $schema;
    }
}
