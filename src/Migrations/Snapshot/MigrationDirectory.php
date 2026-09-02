<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Snapshot;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Runner\SqlFileParser;

/**
 * O diretório de artefatos de um dialeto: os `.sql` numerados, o `journal.json` e os
 * snapshots em `meta/`.
 *
 * ```
 * Migrations/
 *   pgsql/
 *     0000_initial.sql
 *     0001_city_add_ibge.sql
 *     journal.json
 *     meta/0000_snapshot.json
 *          0001_snapshot.json
 * ```
 *
 * Um diretório POR DIALETO, e não um só, porque o `.sql` é inerentemente específico do
 * dialeto e porque engine e collation existem apenas no MySQL: dois snapshots
 * introspectados dos dois bancos não são o mesmo arquivo, nem deveriam ser.
 *
 * Tudo aqui é código-fonte e vai commitado. O snapshot é a única entrada do differ — fora
 * do repositório, dois desenvolvedores geram uma migração `0002` cada um, com conteúdos
 * diferentes e o mesmo número.
 */
final class MigrationDirectory
{
    private const JOURNAL = 'journal.json';

    private const META = 'meta';

    private readonly string $root;

    public function __construct(string $root, public readonly string $dialect)
    {
        if (trim($dialect) === '') {
            throw new MigrationException('MigrationDirectory sem dialeto.');
        }

        $this->root = rtrim($root, '/\\');
    }

    public function path(): string
    {
        return $this->root . DIRECTORY_SEPARATOR . $this->dialect;
    }

    public function metaPath(): string
    {
        return $this->path() . DIRECTORY_SEPARATOR . self::META;
    }

    public function journalPath(): string
    {
        return $this->path() . DIRECTORY_SEPARATOR . self::JOURNAL;
    }

    public function exists(): bool
    {
        return is_dir($this->path());
    }

    public function ensure(): void
    {
        foreach ([$this->path(), $this->metaPath()] as $directory) {
            if (is_dir($directory)) {
                continue;
            }

            if (!@mkdir($directory, 0o775, true) && !is_dir($directory)) {
                throw new MigrationException(
                    "Não foi possível criar '{$directory}'. Confira PATH_MIGRATIONS e as permissões.",
                );
            }
        }
    }

    /**
     * O journal, ou um journal vazio se o diretório ainda não existe.
     *
     * Ausência de journal é o estado normal de um projeto novo, não erro: `generate`
     * funciona num diretório que ainda não existe.
     */
    public function journal(): Journal
    {
        $path = $this->journalPath();

        if (!is_file($path)) {
            return Journal::empty($this->dialect);
        }

        return Journal::decode($this->read($path), $this->dialect);
    }

    public function snapshotPath(int $index): string
    {
        return $this->metaPath() . DIRECTORY_SEPARATOR . Snapshot::formatId($index) . '_snapshot.json';
    }

    public function sqlFileName(int $index, string $tag): string
    {
        return Snapshot::formatId($index) . '_' . $tag . '.sql';
    }

    public function sqlPath(int $index, string $tag): string
    {
        return $this->path() . DIRECTORY_SEPARATOR . $this->sqlFileName($index, $tag);
    }

    public function hasSnapshot(int $index): bool
    {
        return is_file($this->snapshotPath($index));
    }

    public function readSnapshot(int $index): Snapshot
    {
        $path = $this->snapshotPath($index);

        if (!is_file($path)) {
            throw new MigrationException(
                "Snapshot ausente: '{$path}'. Os arquivos de meta/ são código-fonte e precisam estar "
                . 'commitados — sem eles o differ não tem contra o que comparar e a próxima migração '
                . 'sairia como se o banco estivesse vazio.',
            );
        }

        return (new SnapshotSerializer())->fromJson($this->read($path));
    }

    /**
     * O snapshot mais recente, ou a baseline vazia quando não há nenhum.
     *
     * A baseline é o que faz a PRIMEIRA migração ser apenas um diff como qualquer outra,
     * em vez de um caminho de código separado. Um caminho especial para "a primeira" é
     * exatamente onde bugs se escondem, porque é o caminho que roda uma vez por projeto.
     */
    public function latestSnapshot(): Snapshot
    {
        $journal = $this->journal();

        if ($journal->isEmpty()) {
            return Snapshot::baseline($this->dialect);
        }

        return $this->readSnapshot($journal->nextIndex() - 1);
    }

    public function readSql(int $index, string $tag): string
    {
        $path = $this->sqlPath($index, $tag);

        if (!is_file($path)) {
            throw new MigrationException(
                "Arquivo de migração ausente: '{$path}'. Ele está no journal, então alguém já pode "
                . 'tê-lo aplicado; apagá-lo não desfaz nada.',
            );
        }

        return $this->read($path);
    }

    public function hasSql(int $index, string $tag): bool
    {
        return is_file($this->sqlPath($index, $tag));
    }

    /**
     * Os `.sql` presentes no diretório, pelo nome.
     *
     * Serve para o `status` apontar um arquivo que existe mas não está no journal — o
     * sinal de merge mal resolvido.
     *
     * @return list<string>
     */
    public function sqlFiles(): array
    {
        if (!$this->exists()) {
            return [];
        }

        $files = glob($this->path() . DIRECTORY_SEPARATOR . '*.sql') ?: [];
        $names = array_map('basename', $files);

        // `sort` reindexa, então o resultado já é lista.
        sort($names, SORT_STRING);

        return $names;
    }

    /**
     * Escreve os TRÊS arquivos de uma migração, ou nenhum.
     *
     * Atomicidade por escrita em temporário e `rename()` — que é atômico no mesmo sistema
     * de arquivos — mais uma verificação prévia de que nada será sobrescrito. Não é
     * paranoia: os três arquivos são um só fato dividido em três. Um journal que cita uma
     * migração cujo `.sql` não foi escrito trava o `up` de todo mundo; um `.sql` sem
     * snapshot faz a migração seguinte ser gerada contra o schema errado.
     *
     * O que ainda não é atômico é o conjunto dos três renames, e nenhuma API POSIX
     * oferece isso. A mitigação é a ordem: o journal — o único arquivo que o runner
     * consulta para saber o que existe — é o ÚLTIMO. Uma interrupção no meio deixa
     * arquivos órfãos que nenhum comando lê, e o `generate` seguinte os sobrescreve.
     */
    public function write(int $index, string $tag, string $sql, Snapshot $snapshot): void
    {
        if ($snapshot->index() !== $index) {
            throw new MigrationException(
                "O snapshot tem índice {$snapshot->index()} e está sendo escrito como {$index}.",
            );
        }

        $journal = $this->journal();

        if ($journal->nextIndex() !== $index) {
            throw new MigrationException(
                "O journal espera a migração {$journal->nextIndex()}, e esta é a {$index}. "
                . 'Rode generate de novo a partir do estado atual do repositório.',
            );
        }

        $this->ensure();

        $sqlPath = $this->sqlPath($index, $tag);
        $snapshotPath = $this->snapshotPath($index);

        foreach ([$sqlPath, $snapshotPath] as $path) {
            if (file_exists($path)) {
                throw new MigrationException(
                    "'{$path}' já existe, e sobrescrever um arquivo de migração é perder história. "
                    . 'Se ele é lixo de uma execução interrompida, apague-o à mão.',
                );
            }
        }

        $updated = $journal->add($tag, $this->now());

        // Journal por último: é o índice, e enquanto ele não cita a migração, ela não
        // existe para nenhum comando.
        $this->atomicWrite($sqlPath, $sql);
        $this->atomicWrite($snapshotPath, (new SnapshotSerializer())->toJson($snapshot));
        $this->atomicWrite($this->journalPath(), $updated->encode());
    }

    /**
     * Hash canônico do `.sql`, como o runner o registra na tabela de controle.
     */
    public function hashOf(int $index, string $tag): string
    {
        return SqlFileParser::hash($this->readSql($index, $tag));
    }

    /**
     * Letra acentuada para a letra correspondente sem acento.
     *
     * Um mapa explícito, e não `iconv('ASCII//TRANSLIT')`, porque a saída daquele depende
     * da implementação de iconv do sistema: na glibc `ã` sai como `~a` e na musl como `?`.
     * O nome de arquivo é ARTEFATO COMMITADO — dois desenvolvedores em distribuições
     * diferentes gerariam `criacao` e `criac_ao` para o mesmo model, e o segundo a commitar
     * teria um conflito que nada no projeto explica.
     */
    private const TRANSLITERATIONS = [
        'á' => 'a', 'à' => 'a', 'ã' => 'a', 'â' => 'a', 'ä' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'õ' => 'o', 'ô' => 'o', 'ö' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ç' => 'c', 'ñ' => 'n', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', 'æ' => 'ae',
    ];

    /**
     * Transforma um nome livre em algo que pode ser nome de arquivo.
     *
     * Sem acento, sem espaço, sem maiúscula, sem pontuação: o nome entra num caminho e
     * numa coluna `VARCHAR`, e vai ser digitado à mão em `--to <tag>`.
     */
    public static function slug(string $name): string
    {
        // Minúsculas primeiro, para o mapa só precisar das minúsculas — `mb_strtolower`
        // porque `strtolower` não conhece byte de multibyte e deixaria `Á` intacto.
        $slug = mb_strtolower(trim($name), 'UTF-8');

        $slug = strtr($slug, self::TRANSLITERATIONS);

        // O que sobrou de não-ASCII vira separador. Perder a letra muda a palavra, mas
        // para um alfabeto que este mapa não cobre não há transliteração óbvia, e um nome
        // de arquivo com bytes fora do ASCII é problema em outro lugar.
        $slug = (string) preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim($slug, '_');

        // Nome de arquivo tem limite, e o índice de quatro dígitos e o sufixo `.sql`
        // também ocupam espaço.
        if (strlen($slug) > 80) {
            $slug = rtrim(substr($slug, 0, 80), '_');
        }

        return $slug;
    }

    private function now(): int
    {
        return time();
    }

    private function read(string $path): string
    {
        $contents = @file_get_contents($path);

        if ($contents === false) {
            throw new MigrationException("Não foi possível ler '{$path}'.");
        }

        return $contents;
    }

    /**
     * Escreve num temporário no MESMO diretório e renomeia.
     *
     * O mesmo diretório importa: `rename()` só é atômico dentro do mesmo sistema de
     * arquivos, e um temporário em `/tmp` frequentemente está em outro.
     */
    private function atomicWrite(string $path, string $contents): void
    {
        $directory = dirname($path);
        $temporary = @tempnam($directory, '.neoorm-');

        if ($temporary === false) {
            throw new MigrationException("Não foi possível criar arquivo temporário em '{$directory}'.");
        }

        try {
            if (@file_put_contents($temporary, $contents) === false) {
                throw new MigrationException("Não foi possível escrever em '{$temporary}'.");
            }

            // O tempnam cria com 0600, restritivo demais para um arquivo commitado que
            // outras contas do time e o processo do CI vão ler.
            @chmod($temporary, 0o664);

            if (!@rename($temporary, $path)) {
                throw new MigrationException("Não foi possível mover '{$temporary}' para '{$path}'.");
            }
        } catch (MigrationException $e) {
            @unlink($temporary);

            throw $e;
        }
    }
}
