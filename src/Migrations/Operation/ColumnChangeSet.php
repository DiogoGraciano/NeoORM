<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\ColumnDefinition;

/**
 * Que aspectos de uma coluna mudaram.
 *
 * Existe porque os dois bancos discordam sobre a granularidade de um
 * `ALTER COLUMN`: o MySQL exige que a definição inteira seja redeclarada num
 * único `MODIFY COLUMN` (omitir o comentário apaga o comentário), enquanto o
 * PostgreSQL só aceita um aspecto por statement. Sem este objeto, o dialeto
 * pgsql teria que redescobrir por conta própria o que mudou, comparando as duas
 * definições de novo — e a chance de o differ e o dialeto discordarem sobre isso
 * é exatamente o tipo de divergência que produz migração espúria.
 *
 * Não existe operação `SetColumnComment`: mudança de comentário é uma flag
 * daqui. Um conceito, uma operação.
 */
final readonly class ColumnChangeSet
{
    public function __construct(
        public bool $type = false,
        public bool $notNull = false,
        public bool $default = false,
        public bool $comment = false,
        public bool $autoIncrement = false,
        public bool $collation = false,
    ) {
    }

    public static function between(ColumnDefinition $from, ColumnDefinition $to): self
    {
        return new self(
            type: !$from->type->equals($to->type),
            notNull: $from->notNull !== $to->notNull,
            default: !$from->default->equals($to->default),
            comment: $from->comment !== $to->comment,
            autoIncrement: $from->autoIncrement !== $to->autoIncrement,
            collation: $from->collation !== $to->collation,
        );
    }

    public function isEmpty(): bool
    {
        return $this->changed() === [];
    }

    /**
     * Os aspectos alterados, em ordem fixa.
     *
     * A ordem é fixa e não alfabética porque alimenta `describe()`, que é
     * comparado literalmente nos testes do differ: uma ordem que dependesse dos
     * dados faria a asserção depender deles também.
     *
     * @return list<string>
     */
    public function changed(): array
    {
        $aspects = [];

        foreach (
            [
                'tipo' => $this->type,
                'nulidade' => $this->notNull,
                'default' => $this->default,
                'auto incremento' => $this->autoIncrement,
                'collation' => $this->collation,
                'comentário' => $this->comment,
            ] as $label => $changed
        ) {
            if ($changed) {
                $aspects[] = $label;
            }
        }

        return $aspects;
    }

    /**
     * Só o comentário mudou.
     *
     * No PostgreSQL isso não é um `ALTER TABLE` nenhum, é um `COMMENT ON
     * COLUMN` — e no MySQL é um `MODIFY COLUMN` que reescreve a coluna inteira
     * só para mexer no comentário. Vale distinguir.
     */
    public function isCommentOnly(): bool
    {
        return $this->changed() === ['comentário'];
    }
}
