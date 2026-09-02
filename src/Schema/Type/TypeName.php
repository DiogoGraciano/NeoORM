<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Type;

/**
 * Nomes de tipo canônicos, neutros de dialeto.
 *
 * A união dos tipos dos dois bancos, numa grafia só. É o que permite comparar
 * um schema declarado com um schema introspectado: o MySQL reporta `int`, o
 * PostgreSQL reporta `integer`, e nenhum dos dois pode vazar para dentro do IR
 * — senão a comparação vira comparação de texto de SQL, que foi exatamente o
 * problema do sistema antigo.
 *
 * Qual dialeto suporta o quê é assunto de TypeCatalog, não deste enum.
 */
enum TypeName: string
{
    case TinyInt = 'TINYINT';
    case SmallInt = 'SMALLINT';
    case MediumInt = 'MEDIUMINT';
    case Int = 'INT';
    case BigInt = 'BIGINT';
    case Decimal = 'DECIMAL';
    case Float = 'FLOAT';
    case Double = 'DOUBLE';
    case Boolean = 'BOOLEAN';

    case Char = 'CHAR';
    case Varchar = 'VARCHAR';
    case TinyText = 'TINYTEXT';
    case Text = 'TEXT';
    case MediumText = 'MEDIUMTEXT';
    case LongText = 'LONGTEXT';

    case Date = 'DATE';
    case Time = 'TIME';
    case DateTime = 'DATETIME';
    case Timestamp = 'TIMESTAMP';
    case TimestampTz = 'TIMESTAMPTZ';
    case Year = 'YEAR';

    case Json = 'JSON';
    case Jsonb = 'JSONB';
    case Uuid = 'UUID';

    case Binary = 'BINARY';
    case VarBinary = 'VARBINARY';
    case TinyBlob = 'TINYBLOB';
    case Blob = 'BLOB';
    case MediumBlob = 'MEDIUMBLOB';
    case LongBlob = 'LONGBLOB';
    case Bytea = 'BYTEA';

    case Enum = 'ENUM';

    /**
     * Aceita as grafias que o DSL dos models e os catálogos dos bancos usam.
     *
     * As equivalências aqui são de nomenclatura, não de semântica: `INTEGER` e
     * `INT` são o mesmo tipo escrito de dois jeitos. Conversões que perdem
     * informação (TINYINT(1) virando BOOLEAN, por exemplo) são decisão de
     * dialeto e ficam nos normalizadores da introspecção.
     */
    public static function fromAlias(string $type): ?self
    {
        $normalized = strtoupper(trim($type));

        $aliases = [
            'INTEGER' => self::Int,
            'INT4' => self::Int,
            'INT2' => self::SmallInt,
            'INT8' => self::BigInt,
            'SERIAL' => self::Int,
            'BIGSERIAL' => self::BigInt,
            'SMALLSERIAL' => self::SmallInt,
            'NUMERIC' => self::Decimal,
            'DEC' => self::Decimal,
            'FIXED' => self::Decimal,
            'REAL' => self::Float,
            'FLOAT4' => self::Float,
            'FLOAT8' => self::Double,
            'DOUBLE PRECISION' => self::Double,
            'BOOL' => self::Boolean,
            'CHARACTER' => self::Char,
            'BPCHAR' => self::Char,
            'CHARACTER VARYING' => self::Varchar,
            'TIMESTAMP WITHOUT TIME ZONE' => self::Timestamp,
            'TIMESTAMP WITH TIME ZONE' => self::TimestampTz,
            'TIMESTAMPTZ' => self::TimestampTz,
            'TIME WITHOUT TIME ZONE' => self::Time,
            'TIME WITH TIME ZONE' => self::Time,
        ];

        return $aliases[$normalized] ?? self::tryFrom($normalized);
    }

    public function isIntegral(): bool
    {
        return match ($this) {
            self::TinyInt, self::SmallInt, self::MediumInt, self::Int, self::BigInt => true,
            default => false,
        };
    }

    public function isNumeric(): bool
    {
        return $this->isIntegral() || match ($this) {
            self::Decimal, self::Float, self::Double => true,
            default => false,
        };
    }

    /**
     * Tipos que aceitam uma função temporal como default.
     *
     * Serve para decidir se um `CURRENT_TIMESTAMP` que veio do catálogo é a expressão
     * ou o texto: numa coluna de data só pode ser a expressão, numa de texto só pode
     * ser o literal.
     */
    public function isTemporal(): bool
    {
        return match ($this) {
            self::Date, self::Time, self::DateTime,
            self::Timestamp, self::TimestampTz, self::Year => true,
            default => false,
        };
    }

    public function isTextual(): bool
    {
        return match ($this) {
            self::Char, self::Varchar, self::TinyText, self::Text,
            self::MediumText, self::LongText => true,
            default => false,
        };
    }

    /**
     * Tipos cujo comprimento faz parte da identidade: VARCHAR(120) e
     * VARCHAR(255) são tipos diferentes; TEXT não tem comprimento.
     */
    public function acceptsLength(): bool
    {
        return match ($this) {
            self::Char, self::Varchar, self::Binary, self::VarBinary => true,
            default => false,
        };
    }

    public function acceptsPrecision(): bool
    {
        return match ($this) {
            self::Decimal, self::Float, self::Double => true,
            default => false,
        };
    }
}
