<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Type;

/**
 * Que tipos cada dialeto suporta, e com que limites.
 *
 * Fica separado de TypeSpec de propósito: o IR precisa ser neutro para que o
 * mesmo schema possa ser comparado com um banco MySQL e com um PostgreSQL. No
 * sistema antigo, declarar `payload` como JSONB lançava dentro do próprio
 * construtor porque `Config::getDriver()` calhava de ser mysql — a definição de
 * schema não existia sem um driver configurado, e por isso nada era testável
 * sem banco.
 *
 * Recebe o dialeto como string para não criar dependência de Schema para
 * Dialect: a direção é sempre Dialect -> Schema.
 */
final class TypeCatalog
{
    private function __construct()
    {
    }

    /**
     * @return string|null null quando suportado; a razão, quando não
     */
    public static function check(TypeSpec $type, string $dialect): ?string
    {
        $name = $type->name;

        $unsupported = match ($dialect) {
            'mysql' => match ($name) {
                TypeName::Jsonb => 'JSONB é do PostgreSQL; no MySQL use JSON.',
                TypeName::Uuid => 'UUID não existe no MySQL; use CHAR(36) ou BINARY(16).',
                TypeName::Bytea => 'BYTEA é do PostgreSQL; no MySQL use BLOB.',
                TypeName::TimestampTz => 'TIMESTAMPTZ não existe no MySQL; use TIMESTAMP.',
                default => null,
            },
            'pgsql' => match ($name) {
                TypeName::TinyInt, TypeName::MediumInt =>
                    "{$name->value} não existe no PostgreSQL; use SMALLINT ou INT.",
                TypeName::Enum =>
                    'ENUM nativo do MySQL não tem equivalente direto no PostgreSQL; use VARCHAR com uma restrição CHECK.',
                TypeName::TinyText, TypeName::MediumText, TypeName::LongText =>
                    "{$name->value} não existe no PostgreSQL; use TEXT.",
                TypeName::TinyBlob, TypeName::Blob, TypeName::MediumBlob, TypeName::LongBlob =>
                    "{$name->value} não existe no PostgreSQL; use BYTEA.",
                TypeName::Binary, TypeName::VarBinary =>
                    "{$name->value} não existe no PostgreSQL; use BYTEA.",
                TypeName::Double => null,
                TypeName::Year => 'YEAR não existe no PostgreSQL; use SMALLINT.',
                // DATETIME e TIMESTAMP são dois nomes para o mesmo tipo do
                // PostgreSQL (`timestamp without time zone`). Aceitar os dois
                // faria um model que escreve DATETIME renderizar TIMESTAMP,
                // introspectar de volta como TIMESTAMP e divergir do próprio
                // snapshot em toda execução. Recusar é o que mantém a relação
                // entre nome do IR e tipo do banco injetiva.
                TypeName::DateTime =>
                    'DATETIME e TIMESTAMP são o mesmo tipo no PostgreSQL; declare TIMESTAMP.',
                default => null,
            },
            default => "Dialeto desconhecido: '{$dialect}'.",
        };

        if ($unsupported !== null) {
            return $unsupported;
        }

        // Mesma razão: o PostgreSQL não tem tipos sem sinal, então o UNSIGNED
        // seria descartado na renderização e reapareceria como diferença a cada
        // introspecção.
        if ($dialect === 'pgsql' && $type->unsigned) {
            return 'O PostgreSQL não tem tipos UNSIGNED; use um tipo maior (BIGINT) ou uma restrição CHECK (coluna >= 0).';
        }

        return self::checkLimits($type, $dialect);
    }

    private static function checkLimits(TypeSpec $type, string $dialect): ?string
    {
        $name = $type->name;

        if ($type->length !== null) {
            $max = self::maxLength($name, $dialect);

            if ($max !== null && $type->length > $max) {
                return "{$name->value}({$type->length}) excede o máximo de {$max} neste dialeto.";
            }

            if ($type->length === 0 && $name->acceptsLength()) {
                return "{$name->value}(0) não é um tamanho válido.";
            }
        }

        if ($type->precision !== null && $name === TypeName::Decimal) {
            $maxPrecision = $dialect === 'mysql' ? 65 : 1000;

            if ($type->precision > $maxPrecision) {
                return "DECIMAL com precisão {$type->precision} excede o máximo de {$maxPrecision} neste dialeto.";
            }
        }

        return null;
    }

    public static function maxLength(TypeName $type, string $dialect): ?int
    {
        if ($dialect === 'mysql') {
            return match ($type) {
                TypeName::Char, TypeName::Binary => 255,
                // O limite real é em bytes e depende do charset; 65535 é o teto
                // absoluto da linha, e é o que a validação antiga usava.
                TypeName::Varchar, TypeName::VarBinary => 65535,
                default => null,
            };
        }

        return match ($type) {
            TypeName::Char, TypeName::Varchar => 10485760,
            default => null,
        };
    }

    /**
     * Tipos que exigem comprimento para serem úteis. VARCHAR sem comprimento é
     * legal no PostgreSQL (ilimitado) e ilegal no MySQL.
     */
    public static function requiresLength(TypeName $type, string $dialect): bool
    {
        if ($dialect !== 'mysql') {
            return false;
        }

        return match ($type) {
            TypeName::Varchar, TypeName::VarBinary => true,
            default => false,
        };
    }
}
