<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema\Type;

use Diogodg\Neoorm\Schema\Exception\InvalidTypeException;

/**
 * Um tipo de coluna completo e imutável.
 *
 * Note o que NÃO está aqui: nada de SQL. `renderType()` é responsabilidade do
 * dialeto. Este objeto só diz *o que* a coluna é; como se escreve isso em
 * MySQL ou PostgreSQL é outra camada. Foi a mistura das duas coisas que fez o
 * sistema antigo perder o tamanho das colunas no PostgreSQL.
 */
final readonly class TypeSpec
{
    public ?int $length;

    /**
     * @param list<string>|null $values membros do ENUM
     */
    public function __construct(
        public TypeName $name,
        ?int $length = null,
        public ?int $precision = null,
        public ?int $scale = null,
        public ?array $values = null,
        public bool $unsigned = false,
    ) {
        if ($length !== null && $length < 0) {
            throw new InvalidTypeException("Tamanho negativo para {$name->value}: {$length}");
        }

        if ($precision !== null && $precision < 0) {
            throw new InvalidTypeException("Precisão negativa para {$name->value}: {$precision}");
        }

        if ($scale !== null && $scale < 0) {
            throw new InvalidTypeException("Escala negativa para {$name->value}: {$scale}");
        }

        if ($precision !== null && $scale !== null && $scale > $precision) {
            throw new InvalidTypeException(
                "Escala maior que a precisão em {$name->value}({$precision},{$scale})",
            );
        }

        if ($name === TypeName::Enum && ($values === null || $values === [])) {
            throw new InvalidTypeException('ENUM exige ao menos um valor');
        }

        // Largura de exibição de tipo integral não faz parte da identidade do
        // tipo: `INT(11)` e `INT` são o mesmo INT, e o MySQL 8.0.19 parou de
        // reportar a largura no catálogo. Guardá-la faria todo model que escreve
        // `INT(11)` divergir para sempre do banco introspectado — e como a
        // divergência é de representação, não de semântica, nenhuma migração
        // conseguiria resolvê-la.
        //
        // O efeito colateral é o que faz TINYINT e BOOLEAN conviverem no MySQL:
        // BOOLEAN renderiza `TINYINT(1)` e a introspecção o traz de volta como
        // BOOLEAN, enquanto TINYINT renderiza `TINYINT` e volta como TINYINT.
        // São dois tipos distinguíveis no catálogo justamente porque a largura
        // nunca chega aqui por outro caminho.
        $this->length = $name->isIntegral() ? null : $length;
    }

    /**
     * Interpreta o par (tipo, tamanho) do DSL dos models.
     *
     * O DSL aceita `("VARCHAR", 120)`, `("DECIMAL", "10,2")` e `("INT", null)`,
     * além do tamanho embutido no próprio tipo (`"VARCHAR(120)"`), que é como
     * os catálogos dos bancos costumam devolver.
     */
    public static function parse(string $type, string|int|null $size = null): self
    {
        $raw = trim($type);
        $inlineValues = null;
        $unsigned = false;

        // UNSIGNED sai ANTES do modificador, não depois.
        //
        // O catálogo do MySQL escreve o atributo depois dos parênteses — um
        // `INT UNSIGNED ZEROFILL` é reportado como `int(10) unsigned` —, e a extração
        // do modificador exige que o `)` feche a string. Procurando o modificador
        // primeiro, `int(10) unsigned` não casava com nenhuma das duas regras e o tipo
        // chegava a `fromAlias()` como a string `int(10)`, que é desconhecida: uma coluna
        // assim derrubava a introspecção com "Tipo desconhecido".
        //
        // A troca é segura porque o `$` da âncora impede o casamento dentro de
        // parênteses: um `ENUM('a unsigned')` termina em `')`, nunca em ` UNSIGNED`.
        if (preg_match('/\s+UNSIGNED$/i', $raw) === 1) {
            $unsigned = true;
            $raw = (string) preg_replace('/\s+UNSIGNED$/i', '', $raw);
        }

        if (preg_match('/^(.*?)\s*\(([^)]*)\)\s*$/', $raw, $matches) === 1) {
            $raw = trim($matches[1]);

            if ($size === null || $size === '') {
                $size = trim($matches[2]);
            }

            $inlineValues = trim($matches[2]);
        }

        $name = TypeName::fromAlias($raw);

        if ($name === null) {
            throw new InvalidTypeException("Tipo desconhecido: '{$type}'");
        }

        if ($name === TypeName::Enum) {
            return new self($name, values: self::parseEnumValues((string) ($inlineValues ?? $size)), unsigned: $unsigned);
        }

        if ($size === null || $size === '') {
            return new self($name, unsigned: $unsigned);
        }

        $size = (string) $size;

        if (str_contains($size, ',')) {
            [$precision, $scale] = array_map(static fn (string $p): string => trim($p), explode(',', $size, 2));

            if (!ctype_digit($precision) || !ctype_digit($scale)) {
                throw new InvalidTypeException("Precisão/escala inválidas para {$name->value}: '{$size}'");
            }

            return new self($name, precision: (int) $precision, scale: (int) $scale, unsigned: $unsigned);
        }

        if (!ctype_digit(trim($size))) {
            throw new InvalidTypeException("Tamanho inválido para {$name->value}: '{$size}'");
        }

        if ($name->acceptsPrecision()) {
            return new self($name, precision: (int) $size, scale: 0, unsigned: $unsigned);
        }

        return new self($name, length: (int) $size, unsigned: $unsigned);
    }

    /**
     * Lê a lista de membros de um ENUM.
     *
     * Um scanner e não um `explode(',')`: um membro pode conter vírgula
     * (`ENUM('Sim, senhor','Não')`) e pode conter aspa, escrita dobrada à moda do
     * SQL (`ENUM('d''água')`). Partir na vírgula quebrava o primeiro caso e
     * devolvia `d''água` no segundo — que, ao ser citado de novo na geração de
     * SQL, virava `'d''''água'` e mudava o valor. Membro vazio (`''`) é um membro
     * legítimo no MySQL e é preservado.
     *
     * @return list<string>
     */
    private static function parseEnumValues(string $raw): array
    {
        $values = [];
        $length = strlen($raw);
        $position = 0;

        while ($position < $length) {
            while ($position < $length && ($raw[$position] === ',' || ctype_space($raw[$position]))) {
                $position++;
            }

            if ($position >= $length) {
                break;
            }

            if ($raw[$position] === "'" || $raw[$position] === '"') {
                $quote = $raw[$position];
                $position++;
                $value = '';

                while ($position < $length) {
                    if ($raw[$position] !== $quote) {
                        $value .= $raw[$position];
                        $position++;
                        continue;
                    }

                    if ($position + 1 < $length && $raw[$position + 1] === $quote) {
                        $value .= $quote;
                        $position += 2;
                        continue;
                    }

                    $position++;
                    break;
                }

                $values[] = $value;
                continue;
            }

            // Membro sem citação: vai até a próxima vírgula.
            $value = '';

            while ($position < $length && $raw[$position] !== ',') {
                $value .= $raw[$position];
                $position++;
            }

            $value = trim($value);

            if ($value !== '') {
                $values[] = $value;
            }
        }

        return $values;
    }

    /**
     * Chave canônica de comparação. Dois tipos são o mesmo tipo se, e somente
     * se, as assinaturas forem iguais — é o que o differ compara.
     */
    public function signature(): string
    {
        $signature = $this->name->value;

        if ($this->values !== null) {
            $signature .= '(' . implode(',', $this->values) . ')';
        } elseif ($this->precision !== null) {
            $signature .= '(' . $this->precision . ',' . ($this->scale ?? 0) . ')';
        } elseif ($this->length !== null) {
            $signature .= '(' . $this->length . ')';
        }

        return $signature . ($this->unsigned ? ' UNSIGNED' : '');
    }

    public function equals(self $other): bool
    {
        return $this->signature() === $other->signature();
    }

    public function isIntegral(): bool
    {
        return $this->name->isIntegral();
    }

    public function isNumeric(): bool
    {
        return $this->name->isNumeric();
    }

    public function isTextual(): bool
    {
        return $this->name->isTextual();
    }

    public function isBoolean(): bool
    {
        return $this->name === TypeName::Boolean;
    }

    /**
     * Forma serializável. Campos nulos são omitidos, em ordem alfabética de
     * chave: é isso que faz `INT` virar `{"name":"INT"}` e não um objeto cheio
     * de nulos, e é o que mantém o snapshot byte-estável.
     *
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        $data = ['name' => $this->name->value];

        if ($this->length !== null) {
            $data['length'] = $this->length;
        }

        if ($this->precision !== null) {
            $data['precision'] = $this->precision;
        }

        if ($this->scale !== null) {
            $data['scale'] = $this->scale;
        }

        if ($this->unsigned) {
            $data['unsigned'] = true;
        }

        if ($this->values !== null) {
            $data['values'] = $this->values;
        }

        ksort($data, SORT_STRING);

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $name = TypeName::tryFrom((string) ($data['name'] ?? ''));

        if ($name === null) {
            throw new InvalidTypeException("Tipo desconhecido no snapshot: '" . ($data['name'] ?? '') . "'");
        }

        /** @var list<string>|null $values */
        $values = isset($data['values']) ? array_values((array) $data['values']) : null;

        return new self(
            name: $name,
            length: isset($data['length']) ? (int) $data['length'] : null,
            precision: isset($data['precision']) ? (int) $data['precision'] : null,
            scale: isset($data['scale']) ? (int) $data['scale'] : null,
            values: $values,
            unsigned: (bool) ($data['unsigned'] ?? false),
        );
    }
}
