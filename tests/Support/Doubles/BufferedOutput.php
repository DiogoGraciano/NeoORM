<?php

declare(strict_types=1);

namespace Tests\Support\Doubles;

use Diogodg\Neoorm\Support\Output\Output;

/**
 * Guarda as mensagens em vez de imprimi-las.
 *
 * Existe porque a suíte roda com `beStrictAboutOutputDuringTests`: um `echo` de dentro da
 * biblioteca derruba o teste em vez de sujar a saída em silêncio. É a costura que faltava
 * ao `Migrate` antigo, que dava `echo` de seis lugares e por isso não podia ser testado nem
 * usado fora de um terminal.
 */
final class BufferedOutput implements Output
{
    /** @var list<string> */
    public array $lines = [];

    /** @var list<string> */
    public array $errors = [];

    /** @var list<string> */
    public array $warnings = [];

    public function write(string $message): void
    {
        $this->lines[] = $message;
    }

    public function success(string $message): void
    {
        $this->lines[] = $message;
    }

    public function warning(string $message): void
    {
        $this->lines[] = $message;
        $this->warnings[] = $message;
    }

    public function error(string $message): void
    {
        $this->lines[] = $message;
        $this->errors[] = $message;
    }

    public function text(): string
    {
        return implode("\n", $this->lines);
    }
}
