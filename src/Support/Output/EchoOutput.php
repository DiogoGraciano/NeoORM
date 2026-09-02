<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Support\Output;

/**
 * Escreve no terminal, com cor quando o terminal aceita cor.
 *
 * A detecção de TTY importa: sem ela, redirecionar a saída para arquivo ou para o log
 * de um CI enche o arquivo de sequências de escape.
 */
final class EchoOutput implements Output
{
    private readonly bool $colors;

    public function __construct(?bool $colors = null)
    {
        $this->colors = $colors ?? (PHP_SAPI === 'cli' && stream_isatty(STDOUT));
    }

    public function write(string $message): void
    {
        echo $message . PHP_EOL;
    }

    public function success(string $message): void
    {
        echo $this->paint($message, '32') . PHP_EOL;
    }

    public function warning(string $message): void
    {
        echo $this->paint($message, '33') . PHP_EOL;
    }

    public function error(string $message): void
    {
        fwrite(STDERR, $this->paint($message, '31') . PHP_EOL);
    }

    private function paint(string $message, string $code): string
    {
        return $this->colors ? "\033[{$code}m{$message}\033[0m" : $message;
    }
}
