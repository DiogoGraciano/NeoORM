<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;

/**
 * Tipo SQL para tipo PHP. **A única autoridade sobre isso no projeto.**
 *
 * O gerador de docblock da 1.x tinha o próprio mapa, e ele discordava: `DECIMAL` saía
 * como `float`, embora o PDO devolva string. Duas descrições da mesma coluna é
 * exatamente o tipo de divergência que ninguém nota até um valor voltar errado.
 *
 * O `match` é exaustivo sobre `TypeName` de propósito, sem braço `default`: um tipo
 * novo no enum quebra aqui, na compilação, em vez de cair silenciosamente em
 * `mixed` — que era o comportamento do gerador antigo e o motivo de `BIGINT`,
 * `BOOLEAN`, `DATE` e `JSON` não terem tipo nenhum no docblock.
 */
final class TypeMapper
{
    public function __construct(private readonly GeneratorOptions $options = new GeneratorOptions())
    {
    }

    public function for(ColumnDefinition $column): PhpType
    {
        return $this->forType($column->type)->asNullable(!$column->notNull);
    }

    public function forType(TypeSpec $type): PhpType
    {
        $name = $type->name;

        return match ($name) {
            TypeName::TinyInt,
            TypeName::SmallInt,
            TypeName::MediumInt,
            TypeName::Int,
            TypeName::Year => new PhpType('int', $this->integerDocblock($type), caster: 'int'),

            // O mysqlnd devolve BIGINT acima de PHP_INT_MAX como string, em silêncio.
            // O padrão assume o caso de 99% dos schemas; quem tem id de 64 bits de
            // verdade liga `bigIntAsString` e paga o preço na assinatura.
            TypeName::BigInt => $this->options->bigIntAsString
                ? new PhpType('string', 'numeric-string', caster: 'decimal')
                : new PhpType('int', $this->integerDocblock($type), caster: 'int'),

            TypeName::Float, TypeName::Double => new PhpType('float', 'float', caster: 'float'),

            // DECIMAL volta como string do PDO nos dois drivers, e é a única forma que
            // não perde precisão: DECIMAL(19,4) de dinheiro excede o que float
            // representa. Converter para float aqui seria desfazer, por conveniência, o
            // cuidado que o banco teve.
            TypeName::Decimal => $this->options->decimalAsFloat
                ? new PhpType('float', 'float', caster: 'float')
                : new PhpType('string', 'numeric-string', caster: 'decimal'),

            // O mysqlnd devolve TINYINT(1) como int 0/1; o pdo_pgsql devolve bool de
            // verdade. Mesmo IR, dois valores de wire — daí o caster ser obrigatório.
            TypeName::Boolean => new PhpType('bool', 'bool', caster: 'bool'),

            // MySQL corta o espaço à direita do CHAR na leitura; o PostgreSQL preenche
            // até o tamanho. O caster faz os dois concordarem.
            TypeName::Char => new PhpType('string', 'string', caster: 'char'),

            TypeName::Varchar,
            TypeName::TinyText,
            TypeName::Text,
            TypeName::MediumText,
            TypeName::LongText => new PhpType('string', 'string', caster: 'string'),

            TypeName::Date,
            TypeName::DateTime,
            TypeName::Timestamp => $this->temporal('dateTime'),

            TypeName::TimestampTz => $this->temporal('dateTimeTz'),

            // TIME no MySQL é DURAÇÃO, de -838:59:59 a 838:59:59 — não hora do dia.
            // `DateTimeImmutable` não representa isso, e '-05:30:00' seria lido como
            // fuso horário. String é a resposta honesta.
            TypeName::Time => new PhpType('string', 'string', caster: 'string'),

            // Decodificado, e `mixed` e não `array`: '42', '"x"' e 'null' são
            // documentos JSON válidos que não viram array. Anotar `array` produziria
            // TypeError com dado real.
            TypeName::Json, TypeName::Jsonb => new PhpType('mixed', 'mixed', caster: 'json'),

            TypeName::Uuid => new PhpType('string', 'lowercase-string', caster: 'string'),

            TypeName::Binary,
            TypeName::VarBinary,
            TypeName::TinyBlob,
            TypeName::Blob,
            TypeName::MediumBlob,
            TypeName::LongBlob => new PhpType('string', 'string', caster: 'string'),

            // O pdo_pgsql entrega bytea como RESOURCE de stream, não string.
            TypeName::Bytea => new PhpType('string', 'string', caster: 'binary'),

            // Sem classe de enum informada o tipo é o valor cru. Quem gera os DTOs
            // troca por `asClass()` depois, porque só lá se sabe o nome da classe.
            TypeName::Enum => new PhpType('string', $this->enumDocblock($type), caster: 'string'),
        };
    }

    /**
     * `UNSIGNED` refina só o docblock, nunca o tipo nativo: o PHP não tem inteiro
     * sem sinal, e o refinamento é grátis para o analisador.
     */
    private function integerDocblock(TypeSpec $type): string
    {
        return $type->unsigned ? 'int<0, max>' : 'int';
    }

    /**
     * O conjunto de valores vira uma união literal no docblock. É o que dá ao
     * analisador a chance de recusar `'activ'` antes de rodar, mesmo quando a coluna
     * não vira enum PHP.
     */
    private function enumDocblock(TypeSpec $type): string
    {
        if ($type->values === null || $type->values === []) {
            return 'string';
        }

        return implode('|', array_map(
            static fn (string $value): string => "'" . str_replace("'", "\\'", $value) . "'",
            $type->values,
        ));
    }

    private function temporal(string $caster): PhpType
    {
        if ($this->options->temporalAsString) {
            return new PhpType('string', 'string', caster: 'string');
        }

        return new PhpType(
            'DateTimeImmutable',
            'DateTimeImmutable',
            import: \DateTimeImmutable::class,
            caster: $caster,
        );
    }
}
