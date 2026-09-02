<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen\Plan;

use Diogodg\Neoorm\Codegen\PhpType;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\Value\DefaultKind;

/**
 * Uma coluna já resolvida para emissão: tipo PHP decidido, enum resolvido.
 *
 * Existe para os quatro emissores concordarem. Sem isto, cada um decidiria por conta
 * própria se a coluna vira enum ou string, e o `Row` poderia declarar `UsersStatus`
 * enquanto o `Insert` declara `string` — divergência que só apareceria quando alguém
 * tentasse passar um ao outro.
 */
final readonly class ColumnPlan
{
    /**
     * @param array<string,string>|null $enumCases nome do case => valor, quando vira enum PHP
     */
    public function __construct(
        public ColumnDefinition $column,
        public PhpType $type,
        public ?string $enumClass = null,
        public ?array $enumCases = null,
    ) {
    }

    public function name(): string
    {
        return $this->column->name;
    }

    public function isEnum(): bool
    {
        return $this->enumClass !== null;
    }

    /**
     * Se o INSERT pode omitir a coluna.
     *
     * Auto incremento e coluna com DEFAULT o banco preenche; nullable aceita ausência.
     * Só sobra obrigatória a coluna NOT NULL sem default — que é exatamente a que, se
     * faltar, faz o banco recusar a linha.
     */
    public function isOptionalOnInsert(): bool
    {
        return $this->column->autoIncrement
            || !$this->column->notNull
            || $this->column->default->kind !== DefaultKind::None;
    }

    /**
     * O caso ambíguo: nullable COM default.
     *
     * Ali "grave NULL" e "deixe o default" são resultados diferentes, e `null` sozinho
     * só expressa um. É a única combinação que precisa do marcador `Unspecified`.
     */
    public function needsUnspecified(): bool
    {
        return !$this->column->notNull
            && !$this->column->autoIncrement
            && $this->column->default->kind !== DefaultKind::None
            && $this->column->default->kind !== DefaultKind::Null;
    }
}
