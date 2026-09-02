<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\State;

use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\Logical;
use Diogodg\Neoorm\Query\Expr\LogicalConnective;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Um DELETE. Sem WHERE só com `allowFullTableScan`, pelo mesmo motivo do UPDATE.
 */
final readonly class DeleteState
{
    /** @var list<string>|null */
    public ?array $returning;

    /**
     * @param list<string>|null $returning
     */
    public function __construct(
        public TableRef $from,
        public ?Expression $where = null,
        public bool $allowFullTableScan = false,
        ?array $returning = null,
    ) {
        $this->returning = $returning === null
            ? null
            : array_map(
                static fn (string $column): string => IdentifierValidator::normalize($column, 'Nome de coluna'),
                array_values($returning),
            );
    }

    public function withWhere(Expression $condition): self
    {
        $where = $this->where === null
            ? $condition
            : new Logical(LogicalConnective::And, [$this->where, $condition]);

        return new self($this->from, $where, $this->allowFullTableScan, $this->returning);
    }

    public function withFullTableScan(bool $allow = true): self
    {
        return new self($this->from, $this->where, $allow, $this->returning);
    }

    /**
     * @param list<string>|null $columns
     */
    public function withReturning(?array $columns): self
    {
        return new self($this->from, $this->where, $this->allowFullTableScan, $columns);
    }
}
