<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Migrations\Snapshot\SnapshotSerializer;

/**
 * Lê o banco e escreve o que encontrou como snapshot.
 *
 * Só o snapshot no 2.0 — geração de código de model fica para depois. Serve para inspecionar
 * o que o servidor realmente tem, num formato comparável com o que o repositório descreve, o
 * que é o passo zero de diagnosticar drift.
 *
 * Não mexe no journal e não escreve `.sql`: o snapshot sai num arquivo à parte, justamente
 * para não se passar por artefato de migração. Sobrescrever `meta/NNNN_snapshot.json` com o
 * estado introspectado faria o próximo `generate` diffar contra o banco em vez de contra o
 * histórico — e o histórico deixaria de descrever como se chegou ali.
 */
final class Pull
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    /**
     * @param bool $onlyDeclared limita a leitura às tabelas que os models descrevem
     * @return array{snapshot: Snapshot, path: string|null, json: string}
     */
    public function execute(?string $writeTo = null, bool $onlyDeclared = false): array
    {
        $tables = null;

        if ($onlyDeclared) {
            $tables = $this->context->models
                ->loadValidated($this->context->dialect->name())
                ->tableNames();
        }

        $snapshot = $this->context->introspector()->introspect($tables);
        $json = (new SnapshotSerializer())->toJson($snapshot);

        $this->context->output->write(sprintf(
            'Lidas %d tabela(s) de %s.',
            count($snapshot->tables()),
            $this->context->database->database,
        ));

        if ($writeTo === null) {
            return ['snapshot' => $snapshot, 'path' => null, 'json' => $json];
        }

        $directory = dirname($writeTo);

        if (!is_dir($directory) && !@mkdir($directory, 0o775, true) && !is_dir($directory)) {
            throw new \Diogodg\Neoorm\Migrations\Exception\MigrationException(
                "Não foi possível criar '{$directory}'.",
            );
        }

        if (@file_put_contents($writeTo, $json) === false) {
            throw new \Diogodg\Neoorm\Migrations\Exception\MigrationException(
                "Não foi possível escrever '{$writeTo}'.",
            );
        }

        $this->context->output->success("Snapshot do banco escrito em {$writeTo}.");

        return ['snapshot' => $snapshot, 'path' => $writeTo, 'json' => $json];
    }
}
