<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Runtime\Casting;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use JsonException;
use Throwable;

/**
 * Converte o valor cru do PDO no tipo declarado da coluna.
 *
 * **Neutro de dialeto e total.** Cada caster aceita TODA representação que qualquer um
 * dos dois bancos produz e recusa o resto. `bool()` aceita `true`, `1`, `'1'` e `'t'`
 * porque o mysqlnd devolve int e o pdo_pgsql devolve bool ou `'t'` conforme a versão.
 *
 * Custa um braço a mais de `match` e compra três coisas: o DTO gerado não carrega
 * estado de dialeto nenhum, `UsersRow::fromRow($row)` vira função pura de um array —
 * testável sem banco — e o mesmo arquivo gerado vale contra os dois bancos sem
 * regerar, o que importa porque os arquivos são commitados.
 *
 * Todo método recebe `$context`, o caminho da coluna, emitido como literal pelo
 * gerador. É o que troca "Cannot parse date" por
 * "users.created_at: valor temporal inválido '0000-00-00'".
 *
 * ## Fusos
 *
 * Os casters temporais assumem que a sessão está em UTC. Para `TIMESTAMP` no MySQL o
 * servidor converte na leitura usando `@@session.time_zone`, então sem isso a MESMA
 * linha volta diferente conforme o fuso do container. Quem abre a conexão é responsável
 * por fixar a sessão; nenhum caster consegue compensar depois.
 */
final class Cast
{
    private function __construct()
    {
    }

    // ---------------------------------------------------------------- inteiro

    public static function int(mixed $value, string $context): int
    {
        if (is_int($value)) {
            return $value;
        }

        // O driver devolve string quando o valor excede a precisão nativa, e também em
        // colunas calculadas. Aceitar só o que representa um inteiro exato: '1.5' aqui
        // seria perda silenciosa.
        if (is_string($value) && preg_match('/^-?\d+$/', $value) === 1) {
            return (int) $value;
        }

        throw CastException::for($context, 'esperava inteiro, recebeu', $value);
    }

    public static function nullableInt(mixed $value, string $context): ?int
    {
        return $value === null ? null : self::int($value, $context);
    }

    // ------------------------------------------------------------------ float

    public static function float(mixed $value, string $context): float
    {
        if (is_float($value) || is_int($value)) {
            return (float) $value;
        }

        if (is_string($value) && is_numeric($value)) {
            return (float) $value;
        }

        throw CastException::for($context, 'esperava número, recebeu', $value);
    }

    public static function nullableFloat(mixed $value, string $context): ?float
    {
        return $value === null ? null : self::float($value, $context);
    }

    // ---------------------------------------------------------------- decimal

    /**
     * DECIMAL como string, preservando a representação exata do banco.
     *
     * Devolver `(string) $float` reintroduziria a perda que o tipo string evita — e é
     * por isso que o valor numérico nunca passa por float no caminho.
     *
     * @return numeric-string
     */
    public static function decimal(mixed $value, string $context): string
    {
        if (is_string($value) && is_numeric($value)) {
            /** @var numeric-string */
            return $value;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        // Float chegando aqui significa driver ou coluna fora do previsto. Converter é
        // melhor que falhar, mas a precisão já se perdeu antes deste ponto.
        if (is_float($value) && is_finite($value)) {
            /** @var numeric-string */
            return var_export($value, true);
        }

        throw CastException::for($context, 'esperava decimal, recebeu', $value);
    }

    /**
     * @return numeric-string|null
     */
    public static function nullableDecimal(mixed $value, string $context): ?string
    {
        return $value === null ? null : self::decimal($value, $context);
    }

    // --------------------------------------------------------------- booleano

    /**
     * O caster com mais representações de entrada, e por um motivo concreto: o MySQL
     * guarda BOOLEAN como TINYINT(1) e o mysqlnd devolve `int`; o pdo_pgsql devolve
     * `bool` nativo em versão recente e `'t'`/`'f'` em versão antiga.
     */
    public static function bool(mixed $value, string $context): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if ($value === 1 || $value === 0) {
            return $value === 1;
        }

        if (is_string($value)) {
            return match (strtolower($value)) {
                '1', 't', 'true', 'y', 'yes', 'on' => true,
                '0', 'f', 'false', 'n', 'no', 'off' => false,
                default => throw CastException::for($context, 'esperava booleano, recebeu', $value),
            };
        }

        throw CastException::for($context, 'esperava booleano, recebeu', $value);
    }

    public static function nullableBool(mixed $value, string $context): ?bool
    {
        return $value === null ? null : self::bool($value, $context);
    }

    // ----------------------------------------------------------------- texto

    public static function string(mixed $value, string $context): string
    {
        if (is_string($value)) {
            return $value;
        }

        // Coluna textual pode voltar como int quando o driver detecta o conteúdo
        // numérico, e como bool em coluna calculada no PostgreSQL.
        if (is_int($value) || is_float($value)) {
            return (string) $value;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        throw CastException::for($context, 'esperava texto, recebeu', $value);
    }

    public static function nullableString(mixed $value, string $context): ?string
    {
        return $value === null ? null : self::string($value, $context);
    }

    /**
     * CHAR, com o espaço à direita removido.
     *
     * O MySQL corta o preenchimento na leitura e o PostgreSQL o devolve. Sem isto,
     * `CHAR(10)` guardando `'ab'` volta `'ab'` num banco e `'ab        '` no outro, e
     * uma comparação com `===` passa a depender do banco.
     */
    public static function char(mixed $value, string $context): string
    {
        return rtrim(self::string($value, $context), ' ');
    }

    public static function nullableChar(mixed $value, string $context): ?string
    {
        return $value === null ? null : self::char($value, $context);
    }

    // -------------------------------------------------------------- temporais

    public static function dateTime(mixed $value, string $context): DateTimeImmutable
    {
        return self::parseTemporal($value, $context)->setTimezone(new DateTimeZone('UTC'));
    }

    public static function nullableDateTime(mixed $value, string $context): ?DateTimeImmutable
    {
        return $value === null ? null : self::dateTime($value, $context);
    }

    /**
     * Igual, mas normalizando para UTC.
     *
     * O `TIMESTAMPTZ` guarda um instante, e o texto que o banco devolve traz o offset
     * da sessão. Normalizar na leitura é o que faz duas linhas gravadas em fusos
     * diferentes compararem corretamente entre si.
     */
    public static function dateTimeTz(mixed $value, string $context): DateTimeImmutable
    {
        return self::parseTemporal($value, $context)->setTimezone(new DateTimeZone('UTC'));
    }

    public static function nullableDateTimeTz(mixed $value, string $context): ?DateTimeImmutable
    {
        return $value === null ? null : self::dateTimeTz($value, $context);
    }

    private static function parseTemporal(mixed $value, string $context): DateTimeImmutable
    {
        if ($value instanceof DateTimeImmutable) {
            return $value;
        }

        if ($value instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($value);
        }

        if (!is_string($value)) {
            throw CastException::for($context, 'esperava data, recebeu', $value);
        }

        // O MySQL fora do modo estrito aceita e devolve '0000-00-00 00:00:00'. Nenhuma
        // data real corresponde a isso, e converter para 1970 — que é o que um parser
        // permissivo faria — trocaria um dado ausente por um dado errado, que é pior.
        if (str_starts_with($value, '0000-00-00')) {
            throw CastException::for(
                $context,
                'data zero do MySQL não representa instante nenhum; a coluna deveria ser NULL em vez de',
                $value,
            );
        }

        try {
            // UTC explícito, e não o fuso default do PHP. O texto que os dois bancos
            // devolvem para DATETIME/TIMESTAMP não traz offset, e sem o segundo argumento
            // ele seria lido no `date.timezone` do processo: a MESMA linha viraria
            // instantes diferentes conforme o container que a leu. O argumento é ignorado
            // quando a string traz offset — o caso do `TIMESTAMPTZ` —, que é exatamente o
            // desejado ali.
            return new DateTimeImmutable($value, new DateTimeZone('UTC'));
        } catch (Throwable $e) {
            throw CastException::for($context, 'valor temporal inválido', $value);
        }
    }

    // ------------------------------------------------------------------- JSON

    /**
     * Decodificado, e o retorno é `mixed` de propósito.
     *
     * `array` seria mentira: `'42'`, `'"texto"'` e `'null'` são documentos JSON válidos
     * e nenhum vira array. Um DTO anotado `array` produziria TypeError com dado real.
     */
    public static function json(mixed $value, string $context): mixed
    {
        // Alguns caminhos já entregam decodificado — coluna JSON somada a um driver que
        // converte, ou valor que passou pelo Binder e voltou.
        if (is_array($value)) {
            return $value;
        }

        if ($value === null) {
            throw CastException::for($context, 'esperava JSON, recebeu', $value);
        }

        if (!is_string($value)) {
            throw CastException::for($context, 'esperava JSON, recebeu', $value);
        }

        try {
            return json_decode($value, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw CastException::for($context, 'JSON inválido: ' . $e->getMessage() . ' em', $value);
        }
    }

    /**
     * `mixed` já contém null, então a variante existe só para o gerador poder chamar
     * `nullableJson` uniformemente, como faz com os demais tipos.
     */
    public static function nullableJson(mixed $value, string $context): mixed
    {
        return $value === null ? null : self::json($value, $context);
    }

    // --------------------------------------------------------------- binário

    /**
     * O pdo_pgsql entrega `bytea` como RESOURCE de stream, não como string.
     *
     * Sem isto, uma coluna binária chegaria ao DTO como `Resource id #7` e qualquer
     * comparação ou gravação subsequente falharia longe da causa.
     */
    public static function binary(mixed $value, string $context): string
    {
        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            if ($contents === false) {
                throw CastException::for($context, 'não foi possível ler o stream de', $value);
            }

            return $contents;
        }

        return self::string($value, $context);
    }

    public static function nullableBinary(mixed $value, string $context): ?string
    {
        return $value === null ? null : self::binary($value, $context);
    }
}
