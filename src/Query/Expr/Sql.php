<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * Um fragmento de SQL escrito à mão. A via de escape, declarada como tal.
 *
 * **O fragmento não é sanitizado.** Nunca o monte a partir de entrada do usuário.
 *
 * A diferença para o `Raw` que isto substitui é que aqui o fragmento aceita binds:
 *
 * ```php
 * sql('EXTRACT(YEAR FROM ?) = ?', $u->created_at, 2026)
 * ```
 *
 * O `Raw` antigo não tinha como parametrizar nada, então quem precisava de uma
 * expressão com valor tinha só duas saídas: interpolar o valor no fragmento — que é
 * a injeção que a 2.0 veio fechar — ou desistir da expressão. Aceitar binds é o que
 * torna a via de escape utilizável sem ser perigosa: o texto é responsabilidade de
 * quem escreve, os valores continuam sendo parâmetros.
 *
 * O marcador é `?`, posicional, substituído em ordem pelos placeholders nomeados que
 * o compilador aloca. Um `?` a mais ou a menos que os binds informados é erro na
 * construção, não no banco.
 */
final readonly class Sql implements Expression
{
    /** @var list<Expression> */
    public array $binds;

    public function __construct(public string $fragment, mixed ...$binds)
    {
        $this->binds = Value::wrapAll($binds);

        $markers = substr_count($fragment, '?');

        if ($markers !== count($this->binds)) {
            throw new \InvalidArgumentException(
                "O fragmento tem {$markers} marcador(es) '?' e recebeu " . count($this->binds)
                . " bind(s): {$fragment}. Contar isto aqui troca um erro de sintaxe do banco, "
                . 'sem contexto, por um erro que aponta o fragmento.',
            );
        }
    }
}
