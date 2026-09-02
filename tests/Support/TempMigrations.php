<?php

declare(strict_types=1);

namespace Tests\Support;

use Diogodg\Neoorm\Migrations\Snapshot\Journal;
use Diogodg\Neoorm\Migrations\Snapshot\MigrationDirectory;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Migrations\Snapshot\SnapshotSerializer;

/**
 * Um diretório de migrações real, num temporário, montado à mão.
 *
 * Deliberadamente um diretório de VERDADE e não um sistema de arquivos falso. O que
 * `MigrationDirectory` faz é quase todo sobre o sistema de arquivos — escrita atômica por
 * `tempnam` e `rename`, recusa de sobrescrever, criação de `meta/` — e um dublê em memória
 * afirmaria que o dublê funciona. Disco não é banco: não abre socket, e a suíte unitária
 * continua sem servidor.
 *
 * Montar os arquivos à mão, em vez de gerá-los pelo pipeline, é o que permite escrever os
 * estados QUEBRADOS: arquivo editado depois de aplicado, `.sql` que desapareceu, journal com
 * buraco de índice, `.sql` órfão de um merge mal resolvido.
 */
final class TempMigrations
{
    private readonly string $root;

    public function __construct(public readonly string $dialect = 'pgsql')
    {
        $base = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'neoorm-migrations-' . bin2hex(random_bytes(6));

        if (!mkdir($base, 0o775, true) && !is_dir($base)) {
            throw new \RuntimeException("Não foi possível criar '{$base}'.");
        }

        $this->root = $base;
    }

    public function root(): string
    {
        return $this->root;
    }

    public function directory(): MigrationDirectory
    {
        return new MigrationDirectory($this->root, $this->dialect);
    }

    /**
     * Escreve `.sql` + snapshot + entrada no journal, do jeito certo.
     */
    public function add(int $idx, string $tag, string $sql): self
    {
        $directory = $this->directory();
        $directory->ensure();

        file_put_contents($directory->sqlPath($idx, $tag), $sql);
        file_put_contents(
            $directory->snapshotPath($idx),
            (new SnapshotSerializer())->toJson($this->snapshotFor($idx)),
        );

        $journal = $directory->journal()->add($tag, 1_700_000_000 + $idx);
        file_put_contents($directory->journalPath(), $journal->encode());

        return $this;
    }

    /**
     * O snapshot de cada índice é a baseline vazia avançada N vezes.
     *
     * Os testes do runner não afirmam nada sobre o CONTEÚDO do snapshot — isso é assunto do
     * differ e do serializador, e está testado lá. Aqui ele só precisa existir com o índice
     * certo, porque é o que `MigrationDirectory` verifica ao escrever.
     */
    private function snapshotFor(int $idx): Snapshot
    {
        $snapshot = Snapshot::baseline($this->dialect);

        // A baseline JÁ é o índice 0, então a migração N precisa de N avanços, não N+1: o
        // snapshot de índice N descreve o estado DEPOIS da migração N.
        for ($i = 0; $i < $idx; $i++) {
            $snapshot = $snapshot->next($snapshot->schema);
        }

        return $snapshot;
    }

    /**
     * Reescreve um `.sql` já commitado — o cenário da migração adulterada.
     */
    public function tamper(int $idx, string $tag, string $sql): self
    {
        file_put_contents($this->directory()->sqlPath($idx, $tag), $sql);

        return $this;
    }

    /**
     * Apaga o `.sql` mas mantém a entrada no journal.
     */
    public function removeSql(int $idx, string $tag): self
    {
        unlink($this->directory()->sqlPath($idx, $tag));

        return $this;
    }

    /**
     * Escreve um `.sql` que o journal não conhece — merge mal resolvido.
     */
    public function addOrphanSql(string $fileName, string $sql): self
    {
        $this->directory()->ensure();
        file_put_contents($this->directory()->path() . DIRECTORY_SEPARATOR . $fileName, $sql);

        return $this;
    }

    public function writeJournal(Journal $journal): self
    {
        $this->directory()->ensure();
        file_put_contents($this->directory()->journalPath(), $journal->encode());

        return $this;
    }

    public function readSql(int $idx, string $tag): string
    {
        return (string) file_get_contents($this->directory()->sqlPath($idx, $tag));
    }

    public function cleanup(): void
    {
        $this->removeRecursively($this->root);
    }

    private function removeRecursively(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);

            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $this->removeRecursively($path . DIRECTORY_SEPARATOR . $entry);
        }

        @rmdir($path);
    }
}
