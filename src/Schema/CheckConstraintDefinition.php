<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Exception\SchemaException;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Restrição CHECK.
 *
 * A expressão é tratada como string opaca. Os bancos reformatam CHECKs ao
 * armazená-los — o PostgreSQL devolve `((status)::text = 'a'::text)` para o que
 * foi escrito como `status = 'a'` — e reconstruir a forma original exigiria um
 * parser de SQL. Por isso a comparação é feita sobre uma forma normalizada
 * fraca, e divergência de CHECK é reportada como aviso, nunca vira ALTER
 * automático: gerar DDL a partir de uma comparação que sabidamente tem falso
 * positivo seria pior que não gerar.
 */
final readonly class CheckConstraintDefinition implements NamedDefinition
{
    public string $name;

    public string $expression;

    public function __construct(string $name, string $expression)
    {
        if (trim($expression) === '') {
            throw new SchemaException("Restrição CHECK '{$name}' não pode ter expressão vazia.");
        }

        $this->name = IdentifierValidator::normalize($name, 'Nome de restrição CHECK');
        $this->expression = trim($expression);
    }

    /**
     * Forma comparável — melhor esforço, deliberadamente.
     *
     * Cobre o que o PostgreSQL faz na prática com um CHECK simples: envolver
     * tudo em parênteses, parentizar cada referência de coluna e anotar os dois
     * lados com `::tipo`. Não é um parser de SQL e não pretende ser; é por isso
     * que divergência de CHECK vira aviso e nunca ALTER automático. Gerar DDL a
     * partir de uma comparação com falso positivo conhecido seria pior que não
     * gerar nada.
     */
    public function normalizedExpression(): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $this->expression));

        // Casts `::tipo`. Os nomes de tipo do PostgreSQL podem ter mais de uma
        // palavra ("character varying", "timestamp without time zone"), então a
        // continuação é restrita a um conjunto conhecido — um `[a-z ]*` guloso
        // engoliria o operador que vem depois.
        $normalized = (string) preg_replace(
            '/::\s*[a-zA-Z_][a-zA-Z0-9_]*(?:\s+(?:varying|precision|with|without|time|zone))*/i',
            '',
            $normalized,
        );

        // Parênteses redundantes em torno de um termo sozinho: o PostgreSQL
        // escreve `(status)` e `(0)` onde o autor escreveu `status` e `0`.
        $normalized = (string) preg_replace(
            "/\(\s*([a-zA-Z_][a-zA-Z0-9_]*|-?\d+(?:\.\d+)?|'[^']*')\s*\)/",
            '$1',
            $normalized,
        );

        $normalized = self::stripOuterParentheses($normalized);

        return strtolower(trim((string) preg_replace('/\s+/', ' ', $normalized)));
    }

    /**
     * Remove parênteses que envolvem a expressão inteira, e só esses: em
     * `(a) or (b)` o primeiro parêntese não fecha no fim, e cortar as pontas
     * produziria `a) or (b`.
     */
    private static function stripOuterParentheses(string $expression): string
    {
        while (strlen($expression) > 1 && $expression[0] === '(' && str_ends_with($expression, ')')) {
            $depth = 0;
            $closesAtEnd = false;
            $length = strlen($expression);

            for ($i = 0; $i < $length; $i++) {
                if ($expression[$i] === '(') {
                    $depth++;
                } elseif ($expression[$i] === ')') {
                    $depth--;

                    if ($depth === 0) {
                        $closesAtEnd = $i === $length - 1;
                        break;
                    }
                }
            }

            if (!$closesAtEnd) {
                break;
            }

            $expression = trim(substr($expression, 1, -1));
        }

        return $expression;
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->normalizedExpression() === $other->normalizedExpression();
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        return ['expression' => $this->expression, 'name' => $this->name];
    }

    /**
     * Forma para COMPARAR, que não é a forma para GUARDAR.
     *
     * O snapshot guarda a expressão como foi escrita, porque é ela que vai para o
     * `CHECK (...)` gerado. A comparação usa a forma normalizada, que é minúscula e
     * perde informação — usá-la no arquivo transformaria `IN ('Novo')` em
     * `in ('novo')` e mudaria o significado.
     *
     * @return array<string,mixed>
     */
    public function toComparableArray(): array
    {
        return ['expression' => $this->normalizedExpression(), 'name' => $this->name];
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        return new self((string) $data['name'], (string) $data['expression']);
    }
}
