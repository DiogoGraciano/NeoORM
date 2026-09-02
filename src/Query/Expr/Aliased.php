<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * `<expressão> AS <alias>`.
 *
 * O alias passa pelo mesmo validador dos demais identificadores. No sistema antigo o
 * atalho `[coluna, alias]` de `selectColumns()` não era validado, e
 * `['name', 'x FROM users; --']` entrava no SQL — foi um dos vetores corrigidos na 2.0.
 */
final readonly class Aliased implements Expression
{
    public string $alias;

    public function __construct(public Expression $expression, string $alias)
    {
        $this->alias = IdentifierValidator::normalize($alias, 'Alias de coluna');
    }
}
