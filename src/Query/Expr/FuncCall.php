<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * Chamada de função: `COUNT(*)`, `SUM(valor)`, `COALESCE(a, b)`.
 *
 * Lista de argumentos vazia significa `COUNT(*)` — a única função do catálogo em que
 * isso faz sentido, e por isso a única que o construtor deixa passar sem argumento.
 */
final readonly class FuncCall implements Expression
{
    /** @var list<Expression> */
    public array $arguments;

    /**
     * @param list<Expression> $arguments
     */
    public function __construct(
        public SqlFunction $function,
        array $arguments = [],
        public bool $distinct = false,
    ) {
        if ($arguments === [] && $this->function !== SqlFunction::Count) {
            throw new \InvalidArgumentException(
                "{$function->value} precisa de ao menos um argumento. Só COUNT tem a forma "
                . 'sem argumento, que é COUNT(*).',
            );
        }

        if ($distinct && !$function->acceptsDistinct()) {
            throw new \InvalidArgumentException(
                "DISTINCT não faz sentido em {$function->value}: `{$function->value}(DISTINCT x)` "
                . 'não é SQL válido.',
            );
        }

        if ($distinct && $arguments === []) {
            throw new \InvalidArgumentException(
                'COUNT(DISTINCT *) não é SQL válido. DISTINCT precisa saber sobre qual coluna.',
            );
        }

        $this->arguments = $arguments;
    }

    /**
     * `COUNT(*)`, a forma sem argumento.
     */
    public function isStar(): bool
    {
        return $this->arguments === [];
    }
}
