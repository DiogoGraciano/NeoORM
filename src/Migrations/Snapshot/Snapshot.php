<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Snapshot;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Schema\SchemaDefinition;
use Diogodg\Neoorm\Schema\TableDefinition;

/**
 * O schema num instante, versionado no repositório.
 *
 * É a única entrada do differ. Não o banco, não os models: o arquivo JSON
 * commitado. Essa escolha é o que separa este sistema do anterior, que comparava
 * o model contra cinco tabelas `_schema_*` dentro do próprio banco — tabelas que
 * registravam o que o código *afirmou* ter feito, nunca o que o banco tinha.
 * Quando as duas coisas divergiam, nada percebia.
 *
 * Um snapshot por dialeto, em diretórios separados. Não é redundância: `engine` e
 * `collation` só existem no MySQL, e o `.sql` gerado é inerentemente específico,
 * então dois bancos introspectados nunca produziriam o mesmo arquivo.
 */
final readonly class Snapshot
{
    /**
     * Versão do FORMATO do arquivo, não do schema do usuário.
     *
     * Sobe quando a forma do JSON muda de um jeito que `SnapshotSerializer::upcast()`
     * precise tratar. Existe desde a primeira versão porque acrescentá-la depois
     * exigiria adivinhar a versão de arquivos que não a declaram.
     */
    public const FORMAT_VERSION = 1;

    public string $dialect;

    public string $id;

    public ?string $prevId;

    public function __construct(
        string $dialect,
        string $id,
        ?string $prevId,
        public SchemaDefinition $schema,
        public SnapshotMeta $meta = new SnapshotMeta(),
        public int $version = self::FORMAT_VERSION,
        /**
         * Procedência, e NÃO conteúdo: não é serializada, porque um arquivo de snapshot
         * é sempre declarado — quem o lê de volta o lê como declarado. O campo importa
         * apenas para o snapshot que acabou de sair de um banco.
         */
        public SnapshotSource $source = SnapshotSource::Declared,
    ) {
        $this->dialect = self::normalizeDialect($dialect);
        $this->id = self::normalizeId($id);
        $this->prevId = $prevId === null ? null : self::normalizeId($prevId);

        if ($this->prevId !== null && $this->prevId >= $this->id) {
            throw new MigrationException(
                "Snapshot {$this->id} declara prevId {$this->prevId}, que não é anterior. "
                . 'A cadeia de snapshots é estritamente crescente.',
            );
        }
    }

    /**
     * O estado "nada existe ainda".
     *
     * A primeira migração é `diff(baseline, snapshot0)`, e não um caminho especial
     * de código. Um caso especial para "criar do zero" é um caminho que só roda uma
     * vez por projeto e por isso nunca é testado de verdade — que é exatamente o
     * caminho onde o sistema antigo escondia a maior parte dos seus bugs.
     */
    public static function baseline(string $dialect): self
    {
        return new self($dialect, '0000', null, SchemaDefinition::empty());
    }

    public static function initial(string $dialect, SchemaDefinition $schema): self
    {
        return new self($dialect, '0000', null, $schema);
    }

    /**
     * O estado lido de um banco.
     *
     * Marcado como introspectado para o differ saber que expressões de CHECK deste lado
     * podem ter sido reescritas pelo servidor.
     */
    public static function introspected(string $dialect, SchemaDefinition $schema): self
    {
        return new self($dialect, '0000', null, $schema, source: SnapshotSource::Introspected);
    }

    public function withSchema(SchemaDefinition $schema): self
    {
        return new self(
            $this->dialect,
            $this->id,
            $this->prevId,
            $schema,
            $this->meta,
            $this->version,
            $this->source,
        );
    }

    public function withMeta(SnapshotMeta $meta): self
    {
        return new self(
            $this->dialect,
            $this->id,
            $this->prevId,
            $this->schema,
            $meta,
            $this->version,
            $this->source,
        );
    }

    /**
     * O snapshot seguinte da cadeia, com este como antecessor.
     */
    public function next(SchemaDefinition $schema, SnapshotMeta $meta = new SnapshotMeta()): self
    {
        return new self($this->dialect, self::formatId($this->index() + 1), $this->id, $schema, $meta);
    }

    public function index(): int
    {
        return (int) $this->id;
    }

    /**
     * @return array<string,TableDefinition>
     */
    public function tables(): array
    {
        return $this->schema->tables;
    }

    public function table(string $name): ?TableDefinition
    {
        return $this->schema->table($name);
    }

    public function isIntrospected(): bool
    {
        return $this->source === SnapshotSource::Introspected;
    }

    public function isEmpty(): bool
    {
        return $this->schema->isEmpty();
    }

    /**
     * Igualdade de conteúdo de schema, ignorando posição na cadeia.
     *
     * `id` e `prevId` de fora de propósito: a pergunta que se faz é "este schema é o
     * mesmo?", não "este arquivo é o mesmo?". `meta` também fica de fora — registra
     * a decisão que produziu a transição, não o estado resultante.
     */
    public function hasSameSchema(self $other): bool
    {
        return $this->dialect === $other->dialect
            && $this->schema->toArray() === $other->schema->toArray();
    }

    public static function formatId(int $index): string
    {
        if ($index < 0) {
            throw new MigrationException("Índice de snapshot negativo: {$index}.");
        }

        return str_pad((string) $index, 4, '0', STR_PAD_LEFT);
    }

    /**
     * Quatro dígitos, e apenas dígitos.
     *
     * O id ordena lexicograficamente na mesma sequência em que ordena
     * numericamente, o que faz `sort()` de nomes de arquivo e `ORDER BY tag` no
     * banco concordarem sem ninguém precisar converter nada.
     */
    private static function normalizeId(string $id): string
    {
        $trimmed = trim($id);

        if (preg_match('/^\d{4,}$/', $trimmed) !== 1) {
            throw new MigrationException(
                "Id de snapshot inválido: '{$id}'. Use quatro dígitos ou mais, só dígitos (0000, 0001, ...).",
            );
        }

        return $trimmed;
    }

    private static function normalizeDialect(string $dialect): string
    {
        $normalized = strtolower(trim($dialect));

        if (!in_array($normalized, ['mysql', 'pgsql'], true)) {
            throw new MigrationException(
                "Dialeto de snapshot inválido: '{$dialect}'. Use 'mysql' ou 'pgsql' — a grafia canônica "
                . 'vem de DialectFactory.',
            );
        }

        return $normalized;
    }
}
