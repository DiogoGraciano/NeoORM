<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Diff;

/**
 * Dois métodos, para a biblioteca não depender de pacote de CLI.
 *
 * O `InteractiveRenameResolver` precisa perguntar algo a alguém. Passar por esta
 * interface é o que mantém `Diogodg\Neoorm` sem dependência de terminal: o
 * entrypoint da CLI implementa isto, os testes implementam com respostas fixas, e
 * o differ continua puro.
 */
interface Prompter
{
    /**
     * Escolha entre opções, ou nenhuma.
     *
     * @param list<string> $options
     * @return string|null a opção escolhida, ou null para "nenhuma delas"
     */
    public function choose(string $question, array $options): ?string;

    public function confirm(string $question, bool $default = false): bool;
}
