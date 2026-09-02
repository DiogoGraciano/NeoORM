<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Console;

/**
 * Os argumentos da linha de comando, já separados.
 *
 * Escrito à mão em vez de `getopt()` por um motivo concreto: **o `getopt()` para de
 * parsear no primeiro argumento que não é opção**, e o nome do subcomando é exatamente
 * isso. `neoorm migration:generate --allow-destructive` devolveria opção nenhuma, em
 * silêncio — e "em silêncio" aqui significa gerar uma migração destrutiva sem a
 * confirmação que o usuário achou que tinha dado.
 *
 * E é PURO: recebe `$argv`, não lê `$_SERVER`, não escreve em lugar nenhum. É o que
 * permite testar o parsing sem processo, sem terminal e sem banco.
 */
final readonly class Arguments
{
    /**
     * @param list<string> $positionals argumentos livres, na ordem
     * @param array<string,string|true> $options `--flag` vira `true`, `--opt=v` vira `'v'`
     * @param array<string,list<string>> $repeated opções que podem aparecer mais de uma vez
     */
    private function __construct(
        public ?string $command,
        public array $positionals,
        public array $options,
        public array $repeated,
    ) {
    }

    /**
     * @param list<string> $argv o `$argv` completo, com o nome do script na posição 0
     */
    public static function parse(array $argv): self
    {
        $rest = array_slice($argv, 1);
        $command = null;
        $positionals = [];
        $options = [];
        $repeated = [];

        foreach ($rest as $argument) {
            if (!str_starts_with($argument, '--')) {
                if ($command === null) {
                    $command = $argument;

                    continue;
                }

                $positionals[] = $argument;

                continue;
            }

            $body = substr($argument, 2);

            // `--opt=valor` e `--flag`. Não há forma curta: uma letra é ambígua entre
            // comandos que não compartilham opções, e o ganho de digitação não paga o
            // `-f` que significa `--force` num comando e `--file` noutro.
            [$name, $value] = str_contains($body, '=')
                ? [substr($body, 0, (int) strpos($body, '=')), substr($body, (int) strpos($body, '=') + 1)]
                : [$body, true];

            $options[$name] = $value;
            $repeated[$name][] = $value === true ? '' : $value;
        }

        return new self($command, $positionals, $options, $repeated);
    }

    public function has(string $name): bool
    {
        return isset($this->options[$name]);
    }

    public function value(string $name, ?string $default = null): ?string
    {
        $value = $this->options[$name] ?? null;

        return is_string($value) ? $value : $default;
    }

    public function integer(string $name): ?int
    {
        $value = $this->value($name);

        return $value === null || !ctype_digit($value) ? null : (int) $value;
    }

    /**
     * Todas as ocorrências de uma opção repetível, como `--rename a:b --rename c:d`.
     *
     * @return list<string>
     */
    public function all(string $name): array
    {
        return array_values(array_filter(
            $this->repeated[$name] ?? [],
            static fn (string $value): bool => $value !== '',
        ));
    }

    /**
     * Os nomes de opção que não estão na lista permitida.
     *
     * Recusar o desconhecido em vez de ignorá-lo é o que faz um `--dry-runn` digitado
     * errado falhar em vez de aplicar a migração de verdade.
     *
     * @param list<string> $allowed
     * @return list<string>
     */
    public function unknown(array $allowed): array
    {
        return array_values(array_diff(array_keys($this->options), $allowed));
    }
}
