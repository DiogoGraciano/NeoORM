<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Snapshot;

use Countable;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;

/**
 * O índice das migrações de um dialeto, commitado com o projeto.
 *
 * É a AUTORIDADE sobre quais migrações existem e em que ordem. Ler o diretório e ordenar
 * nomes de arquivo pareceria equivalente e não é: o journal também registra quando cada
 * migração nasceu, sobrevive a um arquivo renomeado, e — o que mais importa — deixa
 * explícito no diff de um pull request que uma migração foi ACRESCENTADA. Um arquivo
 * `.sql` novo sem linha no journal é sinal de merge mal resolvido, e o runner sabe
 * reclamar disso.
 *
 * Imutável: `add()` devolve outro journal. Assim não existe o estado intermediário em que
 * o journal já foi alterado em memória mas os arquivos ainda não foram escritos.
 */
final readonly class Journal implements Countable
{
    public const FORMAT_VERSION = 1;

    /** @var list<JournalEntry> */
    public array $entries;

    /**
     * @param list<JournalEntry> $entries
     */
    public function __construct(
        public string $dialect,
        array $entries = [],
        public int $version = self::FORMAT_VERSION,
    ) {
        if (trim($dialect) === '') {
            throw new MigrationException('Journal sem dialeto.');
        }

        // `usort` reindexa, então o resultado já é lista.
        usort($entries, static fn (JournalEntry $a, JournalEntry $b): int => $a->idx <=> $b->idx);

        $this->assertWellFormed($entries);

        $this->entries = $entries;
    }

    public static function empty(string $dialect): self
    {
        return new self($dialect);
    }

    /**
     * Índices contíguos a partir de zero e tags únicas.
     *
     * Buraco de índice não é detalhe estético: significa que uma migração foi apagada do
     * journal sem ser removida do banco de quem já a aplicou, e a partir daí a ordem que
     * um projeto aplicou e a que outro aplicará divergem. Melhor recusar de saída, com o
     * número que falta na mensagem, do que descobrir em produção.
     *
     * @param list<JournalEntry> $entries
     */
    private function assertWellFormed(array $entries): void
    {
        $seenTags = [];

        foreach ($entries as $position => $entry) {
            if ($entry->idx !== $position) {
                throw new MigrationException(
                    "Journal com índices não contíguos: esperava {$position} e achei {$entry->idx} "
                    . "(tag '{$entry->tag}'). Isso normalmente é merge mal resolvido — dois branches "
                    . 'geraram a mesma migração e um dos dois precisa ser renumerado.',
                );
            }

            if (isset($seenTags[$entry->tag])) {
                throw new MigrationException(
                    "Journal com tag repetida: '{$entry->tag}' aparece nos índices "
                    . "{$seenTags[$entry->tag]} e {$entry->idx}.",
                );
            }

            $seenTags[$entry->tag] = $entry->idx;
        }
    }

    public function add(string $tag, int $createdAt): self
    {
        if ($this->hasTag($tag)) {
            throw new MigrationException("Já existe uma migração com a tag '{$tag}'.");
        }

        return new self(
            $this->dialect,
            [...$this->entries, new JournalEntry($this->nextIndex(), $tag, $createdAt)],
            $this->version,
        );
    }

    public function nextIndex(): int
    {
        return count($this->entries);
    }

    public function isEmpty(): bool
    {
        return $this->entries === [];
    }

    public function count(): int
    {
        return count($this->entries);
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return array_map(static fn (JournalEntry $e): string => $e->tag, $this->entries);
    }

    public function hasTag(string $tag): bool
    {
        return in_array($tag, $this->tags(), true);
    }

    public function entry(int $idx): ?JournalEntry
    {
        return $this->entries[$idx] ?? null;
    }

    public function byTag(string $tag): ?JournalEntry
    {
        foreach ($this->entries as $entry) {
            if ($entry->tag === $tag) {
                return $entry;
            }
        }

        return null;
    }

    public function last(): ?JournalEntry
    {
        return $this->entries === [] ? null : $this->entries[count($this->entries) - 1];
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'dialect' => $this->dialect,
            'entries' => array_map(static fn (JournalEntry $e): array => $e->toArray(), $this->entries),
            'version' => $this->version,
        ];
    }

    public function encode(): string
    {
        return json_encode($this->toArray(), SnapshotSerializer::ENCODE_FLAGS) . "\n";
    }

    public static function decode(string $json, string $expectedDialect): self
    {
        if (trim($json) === '') {
            return self::empty($expectedDialect);
        }

        try {
            /** @var mixed $data */
            $data = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new MigrationException('journal.json não é JSON válido: ' . $e->getMessage(), previous: $e);
        }

        if (!is_array($data)) {
            throw new MigrationException('journal.json precisa ser um objeto JSON.');
        }

        $version = (int) ($data['version'] ?? self::FORMAT_VERSION);

        if ($version > self::FORMAT_VERSION) {
            throw new MigrationException(
                "journal.json está no formato {$version} e esta versão da biblioteca entende até a "
                . self::FORMAT_VERSION . '. Atualize a NeoORM.',
            );
        }

        $dialect = (string) ($data['dialect'] ?? $expectedDialect);

        // O dialeto vem do diretório E do arquivo, e os dois têm que concordar. Aplicar
        // SQL de MySQL num PostgreSQL falharia — mas provavelmente não no primeiro
        // statement, e a essa altura o banco já estaria pela metade.
        if ($dialect !== $expectedDialect) {
            throw new MigrationException(
                "journal.json diz que é do dialeto '{$dialect}', mas está no diretório de "
                . "'{$expectedDialect}'.",
            );
        }

        $entries = [];

        foreach ((array) ($data['entries'] ?? []) as $entry) {
            if (!is_array($entry)) {
                throw new MigrationException('Entrada do journal precisa ser um objeto JSON.');
            }

            /** @var array<string,mixed> $entry */
            $entries[] = JournalEntry::fromArray($entry);
        }

        return new self($dialect, $entries, $version);
    }
}
