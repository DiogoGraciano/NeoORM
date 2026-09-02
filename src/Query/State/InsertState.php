<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\State;

use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Um INSERT, possivelmente de várias linhas.
 *
 * A lista de colunas é única para todas as linhas — é o que o `INSERT … VALUES (…), (…)`
 * exige. Quem monta o estado a partir de payloads com conjuntos de colunas diferentes
 * precisa resolver isso antes: ou unificando as colunas, ou quebrando em vários INSERTs.
 * Deixar a decisão aqui produziria linhas com valores deslocados de coluna.
 */
final readonly class InsertState
{
    /** @var list<string> */
    public array $columns;

    /** @var list<string>|null */
    public ?array $returning;

    /**
     * @param list<string> $columns
     * @param list<list<Expression>> $rows
     * @param list<string>|null $returning colunas a devolver; null desliga o RETURNING
     */
    public function __construct(
        public TableRef $into,
        array $columns,
        public array $rows = [],
        ?array $returning = null,
    ) {
        if ($columns === []) {
            throw new \InvalidArgumentException(
                "INSERT em {$into->name} sem nenhuma coluna. Um INSERT que não escreve nada "
                . 'quase sempre é um payload que chegou vazio.',
            );
        }

        $this->columns = array_map(
            static fn (string $column): string => IdentifierValidator::normalize($column, 'Nome de coluna'),
            array_values($columns),
        );

        $expected = count($this->columns);

        foreach ($rows as $index => $row) {
            if (count($row) !== $expected) {
                throw new \InvalidArgumentException(
                    "A linha {$index} do INSERT em {$into->name} tem " . count($row)
                    . " valor(es) para {$expected} coluna(s). Contar aqui evita gravar valor "
                    . 'na coluna errada, que o banco aceitaria sem reclamar quando os tipos batem.',
                );
            }
        }

        $this->returning = $returning === null
            ? null
            : array_map(
                static fn (string $column): string => IdentifierValidator::normalize($column, 'Nome de coluna'),
                array_values($returning),
            );
    }

    /**
     * @param list<Expression> $row
     */
    public function withRow(array $row): self
    {
        return new self($this->into, $this->columns, [...$this->rows, $row], $this->returning);
    }

    /**
     * @param list<string>|null $columns
     */
    public function withReturning(?array $columns): self
    {
        return new self($this->into, $this->columns, $this->rows, $columns);
    }
}
