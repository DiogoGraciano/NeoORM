<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Chave primária.
 *
 * O nome existe para que o DDL gerado seja explícito, mas o differ compara PKs
 * apenas pela lista de colunas: deixado por conta do banco, o PostgreSQL nomeia
 * `{tabela}_pkey` e o MySQL chama de `PRIMARY`, então comparar por nome faria
 * toda tabela introspectada parecer divergente. Renomear uma PK também não é
 * uma operação com significado prático.
 */
final readonly class PrimaryKeyDefinition implements NamedDefinition
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
            throw new SchemaException('Chave primária precisa de ao menos uma coluna.');
        }

        $this->name = IdentifierValidator::normalize($name, 'Nome de chave primária');
        $this->columns = array_values(array_map(
            static fn (string $column): string => IdentifierValidator::normalize($column, 'Coluna de chave primária'),
            $columns,
        ));
    }

    public function isComposite(): bool
    {
        return count($this->columns) > 1;
    }

    /**
     * Igualdade por colunas, deliberadamente ignorando o nome. Ver o comentário
     * da classe.
     */
    public function equals(self $other): bool
    {
        return $this->columns === $other->columns;
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
