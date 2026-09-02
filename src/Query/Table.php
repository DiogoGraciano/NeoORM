<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query;

use Diogodg\Neoorm\Query\Expr\ColumnRef;
use Diogodg\Neoorm\Query\State\TableRef;

/**
 * A tabela como o builder a vê: colunas tipadas, e como virar linha.
 *
 * As classes concretas são geradas. É o que dá `Tables::users()->email` com autocomplete
 * e tipo — uma propriedade declarada, não `__get`, porque `__get` não é verificável nem
 * pela IDE nem pelo analisador estático.
 *
 * Covariante: `Table<UsersRow>` tem que ser aceita onde se espera `Table<object>` — é o
 * caso do `selectFields()` e dos joins, que não hidratam e portanto não se importam com
 * qual é a linha.
 *
 * @template-covariant TRow of object
 */
abstract class Table
{
    public function __construct(public readonly ?string $alias = null)
    {
    }

    abstract public function tableName(): string;

    /**
     * @return class-string<TRow>
     */
    abstract public function rowClass(): string;

    /**
     * @param array<string,mixed> $row linha crua do PDO
     * @return TRow
     */
    abstract public function hydrate(array $row): object;

    /**
     * As colunas por nome.
     *
     * É o que permite ao INSERT e ao UPDATE consultarem o tipo de cada coluna para
     * bindar o valor corretamente — a data no formato da coluna, o booleano na forma que
     * o banco espera. Sem isso o bind cairia no palpite pelo tipo PHP, que não conhece
     * nem coluna nem dialeto.
     *
     * @return array<string,ColumnRef<mixed>>
     */
    abstract public function columnRefs(): array;

    /**
     * @return list<string>
     */
    public function columnNames(): array
    {
        return array_keys($this->columnRefs());
    }

    /** @return list<string> */
    abstract public function primaryKeyColumns(): array;

    /**
     * A coluna auto incremento, se houver.
     *
     * É a que `lastInsertId()` preenche, e a única pela qual dá para reler a linha
     * recém-inserida onde não há `RETURNING`. Antes o INSERT chutava a PRIMEIRA coluna
     * declarada, o que acertava por convenção e devolvia a linha errada quando a
     * convenção não valia.
     */
    abstract public function autoIncrementColumn(): ?string;

    /** @return list<string> */
    abstract public function requiredInsertColumns(): array;

    /**
     * @return ColumnRef<mixed>
     */
    public function columnRef(string $name): ColumnRef
    {
        return $this->columnRefs()[$name]
            ?? throw new Exception\QueryException(
                "A tabela '{$this->tableName()}' não tem a coluna '{$name}'.",
            );
    }

    /**
     * A mesma tabela sob outro nome, com as colunas requalificadas.
     *
     * É o que faz self-join funcionar: sem requalificar, as duas pontas gerariam
     * `users.id` e a condição viraria uma tautologia.
     *
     * @return static
     */
    abstract public function as(string $alias): static;

    public function qualifier(): string
    {
        return $this->alias ?? $this->tableName();
    }

    public function toRef(): TableRef
    {
        return new TableRef($this->tableName(), $this->alias);
    }
}
