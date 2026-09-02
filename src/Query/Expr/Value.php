<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

use Diogodg\Neoorm\Schema\Type\TypeSpec;

/**
 * Um valor que vai virar bind.
 *
 * Todo dado do chamador entra na árvore por aqui, e é o que garante que nenhum
 * valor seja interpolado no SQL: o compilador não sabe transformar um `Value` em
 * texto, só em placeholder. A consequência prática é que `eq($u->name, "' OR '1'='1")`
 * não tem como virar injeção — o texto inteiro vira um parâmetro.
 */
final readonly class Value implements Expression
{
    /**
     * @param TypeSpec|null $columnType tipo da coluna com que este valor é comparado
     */
    public function __construct(
        public mixed $value,
        public ?TypeSpec $columnType = null,
    ) {
    }

    /**
     * Aceita um nó pronto ou um valor cru, e devolve sempre um nó.
     *
     * É o que permite escrever `eq($u->age, 18)` e `eq($u->age, $outraColuna)` com
     * a mesma função: o escalar vira `Value`, a expressão passa direto. Sem isto,
     * cada função de comparação precisaria do mesmo `instanceof` no corpo.
     *
     * O segundo parâmetro carrega o tipo da coluna do outro lado da comparação, e é o
     * que faz `eq($u->created_at, new DateTimeImmutable())` gravar no formato certo e
     * `eq($u->active, true)` virar `1` no MySQL e `true` no PostgreSQL. Sem ele o valor
     * seria bindado pelo palpite do tipo PHP, que não conhece nem coluna nem dialeto.
     */
    public static function wrap(mixed $value, ?TypeSpec $columnType = null): Expression
    {
        return $value instanceof Expression ? $value : new self($value, columnType: $columnType);
    }

    /**
     * O tipo da coluna, quando o operando for uma referência de coluna.
     */
    public static function typeOf(Expression $operand): ?TypeSpec
    {
        return $operand instanceof ColumnRef ? $operand->type : null;
    }

    /**
     * @param iterable<mixed> $values
     * @return list<Expression>
     */
    public static function wrapAll(iterable $values, ?TypeSpec $columnType = null): array
    {
        $wrapped = [];

        foreach ($values as $value) {
            $wrapped[] = self::wrap($value, $columnType);
        }

        return $wrapped;
    }

}
