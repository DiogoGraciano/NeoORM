<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Snapshot;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;

/**
 * Uma linha do journal: a migração número N chama-se T e nasceu em D.
 *
 * O journal é a lista ORDENADA das migrações do repositório, e é ele — não o `glob()` do
 * diretório — que define a ordem de aplicação. A diferença importa: ordem de arquivo é
 * ordem de string, e `0010` vindo antes de `0009` é um acidente esperando por um projeto
 * com dez migrações.
 */
final readonly class JournalEntry
{
    public function __construct(
        public int $idx,
        public string $tag,
        public int $createdAt,
        public int $version = Snapshot::FORMAT_VERSION,
    ) {
        if ($idx < 0) {
            throw new MigrationException("Índice de migração negativo: {$idx}.");
        }

        if (trim($tag) === '') {
            throw new MigrationException('Migração sem tag no journal.');
        }

        if ($createdAt < 0) {
            throw new MigrationException("Data de criação negativa na migração '{$tag}'.");
        }
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        // Chaves em ordem alfabética, como em todo lugar: o journal é commitado, e um
        // arquivo que muda de ordem entre execuções é um conflito de merge por nada.
        return [
            'createdAt' => $this->createdAt,
            'idx' => $this->idx,
            'tag' => $this->tag,
            'version' => $this->version,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        foreach (['idx', 'tag', 'createdAt'] as $required) {
            if (!array_key_exists($required, $data)) {
                throw new MigrationException("Entrada do journal sem '{$required}'.");
            }
        }

        return new self(
            (int) $data['idx'],
            (string) $data['tag'],
            (int) $data['createdAt'],
            (int) ($data['version'] ?? Snapshot::FORMAT_VERSION),
        );
    }
}
