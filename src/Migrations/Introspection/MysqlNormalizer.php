<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Introspection;

use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\Value\DefaultValue;

/**
 * Traduz o que o catálogo do MySQL devolve para o IR.
 *
 * Este arquivo é onde a normalização vive, e é uma regra do projeto que ela viva aqui e
 * em nenhum teste. Cada vez que um teste massageia um valor antes de comparar, ele está
 * escondendo uma diferença que o usuário vai receber como migração espúria — foi
 * literalmente assim que o sistema antigo passou a gerar um ALTER de collation em toda
 * execução, sem nunca convergir.
 */
final class MysqlNormalizer
{
    private function __construct()
    {
    }

    /**
     * `COLUMN_TYPE` é a fonte, não `DATA_TYPE`: só ele traz tamanho, precisão,
     * `unsigned` e os valores do ENUM.
     *
     * O caso que exige atenção é `tinyint(1)`. O MySQL não tem BOOLEAN — é um apelido de
     * `TINYINT(1)` —, então os dois são indistinguíveis no catálogo e algum dos dois
     * tem que ganhar. Ganha BOOLEAN, porque é o que os models querem dizer quando
     * escrevem BOOLEAN, e porque a largura de exibição de tipo integral não chega ao IR
     * por nenhum outro caminho: um `TINYINT` declarado renderiza `TINYINT`, sem largura,
     * e volta como TINYINT. O custo é não haver como expressar um `TINYINT(1)` genuíno.
     */
    public static function type(string $columnType): TypeSpec
    {
        $normalized = trim(strtolower($columnType));

        if ($normalized === 'tinyint(1)' || $normalized === 'tinyint(1) unsigned') {
            return new TypeSpec(TypeName::Boolean);
        }

        // O catálogo escreve os atributos depois do tipo, em minúsculas: `int unsigned`,
        // `int(10) unsigned zerofill`. `TypeSpec::parse` entende UNSIGNED no fim; os
        // outros atributos não têm representação no IR e são descartados.
        //
        // Note que o zerofill traz a largura de exibição de volta com ele (o MySQL 8
        // reporta `int(10) unsigned zerofill` onde reportaria só `int`). Quem lida com
        // isso é o `TypeSpec`, que descarta largura de tipo integral.
        $normalized = (string) preg_replace('/\s+zerofill\b/', '', $normalized);

        return TypeSpec::parse($normalized);
    }

    /**
     * `COLUMN_DEFAULT` vem sempre como string, e o mesmo campo carrega três coisas
     * diferentes: literal, expressão e ausência.
     *
     * A ordem das regras importa. `EXTRA` é consultado primeiro porque a partir do
     * MySQL 8.0.13 um default de expressão é marcado lá como `DEFAULT_GENERATED`, e é a
     * única forma confiável de distinguir a expressão `CURRENT_TIMESTAMP` da string
     * literal `'CURRENT_TIMESTAMP'`.
     *
     * Sobre NULL: um `COLUMN_DEFAULT` nulo é reportado como AUSÊNCIA de default, nunca
     * como `DEFAULT NULL`. Não é escolha — o MySQL guarda exatamente o mesmo estado para
     * `x INT NULL` e `x INT NULL DEFAULT NULL`, e inventar a distinção aqui faria uma
     * das duas formas divergir para sempre. O `SchemaValidator` recusa `setDefault(null)`
     * numa coluna nullable justamente por isso.
     */
    public static function default(mixed $raw, string $extra, string $columnType): DefaultValue
    {
        if ($raw === null) {
            return DefaultValue::none();
        }

        $value = (string) $raw;

        if (str_contains(strtolower($extra), 'default_generated')) {
            return DefaultValue::expression($value);
        }

        // Servidores anteriores ao 8.0.13 não marcam EXTRA, e devolvem a função
        // temporal como texto puro.
        //
        // O tipo da coluna é parte da regra, não decoração. Sem ele o fallback desfaria
        // exatamente a distinção que o EXTRA existe para fazer: um
        // `VARCHAR(40) DEFAULT 'CURRENT_TIMESTAMP'` chega aqui com EXTRA vazio e valor
        // `CURRENT_TIMESTAMP` — provado num MySQL 8.0 de verdade —, e seria lido como
        // expressão. A coluna passaria a divergir do model em toda comparação, sem que
        // migração nenhuma pudesse resolver. Numa coluna temporal o texto puro só pode
        // ser a função; numa coluna de texto só pode ser o literal.
        if (
            self::type($columnType)->name->isTemporal()
            && preg_match('/^(current_timestamp|now)(\(\d*\))?$/i', trim($value)) === 1
        ) {
            return DefaultValue::expression('CURRENT_TIMESTAMP');
        }

        return self::literal($value, $columnType);
    }

    /**
     * Devolve o literal com o tipo PHP que o snapshot deve guardar.
     *
     * `DefaultValue::equals()` já compara literal numérico por valor, então uma string
     * `"0"` casaria com um inteiro `0` de qualquer forma. Converter aqui é sobre o
     * ARQUIVO: um snapshot introspectado com `"price": "0"` onde o declarado tem `0` é
     * um diff visual em code review que não corresponde a mudança nenhuma.
     */
    private static function literal(string $value, string $columnType): DefaultValue
    {
        $type = self::type($columnType);

        if ($type->name === TypeName::Boolean) {
            return DefaultValue::literal($value === '1' || strtolower($value) === 'true');
        }

        if ($type->name->isIntegral() && preg_match('/^-?\d+$/', $value) === 1) {
            return DefaultValue::literal((int) $value);
        }

        if ($type->isNumeric() && is_numeric($value)) {
            return DefaultValue::literal((float) $value);
        }

        return DefaultValue::literal($value);
    }

    /**
     * `BTREE` é o método padrão e não é declarado por ninguém.
     *
     * Reportá-lo faria todo índice declarado sem método divergir do banco.
     */
    public static function indexMethod(string $method): ?string
    {
        return strtoupper(trim($method)) === 'BTREE' ? null : strtoupper(trim($method));
    }

    /**
     * O MySQL devolve a expressão do CHECK entre parênteses e com os identificadores
     * citados com crase. `CheckConstraintDefinition` normaliza o resto na comparação.
     */
    public static function checkExpression(string $clause): string
    {
        return str_replace('`', '', trim($clause));
    }
}
