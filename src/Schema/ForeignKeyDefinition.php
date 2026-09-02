<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;
use Diogodg\Neoorm\Schema\Value\ReferentialAction;

/**
 * Foreign key.
 *
 * Guarda listas de colunas dos dois lados, o que permite FK composta, e é
 * indexada pelo nome da constraint no IR. O mapa antigo era chaveado pela
 * coluna *referenciada* (`$foreningTables[$foreignColumn]`, com `"id"` como
 * padrão), então todas as FKs de uma tabela que apontassem para `id` — o caso
 * normal — se sobrescreviam, e só a última sobrevivia ao rastreamento.
 *
 * onDelete/onUpdate passam por ReferentialAction::canonical(), que dobra
 * RESTRICT em NO ACTION para os dois bancos concordarem.
 */
final readonly class ForeignKeyDefinition implements NamedDefinition
{
    public string $name;

    /** @var list<string> */
    public array $columns;

    public string $referencedTable;

    /** @var list<string> */
    public array $referencedColumns;

    /**
     * @param list<string> $columns
     * @param list<string> $referencedColumns
     */
    public function __construct(
        string $name,
        array $columns,
        string $referencedTable,
        array $referencedColumns,
        public ReferentialAction $onDelete = ReferentialAction::NoAction,
        public ReferentialAction $onUpdate = ReferentialAction::NoAction,
    ) {
        if ($columns === []) {
            throw new SchemaException("Foreign key '{$name}' precisa de ao menos uma coluna.");
        }

        if (count($columns) !== count($referencedColumns)) {
            throw new SchemaException(
                "Foreign key '{$name}': número de colunas locais e referenciadas não bate ("
                . count($columns) . ' vs ' . count($referencedColumns) . ').',
            );
        }

        $this->name = IdentifierValidator::normalize($name, 'Nome de foreign key');
        $this->columns = array_values(array_map(
            static fn (string $column): string => IdentifierValidator::normalize($column, 'Coluna de foreign key'),
            $columns,
        ));
        $this->referencedTable = IdentifierValidator::normalize($referencedTable, 'Tabela referenciada');
        $this->referencedColumns = array_values(array_map(
            static fn (string $column): string => IdentifierValidator::normalize($column, 'Coluna referenciada'),
            $referencedColumns,
        ));
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->columns === $other->columns
            && $this->referencedTable === $other->referencedTable
            && $this->referencedColumns === $other->referencedColumns
            && $this->onDelete === $other->onDelete
            && $this->onUpdate === $other->onUpdate;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return [
            'columns' => $this->columns,
            'name' => $this->name,
            'onDelete' => $this->onDelete->value,
            'onUpdate' => $this->onUpdate->value,
            'referencedColumns' => $this->referencedColumns,
            'referencedTable' => $this->referencedTable,
        ];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var list<string> $columns */
        $columns = array_values((array) ($data['columns'] ?? []));
        /** @var list<string> $referencedColumns */
        $referencedColumns = array_values((array) ($data['referencedColumns'] ?? []));

        return new self(
            name: (string) $data['name'],
            columns: $columns,
            referencedTable: (string) $data['referencedTable'],
            referencedColumns: $referencedColumns,
            onDelete: ReferentialAction::canonical((string) ($data['onDelete'] ?? 'NO ACTION')),
            onUpdate: ReferentialAction::canonical((string) ($data['onUpdate'] ?? 'NO ACTION')),
        );
    }
}
