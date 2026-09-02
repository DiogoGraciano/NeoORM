<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Índice secundário.
 *
 * Uma coluna basta. O builder antigo exigia `count($columns) >= 2` e lançava
 * exceção para índice de coluna única — que é o índice mais comum que existe.
 */
final readonly class IndexDefinition implements NamedDefinition
{
    public string $name;

    /** @var list<string> */
    public array $columns;

    /**
     * @param list<string> $columns
     */
    public function __construct(
        string $name,
        array $columns,
        public bool $unique = false,
        public ?string $method = null,
    ) {
        if ($columns === []) {
            throw new SchemaException("Índice '{$name}' precisa de ao menos uma coluna.");
        }

        $this->name = IdentifierValidator::normalize($name, 'Nome de índice');
        $this->columns = array_values(array_map(
            static fn (string $column): string => IdentifierValidator::normalize($column, 'Coluna de índice'),
            $columns,
        ));
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->columns === $other->columns
            && $this->unique === $other->unique
            && $this->method === $other->method;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = [
            'columns' => $this->columns,
            'name' => $this->name,
            'unique' => $this->unique,
        ];

        if ($this->method !== null) {
            $data['method'] = $this->method;
        }

        ksort($data, SORT_STRING);

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $columns */
        $columns = array_values((array) ($data['columns'] ?? []));

        return new self(
            name: (string) $data['name'],
            columns: $columns,
            unique: (bool) ($data['unique'] ?? false),
            method: isset($data['method']) ? (string) $data['method'] : null,
        );
    }
}
