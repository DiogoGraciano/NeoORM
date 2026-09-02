<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Support\Output;

/**
 * Descarta tudo. O default da biblioteca.
 *
 * Uma biblioteca que escreve na saída padrão por conta própria é uma biblioteca que
 * não dá para embutir. O silêncio não esconde falha: falha é exceção tipada, não
 * mensagem.
 */
final class NullOutput implements Output
{
    public function write(string $message): void
    {
    }

    public function success(string $message): void
    {
    }

    public function warning(string $message): void
    {
    }

    public function error(string $message): void
    {
    }
}
