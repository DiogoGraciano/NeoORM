<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Runtime\Casting;

use BackedEnum;
use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use JsonException;
use PDO;

/**
 * Converte um valor PHP no par (valor, tipo PDO) que vai para o bind.
 *
 * O oposto de {@see Cast} nos dois eixos. Aquele é neutro de dialeto porque roda dentro
 * de um DTO gerado que não pode carregar configuração; este é **ciente de dialeto**
 * porque roda onde já existe uma conexão, e ali o dialeto sai de graça.
 *
 * A assimetria que justifica isso é o booleano: o PostgreSQL tem `boolean` de verdade e
 * quer `PARAM_BOOL`; o MySQL guarda `TINYINT(1)` e precisa receber 0 ou 1 como inteiro.
 * Mandar `PARAM_BOOL` ao MySQL com `ATTR_EMULATE_PREPARES` desligado escreve a string
 * vazia para `false` — a linha grava, sem erro, com o valor errado.
 */
final readonly class Binder
{
    public function __construct(private Dialect $dialect)
    {
    }

    /**
     * @return array{0:string|int|float|bool|null,1:int} valor e constante PDO::PARAM_*
     */
    public function bind(mixed $value, ?TypeSpec $type = null): array
    {
        if ($value === null) {
            return [null, PDO::PARAM_NULL];
        }

        // JSON e JSONB descrevem o documento, não o tipo escalar que o representa em
        // PHP. Portanto `true`, `42` e `"texto"` também precisam ser serializados: sem
        // isso chegavam ao banco como booleano, inteiro e texto SQL comuns (e uma string
        // sequer é JSON válido sem as aspas do documento). `null` continua significando
        // SQL NULL, em linha com todas as demais colunas anuláveis.
        if ($this->isJson($type)) {
            return [$this->encodeJson($value), PDO::PARAM_STR];
        }

        // Enum gerado a partir de coluna ENUM: o que vai ao banco é o valor de trás.
        if ($value instanceof BackedEnum) {
            return $this->bind($value->value, $type);
        }

        if (is_bool($value)) {
            return $this->boolean($value);
        }

        if ($value instanceof DateTimeInterface) {
            return [$this->temporal($value, $type), PDO::PARAM_STR];
        }

        if (is_array($value)) {
            return [$this->encodeJson($value), PDO::PARAM_STR];
        }

        if (is_resource($value)) {
            $contents = stream_get_contents($value);

            return [$contents === false ? '' : $contents, PDO::PARAM_LOB];
        }

        if (is_int($value)) {
            return [$value, PDO::PARAM_INT];
        }

        // Sem PARAM_FLOAT no PDO. String preserva a representação e deixa a conversão
        // com o driver, que é o que evita o arredondamento acontecer duas vezes.
        if (is_float($value)) {
            return [var_export($value, true), PDO::PARAM_STR];
        }

        if (is_string($value)) {
            return [$value, $this->isBinary($type) ? PDO::PARAM_LOB : PDO::PARAM_STR];
        }

        throw CastException::for(
            'bind',
            'não sei transformar em valor de banco',
            $value,
        );
    }

    /**
     * @return array{0:int|bool,1:int}
     */
    private function boolean(bool $value): array
    {
        return $this->dialect->name() === 'mysql'
            ? [$value ? 1 : 0, PDO::PARAM_INT]
            : [$value, PDO::PARAM_BOOL];
    }

    /**
     * O valor temporal como texto, no fuso e no formato que a coluna espera.
     *
     * A sessão do banco é fixada em UTC (`Dialect::connectionSetup()`), e é por isso que
     * o valor precisa ir em UTC: formatá-lo no fuso do próprio objeto grava a hora de
     * parede de quem chamou. Um `DateTimeImmutable` em `America/Sao_Paulo` às 10h
     * gravaria `10:00:00` numa coluna cuja sessão lê tudo como UTC, e a linha voltaria
     * três horas deslocada — sem erro, e dependendo do `date.timezone` do container.
     *
     * `DATE` e `TIME` são a exceção, e não por descuido: ali o dado é de calendário e
     * não instante. Converter um aniversário de `2026-08-08 22:00 -03:00` para UTC
     * gravaria o dia 9. Quem escolheu a coluna escolheu a semântica; o bind respeita.
     */
    private function temporal(DateTimeInterface $value, ?TypeSpec $type): string
    {
        if ($type?->name === TypeName::Date || $type?->name === TypeName::Time) {
            return $value->format($this->temporalFormat($type));
        }

        // `createFromInterface` porque `DateTime` é mutável e `setTimezone()` alteraria o
        // objeto de quem chamou — um bind não tem por que ter efeito colateral.
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format($this->temporalFormat($type));
    }

    /**
     * O formato depende do tipo da coluna, não do valor.
     *
     * Gravar `'2026-08-08 00:00:00'` numa coluna `DATE` funciona nos dois bancos, mas
     * gravar só `'2026-08-08'` numa `DATETIME` zera a hora em silêncio. Sem o tipo, o
     * palpite seguro é o formato mais completo.
     */
    private function temporalFormat(?TypeSpec $type): string
    {
        return match ($type?->name) {
            TypeName::Date => 'Y-m-d',
            TypeName::Time => 'H:i:s',
            // Com offset explícito. O valor já vem convertido para UTC, então o offset é
            // sempre `+00:00` — e escrevê-lo é o que impede o PostgreSQL de reinterpretar
            // o texto no `TimeZone` da sessão caso ela não esteja em UTC.
            TypeName::TimestampTz => 'Y-m-d H:i:sP',
            default => 'Y-m-d H:i:s',
        };
    }

    private function isBinary(?TypeSpec $type): bool
    {
        return match ($type?->name) {
            TypeName::Binary,
            TypeName::VarBinary,
            TypeName::TinyBlob,
            TypeName::Blob,
            TypeName::MediumBlob,
            TypeName::LongBlob,
            TypeName::Bytea => true,
            default => false,
        };
    }

    private function isJson(?TypeSpec $type): bool
    {
        return $type?->name === TypeName::Json || $type?->name === TypeName::Jsonb;
    }

    private function encodeJson(mixed $value): string
    {
        try {
            return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        } catch (JsonException $e) {
            throw CastException::for('bind', 'não foi possível serializar como JSON: ' . $e->getMessage() . ' em', $value);
        }
    }
}
