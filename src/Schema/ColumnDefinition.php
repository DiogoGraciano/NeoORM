<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Schema;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\Value\DefaultValue;

/**
 * Uma coluna, sem uma linha de SQL dentro.
 *
 * O objeto antigo carregava simultaneamente o dado e o SQL já cozido
 * (`null` = "NOT NULL", `comment` = "COMMENT 'x'", `columnSql` = a linha
 * inteira). Isso é o que tornava impossível gerar dois dialetos a partir da
 * mesma definição, e é a razão de o PostgreSQL ter perdido comentários e
 * tamanhos: o SQL do MySQL era montado na construção e o driver pgsql
 * simplesmente ignorava os pedaços que não sabia usar.
 *
 * Aqui `comment` é o texto do comentário, não um fragmento de SQL.
 */
final readonly class ColumnDefinition implements NamedDefinition
{
    public string $name;

    public DefaultValue $default;

    public ?string $comment;

    public ?string $collation;

    public function __construct(
        string $name,
        public TypeSpec $type,
        public bool $notNull = false,
        public bool $autoIncrement = false,
        ?DefaultValue $default = null,
        ?string $comment = null,
        ?string $collation = null,
    ) {
        // A validação mora no construtor, não num factory: assim não existe
        // caminho que produza uma coluna com nome inválido.
        $this->name = IdentifierValidator::normalize($name, 'Nome de coluna');
        $this->default = $default ?? DefaultValue::none();
        $this->comment = ($comment === null || $comment === '') ? null : $comment;
        $this->collation = ($collation === null || $collation === '') ? null : $collation;
    }

    public function withName(string $name): self
    {
        return new self(
            name: $name,
            type: $this->type,
            notNull: $this->notNull,
            autoIncrement: $this->autoIncrement,
            default: $this->default,
            comment: $this->comment,
            collation: $this->collation,
        );
    }

    public function withType(TypeSpec $type): self
    {
        return new self(
            name: $this->name,
            type: $type,
            notNull: $this->notNull,
            autoIncrement: $this->autoIncrement,
            default: $this->default,
            comment: $this->comment,
            collation: $this->collation,
        );
    }

    public function withAutoIncrement(bool $autoIncrement): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            notNull: $this->notNull,
            autoIncrement: $autoIncrement,
            default: $this->default,
            comment: $this->comment,
            collation: $this->collation,
        );
    }

    public function withNotNull(bool $notNull): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            notNull: $notNull,
            autoIncrement: $this->autoIncrement,
            default: $this->default,
            comment: $this->comment,
            collation: $this->collation,
        );
    }

    public function withDefault(DefaultValue $default): self
    {
        return new self(
            name: $this->name,
            type: $this->type,
            notNull: $this->notNull,
            autoIncrement: $this->autoIncrement,
            default: $default,
            comment: $this->comment,
            collation: $this->collation,
        );
    }

    public function equals(self $other): bool
    {
        return $this->name === $other->name
            && $this->type->equals($other->type)
            && $this->notNull === $other->notNull
            && $this->autoIncrement === $other->autoIncrement
            && $this->default->equals($other->default)
            && $this->comment === $other->comment
            && $this->collation === $other->collation;
    }

    /**
     * @return array<string,mixed>
     */
    public function toArray(): array
    {
        // Chaves em ordem alfabética: é o que mantém o JSON do snapshot
        // byte-estável entre execuções e entre máquinas.
        return [
            'autoIncrement' => $this->autoIncrement,
            'collation' => $this->collation,
            'comment' => $this->comment,
            'default' => $this->default->toArray(),
            'name' => $this->name,
            'notNull' => $this->notNull,
            'type' => $this->type->toArray(),
        ];
    }

    /**
     * Forma para COMPARAR. Ver DefaultValue::toComparableArray().
     *
     * @return array<string,mixed>
     */
    public function toComparableArray(): array
    {
        $data = $this->toArray();
        $data['default'] = $this->default->toComparableArray();

        return $data;
    }

    /**
     * @param array<string,mixed> $data
     */
    public static function fromArray(array $data): self
    {
        /** @var array<string,mixed>|null $default */
        $default = $data['default'] ?? null;

        return new self(
            name: (string) $data['name'],
            type: TypeSpec::fromArray((array) $data['type']),
            notNull: (bool) ($data['notNull'] ?? false),
            autoIncrement: (bool) ($data['autoIncrement'] ?? false),
            default: DefaultValue::fromArray($default),
            comment: isset($data['comment']) ? (string) $data['comment'] : null,
            collation: isset($data['collation']) ? (string) $data['collation'] : null,
        );
    }
}
