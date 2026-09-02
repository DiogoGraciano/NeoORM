<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Definitions\Raw;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\Type\TypeName;
use Diogodg\Neoorm\Schema\Type\TypeSpec;
use Diogodg\Neoorm\Schema\Value\DefaultValue;

/**
 * Uma coluna, declarada pelo TIPO em vez de por uma string.
 *
 * `Col` não sabe o próprio nome: quem nomeia é a chave do mapa passado a
 * `Table::columns()`. Escrever o nome duas vezes — uma na chave e outra no
 * construtor — seria a única forma de os dois discordarem, e a `Column` antiga
 * ainda permitia declarar o tipo como texto livre (`'VARCAHR'`), erro que só
 * aparecia em `build()`.
 *
 * ```php
 * Table::make('state')->columns([
 *     'id'      => Col::id(),
 *     'name'    => Col::varchar(120)->notNull(),
 *     'country' => Col::int()->notNull()->references(Country::class),
 * ]);
 * ```
 *
 * É IMUTÁVEL, ao contrário da `Column` antiga. Cada modificador devolve outra
 * instância, então uma coluna serve de molde sem risco de alias:
 *
 * ```php
 * $dinheiro = Col::decimal(19, 4)->notNull()->default(0);
 *
 * 'saldo'   => $dinheiro,
 * 'limite'  => $dinheiro->comment('Limite de crédito'),   // não mexe em $dinheiro
 * ```
 */
final class Col
{
    private bool $notNull = false;

    private bool $primary = false;

    private bool $unique = false;

    private bool $autoIncrement = false;

    private DefaultValue $default;

    private ?string $comment = null;

    private ?string $collation = null;

    /** @var array{name:?string,method:?string}|null */
    private ?array $index = null;

    /** @var array{table:string,column:string,onDelete:string,onUpdate:string,name:?string}|null */
    private ?array $reference = null;

    private function __construct(private TypeSpec $type)
    {
        $this->default = DefaultValue::none();
    }

    // ------------------------------------------------------------------ tipos

    public static function tinyInt(): self
    {
        return new self(new TypeSpec(TypeName::TinyInt));
    }

    public static function smallInt(): self
    {
        return new self(new TypeSpec(TypeName::SmallInt));
    }

    public static function mediumInt(): self
    {
        return new self(new TypeSpec(TypeName::MediumInt));
    }

    public static function int(): self
    {
        return new self(new TypeSpec(TypeName::Int));
    }

    public static function bigInt(): self
    {
        return new self(new TypeSpec(TypeName::BigInt));
    }

    public static function decimal(int $precision, int $scale = 0): self
    {
        return new self(new TypeSpec(TypeName::Decimal, precision: $precision, scale: $scale));
    }

    public static function float(): self
    {
        return new self(new TypeSpec(TypeName::Float));
    }

    public static function double(): self
    {
        return new self(new TypeSpec(TypeName::Double));
    }

    public static function boolean(): self
    {
        return new self(new TypeSpec(TypeName::Boolean));
    }

    public static function char(int $length): self
    {
        return new self(new TypeSpec(TypeName::Char, length: $length));
    }

    public static function varchar(int $length): self
    {
        return new self(new TypeSpec(TypeName::Varchar, length: $length));
    }

    public static function tinyText(): self
    {
        return new self(new TypeSpec(TypeName::TinyText));
    }

    public static function text(): self
    {
        return new self(new TypeSpec(TypeName::Text));
    }

    public static function mediumText(): self
    {
        return new self(new TypeSpec(TypeName::MediumText));
    }

    public static function longText(): self
    {
        return new self(new TypeSpec(TypeName::LongText));
    }

    public static function date(): self
    {
        return new self(new TypeSpec(TypeName::Date));
    }

    /** `TIME` é DURAÇÃO no MySQL (±838h) e chega ao PHP como string, não como data. */
    public static function time(): self
    {
        return new self(new TypeSpec(TypeName::Time));
    }

    public static function dateTime(): self
    {
        return new self(new TypeSpec(TypeName::DateTime));
    }

    public static function timestamp(): self
    {
        return new self(new TypeSpec(TypeName::Timestamp));
    }

    public static function timestampTz(): self
    {
        return new self(new TypeSpec(TypeName::TimestampTz));
    }

    public static function year(): self
    {
        return new self(new TypeSpec(TypeName::Year));
    }

    public static function json(): self
    {
        return new self(new TypeSpec(TypeName::Json));
    }

    public static function jsonb(): self
    {
        return new self(new TypeSpec(TypeName::Jsonb));
    }

    public static function uuid(): self
    {
        return new self(new TypeSpec(TypeName::Uuid));
    }

    public static function binary(int $length): self
    {
        return new self(new TypeSpec(TypeName::Binary, length: $length));
    }

    public static function varBinary(int $length): self
    {
        return new self(new TypeSpec(TypeName::VarBinary, length: $length));
    }

    public static function tinyBlob(): self
    {
        return new self(new TypeSpec(TypeName::TinyBlob));
    }

    public static function blob(): self
    {
        return new self(new TypeSpec(TypeName::Blob));
    }

    public static function mediumBlob(): self
    {
        return new self(new TypeSpec(TypeName::MediumBlob));
    }

    public static function longBlob(): self
    {
        return new self(new TypeSpec(TypeName::LongBlob));
    }

    public static function bytea(): self
    {
        return new self(new TypeSpec(TypeName::Bytea));
    }

    /**
     * @param list<string> $values
     */
    public static function enum(array $values): self
    {
        return new self(new TypeSpec(TypeName::Enum, values: array_values($values)));
    }

    /**
     * Via de escape: o tipo escrito como texto, na gramática do DSL antigo.
     *
     * Existe para que nenhum tipo fique inalcançável — `TypeSpec::parse()` aceita
     * `('VARCHAR', 120)`, `('DECIMAL', '10,2')`, `'VARCHAR(120)'` e o sufixo
     * `UNSIGNED`. Preferir as fábricas acima quando existirem: só elas erram na
     * chamada em vez de errar no build.
     */
    public static function type(string $type, string|int|null $size = null): self
    {
        return new self(TypeSpec::parse($type, $size));
    }

    // ---------------------------------------------------------------- atalhos

    /**
     * `INT` chave primária com auto incremento — a primeira linha de quase todo model.
     */
    public static function id(): self
    {
        return self::int()->primary()->autoIncrement();
    }

    /** Idem, em `BIGINT`. */
    public static function bigId(): self
    {
        return self::bigInt()->primary()->autoIncrement();
    }

    /**
     * `INT NOT NULL` referenciando outra tabela.
     *
     * O tipo é INT porque é o que `Col::id()` produz. Para uma tabela cuja chave é
     * `bigId()`, escreva `Col::bigInt()->notNull()->references(...)`: tipos
     * diferentes nas duas pontas de uma foreign key são recusados pelo banco, e
     * adivinhar aqui exigiria que este builder conhecesse o outro model.
     */
    public static function fk(
        string $modelOrTable,
        string $column = 'id',
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'NO ACTION',
        ?string $name = null,
    ): self {
        return self::int()->notNull()->references($modelOrTable, $column, $onDelete, $onUpdate, $name);
    }

    // ----------------------------------------------------------- modificadores

    public function notNull(): self
    {
        $clone = clone $this;
        $clone->notNull = true;

        return $clone;
    }

    /**
     * O padrão já é aceitar nulo; isto existe para dizê-lo em voz alta, e para
     * desfazer o `notNull()` de uma coluna usada como molde.
     */
    public function nullable(): self
    {
        $clone = clone $this;
        $clone->notNull = false;

        return $clone;
    }

    /**
     * Marca a coluna como parte da chave primária.
     *
     * Em mais de uma coluna produz chave primária composta. Implica NOT NULL: os
     * dois bancos tratam PK como NOT NULL, e deixar o IR discordar faria a coluna
     * parecer alterada em todo round-trip de introspecção.
     */
    public function primary(): self
    {
        $clone = clone $this;
        $clone->primary = true;
        $clone->notNull = true;

        return $clone;
    }

    /**
     * Restrição de unicidade sobre esta coluna.
     *
     * Restrição, e não índice único: no MySQL os dois são o mesmo objeto de
     * catálogo, então ter duas representações faria uma delas divergir do banco
     * para sempre.
     */
    public function unique(): self
    {
        $clone = clone $this;
        $clone->unique = true;

        return $clone;
    }

    public function autoIncrement(): self
    {
        $clone = clone $this;
        $clone->autoIncrement = true;

        return $clone;
    }

    /** Índice comum sobre esta coluna. Sem nome, `ConstraintNamer` decide. */
    public function index(?string $name = null, ?string $method = null): self
    {
        $clone = clone $this;
        $clone->index = ['name' => $name, 'method' => $method];

        return $clone;
    }

    /** `INT UNSIGNED` e afins. Só o MySQL tem o modificador. */
    public function unsigned(): self
    {
        $clone = clone $this;
        $clone->type = new TypeSpec(
            name: $this->type->name,
            length: $this->type->length,
            precision: $this->type->precision,
            scale: $this->type->scale,
            values: $this->type->values,
            unsigned: true,
        );

        return $clone;
    }

    /**
     * Valor padrão. `Raw` (ou `defaultRaw()`) para SQL que não deve ser citado.
     */
    public function default(Raw|string|int|float|bool|null $value = null): self
    {
        $clone = clone $this;

        if ($value instanceof Raw) {
            $clone->default = DefaultValue::expression($value->getSql());
        } elseif ($value === null) {
            // Distinto de "sem cláusula DEFAULT", que é o estado inicial. No catálogo
            // de um banco os dois são estados diferentes, e colapsá-los faria o differ
            // não conseguir expressar a remoção de um default.
            $clone->default = DefaultValue::null();
        } else {
            $clone->default = DefaultValue::literal($value);
        }

        return $clone;
    }

    /** `DEFAULT <sql>`, sem citação. */
    public function defaultRaw(string $sql): self
    {
        return $this->default(new Raw($sql));
    }

    public function defaultNull(): self
    {
        return $this->default(null);
    }

    /** Comentário como TEXTO — o dialeto decide se vira `COMMENT` ou `COMMENT ON COLUMN`. */
    public function comment(string $comment): self
    {
        $clone = clone $this;
        $clone->comment = $comment === '' ? null : $comment;

        return $clone;
    }

    /** Collation por coluna. Só o MySQL a suporta; o `SchemaValidator` recusa no pgsql. */
    public function collation(string $collation): self
    {
        $clone = clone $this;
        $clone->collation = $collation === '' ? null : $collation;

        return $clone;
    }

    /**
     * Foreign key desta coluna para outra tabela.
     *
     * Aceita a CLASSE do model (`Country::class`) ou o nome cru da tabela. A classe
     * é o caminho recomendado: `Country::class` é verificável pela IDE e pelo
     * PHPStan, uma string não é.
     *
     * Foreign key COMPOSTA não cabe numa coluna só — para essa, use
     * `Table::foreignKey()`.
     */
    public function references(
        string $modelOrTable,
        string $column = 'id',
        string $onDelete = 'RESTRICT',
        string $onUpdate = 'NO ACTION',
        ?string $name = null,
    ): self {
        $clone = clone $this;
        $clone->reference = [
            'table' => self::resolveTableName($modelOrTable),
            'column' => $column,
            'onDelete' => $onDelete,
            'onUpdate' => $onUpdate,
            'name' => $name,
        ];

        return $clone;
    }

    /**
     * Class-string de model vira o nome da tabela que ele declara; qualquer outra
     * string passa adiante como está.
     */
    private static function resolveTableName(string $modelOrTable): string
    {
        if (!class_exists($modelOrTable)) {
            return $modelOrTable;
        }

        if (!defined($modelOrTable . '::table')) {
            throw new MigrationException(
                "references({$modelOrTable}::class) não encontra a constante 'table' nessa classe. "
                . 'Um model precisa declarar `public const table = \'nome_da_tabela\';`, ou passe o '
                . 'nome da tabela como string.',
            );
        }

        $table = constant($modelOrTable . '::table');

        if (!is_string($table) || $table === '') {
            throw new MigrationException(
                "references({$modelOrTable}::class): a constante 'table' precisa ser uma string não vazia.",
            );
        }

        return $table;
    }

    // ------------------------------------------------------------------ build

    /**
     * O IR desta coluna, com o nome que a tabela lhe deu.
     *
     * Não memoiza: a mesma instância pode nomear duas colunas (é imutável, então
     * reusá-la como molde é o comportamento esperado), e um cache de uma definição
     * só devolveria o nome errado para a segunda.
     */
    public function build(string $name): ColumnDefinition
    {
        return new ColumnDefinition(
            name: $name,
            type: $this->type,
            notNull: $this->notNull,
            autoIncrement: $this->autoIncrement,
            default: $this->default,
            comment: $this->comment,
            collation: $this->collation,
        );
    }

    public function isPrimaryKey(): bool
    {
        return $this->primary;
    }

    public function isUniqueColumn(): bool
    {
        return $this->unique;
    }

    public function wantsAutoIncrement(): bool
    {
        return $this->autoIncrement;
    }

    /**
     * @return array{name:?string,method:?string}|null
     */
    public function wantsIndex(): ?array
    {
        return $this->index;
    }

    /**
     * @return array{table:string,column:string,onDelete:string,onUpdate:string,name:?string}|null
     */
    public function wantsReference(): ?array
    {
        return $this->reference;
    }
}
