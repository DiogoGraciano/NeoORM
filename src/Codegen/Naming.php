<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

/**
 * Nome de tabela e de coluna para nome PHP.
 *
 * A regra das colunas é: **nenhuma**. A propriedade tem o nome da coluna, sem
 * conversão. `IdentifierValidator` já garante `^[a-z_][a-z0-9_]*$`, que é sempre um
 * nome de propriedade válido.
 *
 * Camelizar seria o esperado em PHP moderno e está errado aqui por dois motivos. Não é
 * injetivo: o IR minuscula tudo, então `user_id` e `userId` são a MESMA coluna, e
 * `is_active` colidiria com `isactive`. E criaria duas grafias para uma coisa só — a
 * coluna é `created_at` no schema, no builder e no SQL, e só no DTO seria `createdAt`.
 */
final class Naming
{
    private function __construct()
    {
    }

    /**
     * `schedule_user` => `ScheduleUser`.
     */
    public static function studly(string $identifier): string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $identifier, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            throw new CodegenException("Não consegui derivar um nome de classe de '{$identifier}'.");
        }

        return implode('', array_map(ucfirst(...), $parts));
    }

    public static function rowClass(string $table): string
    {
        return self::studly($table) . 'Row';
    }

    public static function insertClass(string $table): string
    {
        return self::studly($table) . 'Insert';
    }

    public static function tableClass(string $table): string
    {
        return self::studly($table) . 'Table';
    }

    public static function enumClass(string $table, string $column): string
    {
        return self::studly($table) . self::studly($column);
    }

    /**
     * Método do registry: `schedule_user` => `scheduleUser`.
     *
     * Aqui camelizar é seguro porque o destino é um nome de método derivado do nome da
     * tabela, e nomes de tabela num mesmo schema já são únicos entre si.
     */
    public static function registryMethod(string $table): string
    {
        return lcfirst(self::studly($table));
    }

    /**
     * Nome do case de enum a partir do valor.
     *
     * Devolve null quando o valor não rende identificador válido. O emissor então cai
     * para `string` naquela coluna, com comentário explicando — melhor que emitir um
     * arquivo que não parseia.
     */
    public static function enumCase(string $value): ?string
    {
        $parts = preg_split('/[^a-zA-Z0-9]+/', $value, -1, PREG_SPLIT_NO_EMPTY);

        if ($parts === false || $parts === []) {
            return null;
        }

        $name = implode('', array_map(ucfirst(...), $parts));

        // Identificador PHP não começa com dígito. `_12` é feio e é válido, que é o que
        // importa num nome derivado de dado.
        return ctype_digit($name[0]) ? '_' . $name : $name;
    }

    /**
     * Os cases de um conjunto de valores, ou null se algum colidir ou não render nome.
     *
     * Colisão acontece de verdade: `in-progress` e `in_progress` produzem o mesmo
     * `InProgress`. Recusar o conjunto inteiro é melhor que desempatar com sufixo
     * numérico, que geraria `InProgress2` sem ninguém saber qual valor é qual.
     *
     * @param list<string> $values
     * @return array<string,string>|null nome do case => valor
     */
    public static function enumCases(array $values): ?array
    {
        $cases = [];

        foreach ($values as $value) {
            $name = self::enumCase($value);

            if ($name === null || isset($cases[$name])) {
                return null;
            }

            $cases[$name] = $value;
        }

        return $cases === [] ? null : $cases;
    }
}
