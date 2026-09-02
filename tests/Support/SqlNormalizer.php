<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * A menor normalização de SQL que resolve o problema real.
 *
 * O problema real é só um: uma expectativa de `CREATE TABLE` escrita em várias
 * linhas para caber na tela não deveria falhar contra a mesma DDL escrita em
 * outra indentação. Então isto colapsa espaço em branco, e nada mais.
 *
 * O que NÃO faz, deliberadamente: não deixa palavra-chave em maiúscula, não
 * reordena cláusula, não remove parêntese redundante. Cada uma dessas
 * "melhorias" mascararia uma diferença de verdade, e é assim que se entrega um
 * diff fantasma ao usuário — o gerador e o comparador passariam a discordar em
 * silêncio, com a suíte verde. Se um dia um teste precisar de mais normalização
 * que isto, o lugar da normalização é o normalizador de produção, não aqui.
 *
 * Literais de string são protegidos porque o espaço dentro deles é dado, não
 * formatação: um comentário `'City   name'` não é o mesmo que `'City name'`.
 */
final class SqlNormalizer
{
    private function __construct()
    {
    }

    public static function collapse(string $sql): string
    {
        $literals = [];

        // Aspa simples doblada é o escape das duas engines, então `''` dentro do
        // padrão mantém o literal inteiro num só match.
        $protected = preg_replace_callback(
            "/'(?:[^']|'')*'/",
            static function (array $match) use (&$literals): string {
                $literals[] = $match[0];

                return "\0literal:" . (count($literals) - 1) . "\0";
            },
            $sql,
        ) ?? $sql;

        $collapsed = trim((string) preg_replace('/\s+/', ' ', $protected));
        $collapsed = rtrim($collapsed, "; \t\n\r");

        return (string) preg_replace_callback(
            "/\0literal:(\d+)\0/",
            static fn (array $match): string => $literals[(int) $match[1]],
            $collapsed,
        );
    }

    /**
     * @param list<string> $statements
     * @return list<string>
     */
    public static function collapseAll(array $statements): array
    {
        return array_map(self::collapse(...), $statements);
    }
}
