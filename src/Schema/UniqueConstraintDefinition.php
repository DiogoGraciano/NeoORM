<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Restrição de unicidade.
 *
 * No IR, unicidade mora em exatamente um lugar. No sistema antigo ela existia
 * em dois — como fragmento `UNIQUE (col)` embutido na coluna e como constraint
 * de tabela — e o gerador de ALTER lia só a segunda forma. Resultado: coluna
 * nova declarada `isUnique()` entrava no banco sem unicidade nenhuma.
 */
final readonly class UniqueConstraintDefinition implements NamedDefinition
{
    public string $name;

    /** @var list<string> */
    public array $columns;

    /**
     * @param list<string> $columns
     */
    public function __construct(string $name, array $columns)
    {
        if ($columns === []) {
            throw new SchemaException("Restrição de unicidade '{$name}' precisa de ao menos uma coluna.");
        }

        $this->name = IdentifierValidator::normalize($name, 'Nome de restrição de unicidade');
        $this->columns = array_values(array_map(
            static fn (string $column): string => IdentifierValidator::normalize($column, 'Coluna de restrição de unicidade'),
            $columns,
        ));
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name && $this->columns === $other->columns;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return ['columns' => $this->columns, 'name' => $this->name];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $columns */
        $columns = array_values((array) ($data['columns'] ?? []));

        return new self((string) $data['name'], $columns);
    }
}
