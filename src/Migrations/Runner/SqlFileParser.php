<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

use Diogodg\Neoorm\Dialect\SqlWriter;

/**
 * Separa um arquivo `.sql` de migração nos statements que o compõem.
 *
 * O caminho normal não é um parser: o arquivo que esta biblioteca escreveu tem
 * `--> statement-breakpoint` numa linha só dele entre um statement e o próximo, e nesse
 * caso dividir é um `explode()` — exato, sem caso de borda, sem opinião sobre a
 * gramática do SQL.
 *
 * O parser existe só para o arquivo editado à mão, que perdeu o marcador. Aí não há
 * escolha: é preciso entender onde um ponto e vírgula é separador e onde ele é conteúdo.
 * Ponto e vírgula aparece dentro de literal de string, de identificador citado, de
 * comentário de linha, de comentário de bloco e de corpo de função com dollar-quoting no
 * PostgreSQL. Um `explode(';')` acerta o caso fácil e corrompe silenciosamente o resto —
 * e "silenciosamente" aqui significa mandar meio comando para o banco.
 *
 * O que este arquivo NÃO faz é tentar entender SQL além disso. Não sabe o que é um
 * `CREATE`, não valida nada, não reordena. Só sabe onde cada statement termina.
 */
final class SqlFileParser
{
    /**
     * @return list<string> statements sem o `;` final, na ordem do arquivo
     */
    public function parse(string $contents): array
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $contents);

        if (str_contains($normalized, SqlWriter::BREAKPOINT)) {
            return $this->splitOnBreakpoints($normalized);
        }

        return $this->scan($normalized);
    }

    /**
     * @return list<string>
     */
    private function splitOnBreakpoints(string $contents): array
    {
        $statements = [];

        foreach (explode(SqlWriter::BREAKPOINT, $contents) as $chunk) {
            $statement = $this->clean($chunk);

            if ($statement !== '') {
                $statements[] = $statement;
            }
        }

        return $statements;
    }

    /**
     * O fallback: caminha caractere a caractere e corta nos `;` que estão fora de
     * qualquer contexto citado ou comentado.
     *
     * @return list<string>
     */
    private function scan(string $contents): array
    {
        $statements = [];
        $current = '';
        $length = strlen($contents);
        $i = 0;

        while ($i < $length) {
            $char = $contents[$i];
            $next = $i + 1 < $length ? $contents[$i + 1] : '';

            // Comentário de linha: `--` e `#` (o MySQL aceita os dois).
            if (($char === '-' && $next === '-') || $char === '#') {
                $end = strpos($contents, "\n", $i);
                $stop = $end === false ? $length : $end;
                $current .= substr($contents, $i, $stop - $i);
                $i = $stop;
                continue;
            }

            // Comentário de bloco. Não aninha em nenhum dos dois bancos.
            if ($char === '/' && $next === '*') {
                $end = strpos($contents, '*/', $i + 2);
                $stop = $end === false ? $length : $end + 2;
                $current .= substr($contents, $i, $stop - $i);
                $i = $stop;
                continue;
            }

            // Dollar-quoting do PostgreSQL: `$$ ... $$` ou `$tag$ ... $tag$`. É o que
            // envolve corpo de função, onde ponto e vírgula é a regra e não a exceção.
            if ($char === '$' && ($tag = $this->dollarTagAt($contents, $i)) !== null) {
                $end = strpos($contents, $tag, $i + strlen($tag));
                $stop = $end === false ? $length : $end + strlen($tag);
                $current .= substr($contents, $i, $stop - $i);
                $i = $stop;
                continue;
            }

            if ($char === "'" || $char === '"' || $char === '`') {
                $consumed = $this->consumeQuoted($contents, $i, $char);
                $current .= $consumed;
                $i += strlen($consumed);
                continue;
            }

            if ($char === ';') {
                $statement = $this->clean($current);

                if ($statement !== '') {
                    $statements[] = $statement;
                }

                $current = '';
                $i++;
                continue;
            }

            $current .= $char;
            $i++;
        }

        // O último statement pode não ter `;`.
        $statement = $this->clean($current);

        if ($statement !== '') {
            $statements[] = $statement;
        }

        return $statements;
    }

    /**
     * O delimitador de dollar-quoting que começa em `$offset`, ou null se ali é só um
     * `$` solto.
     *
     * A tag aceita letra, dígito e `_`, e não pode começar com dígito — é a regra de
     * identificador do PostgreSQL. Sem essa checagem, um `$1` de placeholder abriria um
     * bloco citado que nunca fecha e engoliria o resto do arquivo.
     */
    private function dollarTagAt(string $contents, int $offset): ?string
    {
        if (preg_match('/\G\$([A-Za-z_][A-Za-z0-9_]*)?\$/', $contents, $matches, 0, $offset) !== 1) {
            return null;
        }

        return $matches[0];
    }

    /**
     * Consome um trecho citado inteiro, incluindo os delimitadores, e devolve o texto
     * consumido.
     *
     * Trata as duas formas de escapar que os dois bancos usam: a aspa dobrada
     * (`'d''água'`, padrão SQL, válida em ambos) e a contrabarra (`'d\'água'`, do MySQL
     * quando `NO_BACKSLASH_ESCAPES` está desligado, que é o default). Tratar só uma
     * delas faz o scanner perder o fim da string e passar a ler comando como conteúdo.
     */
    private function consumeQuoted(string $contents, int $offset, string $quote): string
    {
        $length = strlen($contents);
        $i = $offset + 1;

        while ($i < $length) {
            $char = $contents[$i];

            if ($char === '\\' && $quote !== '`' && $i + 1 < $length) {
                $i += 2;
                continue;
            }

            if ($char === $quote) {
                // Aspa dobrada é a aspa em si, não o fim do trecho.
                if ($i + 1 < $length && $contents[$i + 1] === $quote) {
                    $i += 2;
                    continue;
                }

                return substr($contents, $offset, $i + 1 - $offset);
            }

            $i++;
        }

        // Citação não fechada: devolve o resto. Quem executa recebe o erro do banco, com
        // o SQL na mensagem — melhor que este método adivinhar onde a string terminaria.
        return substr($contents, $offset);
    }

    /**
     * Tira espaço em branco e o `;` final, e descarta o que sobrou só de comentário.
     *
     * Mandar um chunk que é só comentário para o banco não é erro em lugar nenhum, mas
     * conta como statement na tabela de controle — e aí o número de statements aplicados
     * deixa de casar com o número de statements do arquivo.
     */
    private function clean(string $chunk): string
    {
        $statement = rtrim(trim($chunk), "; \t\n\r\0\x0B");

        if ($statement === '') {
            return '';
        }

        return $this->isOnlyComments($statement) ? '' : $statement;
    }

    private function isOnlyComments(string $statement): bool
    {
        $stripped = (string) preg_replace(
            ['#/\*.*?\*/#s', '/^\s*(--|#).*$/m'],
            '',
            $statement,
        );

        return trim($stripped) === '';
    }

    /**
     * Hash canônico do arquivo, para a tabela de controle.
     *
     * Normaliza fim de linha e corta espaço no fim porque um checkout com `core.autocrlf`
     * ligado, ou um editor que apara a última linha, não pode invalidar o histórico de
     * migrações de um projeto inteiro. O que se quer detectar é edição de CONTEÚDO.
     */
    public static function hash(string $contents): string
    {
        return hash('sha256', rtrim(str_replace(["\r\n", "\r"], "\n", $contents)));
    }
}
