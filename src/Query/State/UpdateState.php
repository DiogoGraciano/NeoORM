<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\State;

use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\Logical;
use Diogodg\Neoorm\Query\Expr\LogicalConnective;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Um UPDATE.
 *
 * `allowFullTableScan` existe para que atualizar a tabela inteira seja possível e
 * **declarado**. O padrão é recusar: `deleteByFilter()` no sistema antigo já exigia
 * filtro, mas o UPDATE nunca exigiu — e um `set()` sem `where()` reescreve todas as
 * linhas sem que nada no código pareça errado.
 */
final readonly class UpdateState
{
    /** @var array<string,Expression> */
    public array $assignments;

    /** @var list<string>|null */
    public ?array $returning;

    /**
     * @param array<string,Expression> $assignments coluna => novo valor
     * @param list<string>|null $returning
     */
    public function __construct(
        public TableRef $table,
        array $assignments = [],
        public ?Expression $where = null,
        public bool $allowFullTableScan = false,
        ?array $returning = null,
    ) {
        $normalized = [];

        foreach ($assignments as $column => $value) {
            $normalized[IdentifierValidator::normalize($column, 'Nome de coluna')] = $value;
        }

        $this->assignments = $normalized;
        $this->returning = $returning === null
            ? null
            : array_map(
                static fn (string $column): string => IdentifierValidator::normalize($column, 'Nome de coluna'),
                array_values($returning),
            );
    }

    /**
     * @param array<string,Expression> $assignments
     */
    public function withAssignments(array $assignments): self
    {
        return new self(
            $this->table,
            [...$this->assignments, ...$assignments],
            $this->where,
            $this->allowFullTableScan,
            $this->returning,
        );
    }

    public function withWhere(Expression $condition): self
    {
        $where = $this->where === null
            ? $condition
            : new Logical(LogicalConnective::And, [$this->where, $condition]);

        return new self($this->table, $this->assignments, $where, $this->allowFullTableScan, $this->returning);
    }

    public function withFullTableScan(bool $allow = true): self
    {
        return new self($this->table, $this->assignments, $this->where, $allow, $this->returning);
    }

    /**
     * @param list<string>|null $columns
     */
    public function withReturning(?array $columns): self
    {
        return new self($this->table, $this->assignments, $this->where, $this->allowFullTableScan, $columns);
    }
}
