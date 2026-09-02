<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Naming;

use Diogodg\Neoorm\Schema\Exception\InvalidIdentifierException;

/**
 * A única definição de "nome válido" da biblioteca.
 *
 * Substitui as seis cópias de validateName() espalhadas pelos drivers antigos,
 * cada uma com o mesmo regex e mensagens de erro diferentes. Ter uma cópia só
 * importa porque este é o ponto onde configuração vira DDL: nomes não podem ser
 * parametrizados, então validar aqui é o que impede que virem injeção.
 */
final class IdentifierValidator
{
    public const PATTERN = '/^[a-zA-Z_][a-zA-Z0-9_]*$/';

    private function __construct()
    {
    }

    public static function isValid(string $identifier): bool
    {
        return preg_match(self::PATTERN, $identifier) === 1;
    }

    /**
     * Valida e normaliza para minúsculas.
     *
     * A normalização não é cosmética: o PostgreSQL dobra identificadores não
     * citados para minúsculas, então `MinhaTabela` e `minhatabela` são a mesma
     * tabela lá e tabelas diferentes no MySQL com lower_case_table_names=0.
     * Fixar minúsculas no IR faz o round-trip fechar nos dois.
     */
    public static function normalize(string $identifier, string $context = 'identificador'): string
    {
        $normalized = strtolower(trim($identifier));

        if (!self::isValid($normalized)) {
            throw new InvalidIdentifierException(
                "{$context} inválido: '{$identifier}'. Use apenas letras, dígitos e underscore, começando por letra ou underscore.",
            );
        }

        return $normalized;
    }
}
