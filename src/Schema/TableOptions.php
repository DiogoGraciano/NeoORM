<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

/**
 * Opções de tabela específicas do MySQL.
 *
 * `null` significa "padrão do servidor", e essa distinção é o que mata o diff
 * fantasma de collation. O builder antigo tinha `utf8mb4_general_ci` como
 * default embutido; num MySQL 8, cujo padrão é `utf8mb4_0900_ai_ci`, o schema
 * declarado e o banco discordavam permanentemente, e cada migração emitia um
 * ALTER COLLATE que nunca convergia.
 *
 * Com `null`, o campo simplesmente não entra na comparação: só se compara o que
 * foi explicitamente pedido.
 */
final readonly class TableOptions
{
    public function __construct(
        public ?string $engine = null,
        public ?string $collation = null,
    ) {
    }

    public static function none(): self
    {
        return new self();
    }

    public function isEmpty(): bool
    {
        return $this->engine === null && $this->collation === null;
    }

    /**
     * Igualdade que ignora o que não foi declarado dos dois lados.
     */
    public function equals(self $other): bool
    {
        return $this->engine === $other->engine && $this->collation === $other->collation;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return ['collation' => $this->collation, 'engine' => $this->engine];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self(
            engine: isset($data['engine']) ? (string) $data['engine'] : null,
            collation: isset($data['collation']) ? (string) $data['collation'] : null,
        );
    }
}
