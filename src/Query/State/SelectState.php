<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\State;

use Diogodg\Neoorm\Query\Expr\Expression;
use Diogodg\Neoorm\Query\Expr\Logical;
use Diogodg\Neoorm\Query\Expr\LogicalConnective;
use Diogodg\Neoorm\Query\Expr\OrderTerm;

/**
 * Um SELECT descrito como dado, sem executor e sem PDO.
 *
 * Separar o estado do builder é o que torna o compilador testável de verdade: um teste
 * monta um `SelectState` à mão e compila, sem `Database`, sem conexão e sem passar pela
 * API fluente. E é o que permite compilar o MESMO estado contra os dois dialetos e
 * comparar o par — a forma mais direta de ver uma divergência de dialeto aparecer.
 *
 * Imutável, e cada `with*()` devolve uma instância nova. O builder antigo acumulava
 * filtros em `$this` e precisava de `clean()` depois de cada execução; a consequência
 * era que uma exceção no meio deixava a query seguinte com os filtros da anterior.
 * Aqui não existe estado para limpar.
 */
final readonly class SelectState
{
    /**
     * @param list<Expression> $columns lista vazia significa todas as colunas da tabela base
     * @param list<JoinClause> $joins
     * @param list<Expression> $groupBy
     * @param list<OrderTerm> $orderBy
     */
    public function __construct(
        public ?TableRef $from = null,
        public array $columns = [],
        public array $joins = [],
        public ?Expression $where = null,
        public array $groupBy = [],
        public ?Expression $having = null,
        public array $orderBy = [],
        public ?int $limit = null,
        public ?int $offset = null,
        public bool $distinct = false,
    ) {
    }

    public function withFrom(TableRef $from): self
    {
        return $this->with(from: $from);
    }

    /**
     * @param list<Expression> $columns
     */
    public function withColumns(array $columns): self
    {
        return $this->with(columns: $columns);
    }

    public function withJoin(JoinClause $join): self
    {
        return $this->with(joins: [...$this->joins, $join]);
    }

    /**
     * Condições acumulam com `AND`.
     *
     * Chamar `->where()` duas vezes restringe, não substitui — é o que a leitura
     * `->where(a)->where(b)` sugere, e o contrário seria perder um filtro em silêncio.
     * Quem quer `OR` escreve `Op::or()` explicitamente.
     */
    public function withWhere(Expression $condition): self
    {
        return $this->with(where: self::conjoin($this->where, $condition));
    }

    /**
     * @param list<Expression> $columns
     */
    public function withGroupBy(array $columns): self
    {
        return $this->with(groupBy: [...$this->groupBy, ...$columns]);
    }

    public function withHaving(Expression $condition): self
    {
        return $this->with(having: self::conjoin($this->having, $condition));
    }

    /**
     * @param list<OrderTerm> $terms
     */
    public function withOrderBy(array $terms): self
    {
        return $this->with(orderBy: [...$this->orderBy, ...$terms]);
    }

    public function withLimit(?int $limit): self
    {
        return $this->with(limit: $limit);
    }

    public function withOffset(?int $offset): self
    {
        return $this->with(offset: $offset);
    }

    public function withDistinct(bool $distinct = true): self
    {
        return $this->with(distinct: $distinct);
    }

    /**
     * Uma cópia com a paginação removida.
     *
     * É o que `count()` usa: contar as linhas de uma consulta limitada a 10 devolveria
     * no máximo 10, e quem pagina quer o total.
     */
    public function withoutPagination(): self
    {
        return $this->with(limit: null, offset: null);
    }

    /**
     * Uma cópia sem ordenação.
     *
     * Também de `count()`: ordenar não muda quantas linhas existem, e dentro da
     * subconsulta que a contagem monta há banco que sequer respeita a cláusula.
     */
    public function withoutOrder(): self
    {
        return $this->with(orderBy: []);
    }

    private static function conjoin(?Expression $existing, Expression $addition): Expression
    {
        return $existing === null
            ? $addition
            : new Logical(LogicalConnective::And, [$existing, $addition]);
    }

    /**
     * @param list<Expression>|null $columns
     * @param list<JoinClause>|null $joins
     * @param list<Expression>|null $groupBy
     * @param list<OrderTerm>|null $orderBy
     */
    private function with(
        ?TableRef $from = null,
        ?array $columns = null,
        ?array $joins = null,
        ?Expression $where = null,
        ?array $groupBy = null,
        ?Expression $having = null,
        ?array $orderBy = null,
        int|false|null $limit = false,
        int|false|null $offset = false,
        ?bool $distinct = null,
    ): self {
        // `false` como sentinela de "não informado" nos dois inteiros: `null` ali é um
        // valor legítimo — significa "sem limite" — e usá-lo como ausência tornaria
        // `withLimit(null)` um no-op silencioso.
        return new self(
            from: $from ?? $this->from,
            columns: $columns ?? $this->columns,
            joins: $joins ?? $this->joins,
            where: $where ?? $this->where,
            groupBy: $groupBy ?? $this->groupBy,
            having: $having ?? $this->having,
            orderBy: $orderBy ?? $this->orderBy,
            limit: $limit === false ? $this->limit : $limit,
            offset: $offset === false ? $this->offset : $offset,
            distinct: $distinct ?? $this->distinct,
        );
    }
}
