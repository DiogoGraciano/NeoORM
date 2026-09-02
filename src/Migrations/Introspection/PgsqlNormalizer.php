<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Introspection;

use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\Value\DefaultValue;

/**
 * Traduz o que o catálogo do PostgreSQL devolve para o IR.
 *
 * Como no lado do MySQL, é aqui que a normalização mora — nunca num teste.
 */
final class PgsqlNormalizer
{
    private function __construct()
    {
    }

    /**
     * A entrada é `format_type(atttypid, atttypmod)`, que devolve a grafia do padrão SQL
     * com a precisão já embutida: `character varying(120)`, `numeric(10,2)`,
     * `timestamp without time zone`.
     *
     * `TypeName::fromAlias()` conhece essas grafias, então o trabalho aqui é só separar o
     * modificador entre parênteses do nome — que pode ter mais de uma palavra.
     */
    public static function type(string $formatted): TypeSpec
    {
        $normalized = trim(strtolower($formatted));

        // Arrays não têm representação no IR. Falhar é melhor que reportar o tipo base e
        // gerar uma migração que troca `integer[]` por `integer`.
        if (str_ends_with($normalized, '[]')) {
            throw new \Diogodg\Neoorm\Migrations\Exception\MigrationException(
                "Coluna de tipo array ('{$formatted}') não é representável no IR desta biblioteca. "
                . 'Declare a coluna como JSONB, ou exclua a tabela do conjunto de models.',
            );
        }

        return TypeSpec::parse($normalized);
    }

    /**
     * O default vem anotado com o tipo: `'scheduled'::character varying`, `0`,
     * `now()`, `nextval('city_id_seq'::regclass)`.
     *
     * `nextval` devolve `null` — quem chama trata isso como auto incremento e descarta o
     * default. É o que faz uma coluna `serial` (que no catálogo é `integer` + default
     * `nextval`) fechar o round-trip contra uma coluna declarada com auto incremento: se
     * o default sobrevivesse, o schema declarado divergiria para sempre.
     */
    public static function default(?string $raw, TypeSpec $type): ?DefaultValue
    {
        if ($raw === null) {
            // Ausência de default. Como no MySQL, não existe estado "DEFAULT NULL"
            // distinguível: o PostgreSQL descarta a cláusula ao armazená-la.
            return DefaultValue::none();
        }

        $value = trim($raw);

        if (preg_match('/^nextval\(/i', $value) === 1) {
            return null;
        }

        // Remove a anotação de tipo: `::character varying`, `::text`, `::numeric`.
        $unannotated = trim((string) preg_replace(
            '/::\s*[a-z_][a-z0-9_]*(?:\s+(?:varying|precision|with|without|time|zone))*(?:\(\d+(?:,\d+)?\))?/i',
            '',
            $value,
        ));

        // O PostgreSQL parentiza número negativo: um default `-42` volta como `(-42)`.
        if (preg_match('/^\\((-?[\\d.]+)\\)$/', $unannotated, $parenthesized) === 1) {
            $unannotated = $parenthesized[1];
        }

        if (preg_match('/^(current_timestamp|now\\(\\))$/i', $unannotated) === 1) {
            return DefaultValue::expression('CURRENT_TIMESTAMP');
        }

        if (preg_match('/^(current_date|current_time)$/i', $unannotated) === 1) {
            return DefaultValue::expression(strtoupper($unannotated));
        }

        // As aspas saem ANTES de decidir o tipo do literal, e não depois.
        //
        // O PostgreSQL cita default numérico: um `DEFAULT -42` numa coluna INT volta como
        // `'-42'::integer`. Tratar "está entre aspas" como "é texto" faria esse default
        // virar a string `-42`, e string contra inteiro é divergência de kind — a coluna
        // geraria migração em toda execução, para sempre.
        $quoted = preg_match("/^'(.*)'$/s", $unannotated, $matches) === 1;

        if ($quoted) {
            $unannotated = str_replace("''", "'", $matches[1]);
        }

        if ($type->name === TypeName::Boolean) {
            $lowered = strtolower($unannotated);

            if ($lowered === 'true' || $lowered === 'false') {
                return DefaultValue::literal($lowered === 'true');
            }
        }

        if ($type->name->isIntegral() && preg_match('/^-?\\d+$/', $unannotated) === 1) {
            return DefaultValue::literal((int) $unannotated);
        }

        if ($type->isNumeric() && is_numeric($unannotated)) {
            return DefaultValue::literal((float) $unannotated);
        }

        if ($quoted) {
            return DefaultValue::literal($unannotated);
        }

        // Sobrou expressão: função, cast composto, aritmética.
        return DefaultValue::expression($unannotated);
    }

    /**
     * `btree` é o método padrão e não é declarado por ninguém.
     */
    public static function indexMethod(string $method): ?string
    {
        return strtolower(trim($method)) === 'btree' ? null : strtoupper(trim($method));
    }

    /**
     * Extrai a expressão de um `pg_get_constraintdef` de CHECK.
     *
     * A entrada é `CHECK ((price >= 0))`. O PostgreSQL reformata a expressão ao
     * armazená-la — parentiza cada referência de coluna e anota os dois lados com
     * `::tipo` —, de forma não trivialmente reversível. É por isso que divergência de
     * CHECK é reportada como advisória e nunca vira ALTER automático: gerar DDL a partir
     * de uma comparação com falso positivo conhecido seria pior que não gerar.
     */
    public static function checkExpression(string $definition): string
    {
        $expression = trim($definition);

        if (preg_match('/^CHECK\s*\((.*)\)$/is', $expression, $matches) === 1) {
            $expression = trim($matches[1]);
        }

        return $expression;
    }

    /**
     * Colunas de uma lista `{1,2}` de `conkey::int[]` traduzidas para nomes.
     *
     * @param array<int,string> $columnsByAttnum
     * @return list<string>
     */
    public static function columnList(?string $rawArray, array $columnsByAttnum): array
    {
        if ($rawArray === null || trim($rawArray) === '') {
            return [];
        }

        $numbers = array_filter(explode(',', trim($rawArray, '{}')), static fn (string $n): bool => $n !== '');

        $columns = [];

        foreach ($numbers as $number) {
            $name = $columnsByAttnum[(int) $number] ?? null;

            if ($name !== null) {
                $columns[] = $name;
            }
        }

        return $columns;
    }
}
