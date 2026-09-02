<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Dialect;

/**
 * Serializa statements num arquivo `.sql` de migração.
 *
 * O separador é um marcador explícito, `--> statement-breakpoint`, e não o ponto
 * e vírgula. É a convenção do Drizzle e existe por uma razão prática: dividir SQL
 * por `;` exige um parser de verdade, porque ponto e vírgula aparece dentro de
 * string literal, de comentário e de corpo de função com dollar-quoting no
 * PostgreSQL. Um marcador em linha própria transforma a divisão numa operação de
 * `explode()`, exata e sem casos de borda.
 *
 * O comentário sobrevive à leitura porque é um comentário de SQL: quem abrir o
 * arquivo num cliente e rodar tudo de uma vez obtém o mesmo resultado.
 */
final class SqlWriter
{
    public const BREAKPOINT = '--> statement-breakpoint';

    /**
     * @param list<string> $statements
     */
    public function write(array $statements): string
    {
        $clean = [];

        foreach ($statements as $statement) {
            $statement = rtrim(trim($statement), "; \t\n\r\0\x0B");

            if ($statement !== '') {
                $clean[] = $statement . ';';
            }
        }

        if ($clean === []) {
            // Uma migração vazia é um arquivo vazio, não um arquivo com um
            // comentário dizendo que está vazio: o hash dele entra na tabela de
            // controle, e conteúdo decorativo é conteúdo que alguém edita.
            return '';
        }

        return implode("\n" . self::BREAKPOINT . "\n", $clean) . "\n";
    }
}
