<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Compiler;

use PDO;

/**
 * Aloca os placeholders de uma query e guarda os valores.
 *
 * É a única peça mutável do pipeline de compilação — a árvore é imutável, o dialeto
 * não tem estado, e os compiladores são funções. A mutabilidade fica confinada aqui
 * porque a alocação de placeholder é, por natureza, um contador que avança conforme a
 * árvore é percorrida.
 *
 * Placeholders são nomeados e sequenciais (`:p0`, `:p1`). Nomeados, e não `?`
 * posicionais, porque um mesmo valor pode aparecer em cláusulas diferentes e a ordem
 * de montagem do SQL não é a ordem de percurso da árvore: o LIMIT é alocado antes do
 * WHERE em alguns caminhos, e com posicionais isso trocaria os valores em silêncio.
 */
final class BindCollector
{
    /** @var array<string,array{mixed,int}> */
    private array $binds = [];

    private int $next = 0;

    /**
     * Registra um valor e devolve o placeholder a escrever no SQL.
     */
    public function add(mixed $value, int $pdoType = PDO::PARAM_STR): string
    {
        $placeholder = 'p' . $this->next++;

        $this->binds[$placeholder] = [$value, $pdoType];

        return ':' . $placeholder;
    }

    /**
     * @return array<string,array{mixed,int}>
     */
    public function all(): array
    {
        return $this->binds;
    }

    public function count(): int
    {
        return $this->next;
    }
}
