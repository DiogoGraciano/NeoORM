<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Value;

/**
 * O valor padrão de uma coluna, com a intenção explícita.
 *
 * Expressões são canonicalizadas na construção. Sem isso, `now()` vindo do
 * catálogo do PostgreSQL e `CURRENT_TIMESTAMP` declarado no model pareceriam
 * diferentes para sempre, e cada `generate` produziria uma migração espúria —
 * o modo de falha que mata a confiança no sistema inteiro.
 */
final readonly class DefaultValue
{
    private function __construct(
        public DefaultKind $kind,
        public string|int|float|bool|null $value = null,
    ) {
    }

    public static function none(): self
    {
        return new self(DefaultKind::None);
    }

    public static function null(): self
    {
        return new self(DefaultKind::Null);
    }

    public static function literal(string|int|float|bool $value): self
    {
        return new self(DefaultKind::Literal, $value);
    }

    public static function expression(string $sql): self
    {
        return new self(DefaultKind::Expression, self::canonicalizeExpression($sql));
    }

    /**
     * Reduz as grafias equivalentes a uma só.
     *
     * Deliberadamente conservador: só mexe no que é seguro (espaços, e o punhado
     * de funções sem argumento que os dois bancos escrevem de formas diferentes).
     * Uppercase indiscriminado quebraria expressões com literais de string.
     */
    private static function canonicalizeExpression(string $sql): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $sql));

        // Parênteses externos: o MySQL 8 embrulha defaults de expressão neles.
        while (
            strlen($normalized) > 1
            && $normalized[0] === '('
            && substr($normalized, -1) === ')'
            && self::parenthesesAreBalancedAround($normalized)
        ) {
            $normalized = trim(substr($normalized, 1, -1));
        }

        $equivalences = [
            '/^now\(\)$/i' => 'CURRENT_TIMESTAMP',
            '/^current_timestamp(\(\))?$/i' => 'CURRENT_TIMESTAMP',
            '/^current_date(\(\))?$/i' => 'CURRENT_DATE',
            '/^current_time(\(\))?$/i' => 'CURRENT_TIME',
            '/^localtimestamp(\(\))?$/i' => 'CURRENT_TIMESTAMP',
            '/^gen_random_uuid\(\)$/i' => 'gen_random_uuid()',
        ];

        foreach ($equivalences as $pattern => $canonical) {
            if (preg_match($pattern, $normalized) === 1) {
                return $canonical;
            }
        }

        return $normalized;
    }

    /**
     * Verifica se o primeiro parêntese fecha só no último caractere. Sem isso,
     * `(a) + (b)` perderia os parênteses das duas pontas e viraria `a) + (b`.
     */
    private static function parenthesesAreBalancedAround(string $sql): bool
    {
        $depth = 0;
        $length = strlen($sql);

        for ($i = 0; $i < $length; $i++) {
            if ($sql[$i] === '(') {
                $depth++;
            } elseif ($sql[$i] === ')') {
                $depth--;

                if ($depth === 0) {
                    return $i === $length - 1;
                }
            }
        }

        return false;
    }

    public function isNone(): bool
    {
        return $this->kind === DefaultKind::None;
    }

    public function isNull(): bool
    {
        return $this->kind === DefaultKind::Null;
    }

    public function isLiteral(): bool
    {
        return $this->kind === DefaultKind::Literal;
    }

    public function isExpression(): bool
    {
        return $this->kind === DefaultKind::Expression;
    }

    /**
     * Igualdade por intenção e valor.
     *
     * Literais numéricos são comparados sem tipo: o catálogo do MySQL devolve
     * todo default como string, então exigir que `0` e `"0"` fossem distintos
     * geraria drift falso em toda coluna numérica com default.
     */
    public function equals(self $other): bool
    {
        if ($this->kind !== $other->kind) {
            return false;
        }

        if ($this->kind !== DefaultKind::Literal) {
            return $this->value === $other->value;
        }

        if (is_bool($this->value) || is_bool($other->value)) {
            return (bool) $this->value === (bool) $other->value;
        }

        if (is_numeric($this->value) && is_numeric($other->value)) {
            return (float) $this->value === (float) $other->value;
        }

        return (string) $this->value === (string) $other->value;
    }

    /**
     * Forma serializável: `null` (JSON) para "sem DEFAULT", objeto para o
     * resto. Distinguir os dois no snapshot é o que permite ao differ saber a
     * diferença entre remover um default e defini-lo como NULL.
     *
     * @return array<string,mixed>|null
     */
    public function toArray(): ?array
    {
        if ($this->kind === DefaultKind::None) {
            return null;
        }

        if ($this->kind === DefaultKind::Null) {
            return ['kind' => DefaultKind::Null->value];
        }

        return ['kind' => $this->kind->value, 'value' => $this->value];
    }

    /**
     * Forma para COMPARAR, que não é a forma para GUARDAR.
     *
     * `toArray()` preserva o tipo PHP do literal, porque o arquivo de snapshot precisa
     * distinguir `1.0` de `1`. Mas a IGUALDADE é numérica: `equals()` compara literal
     * numérico por valor, justamente porque o catálogo do MySQL devolve todo default como
     * string e o do PostgreSQL devolve `0.00` onde o model escreveu `0`.
     *
     * Esta projeção alinha as duas coisas: quem compara arrays chega à mesma resposta que
     * `equals()` daria, em vez de acusar diferença entre um zero inteiro e um zero real.
     *
     * @return array<string,mixed>|null
     */
    public function toComparableArray(): ?array
    {
        $data = $this->toArray();

        if ($data === null || $this->kind !== DefaultKind::Literal) {
            return $data;
        }

        if (is_bool($this->value)) {
            $data['value'] = $this->value ? 'true' : 'false';

            return $data;
        }

        if (is_numeric($this->value)) {
            // Uma forma canônica só: `0`, `0.0` e `"0"` colapsam em `0`.
            $data['value'] = (string) (0 + $this->value);
        }

        return $data;
    }

    /**
     * @param array<string,mixed>|null $data
     */
    public static function fromArray(?array $data): self
    {
        if ($data === null) {
            return self::none();
        }

        $kind = DefaultKind::tryFrom((string) ($data['kind'] ?? '')) ?? DefaultKind::None;

        return match ($kind) {
            DefaultKind::None => self::none(),
            DefaultKind::Null => self::null(),
            DefaultKind::Expression => self::expression((string) ($data['value'] ?? '')),
            DefaultKind::Literal => self::literal(self::scalar($data['value'] ?? '')),
        };
    }

    private static function scalar(mixed $value): string|int|float|bool
    {
        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value)) {
            return $value;
        }

        // Chegar aqui significa que o JSON do snapshot trazia array, objeto ou null
        // onde deveria haver um literal. Vira string vazia em vez de lançar: o
        // desserializador é o caminho de leitura de um arquivo versionado, e falhar
        // nele deixaria o projeto sem conseguir sequer olhar o próprio histórico.
        return '';
    }
}
