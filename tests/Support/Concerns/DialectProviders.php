<?php

declare(strict_types=1);

namespace Tests\Support\Concerns;

use Diogodg\Neoorm\Dialect\DialectFactory;

trait DialectProviders
{
    /**
     * Todo dialeto que existe, com nome de data set legível.
     *
     * Vem de `DialectFactory::all()` em vez de uma lista escrita à mão: um dialeto
     * novo entra automaticamente em todo teste de invariante, sem depender de
     * alguém lembrar de acrescentá-lo em cada arquivo.
     *
     * @return iterable<string,array{string}>
     */
    public static function dialects(): iterable
    {
        foreach (array_keys(DialectFactory::all()) as $name) {
            yield $name => [$name];
        }
    }
}
