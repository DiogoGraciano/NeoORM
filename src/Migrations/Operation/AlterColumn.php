<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Schema\ColumnDefinition;
use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Altera uma coluna existente, carregando as duas pontas.
 *
 * Guarda `from` e `to` inteiros, não só o destino, porque os dois dialetos
 * precisam de informação diferente: o MySQL redeclara a definição completa de
 * `to` num único `MODIFY COLUMN`, e o PostgreSQL precisa saber exatamente o que
 * mudou para emitir só os statements correspondentes. O `ColumnChangeSet` é
 * derivado aqui, uma vez, em vez de recalculado por cada dialeto — duas
 * respostas diferentes para "o que mudou?" seria uma fonte de migração espúria.
 */
final readonly class AlterColumn implements SchemaOperation
{
    public string $table;

    public ColumnChangeSet $changes;

    public function __construct(
        string $table,
        public ColumnDefinition $from,
        public ColumnDefinition $to,
    ) {
        $this->table = IdentifierValidator::normalize($table, 'Nome de tabela');

        if ($from->name !== $to->name) {
            throw new MigrationException(
                "AlterColumn exige a mesma coluna nas duas pontas; recebeu '{$from->name}' e '{$to->name}'. "
                . 'Renomeação é RenameColumn.',
            );
        }

        $this->changes = ColumnChangeSet::between($from, $to);
    }

    public function tableName(): string
    {
        return $this->table;
    }

    /**
     * Mudança de tipo pode truncar (VARCHAR(200) para VARCHAR(20)) ou falhar na
     * conversão; `NOT NULL` novo sem default falha se já existir linha com NULL.
     * Nenhum dos dois é decidível sem olhar os dados, então a operação declara o
     * risco em vez de fingir certeza.
     */
    public function isDestructive(): bool
    {
        if ($this->changes->type) {
            return true;
        }

        return $this->changes->notNull && $this->to->notNull && $this->to->default->isNone();
    }

    /**
     * Não, e a razão é o `sql_mode` que o runner garante.
     *
     * Estreitar um tipo — `VARCHAR(120)` para `VARCHAR(20)` — truncaria dados num MySQL sem
     * `STRICT_TRANS_TABLES`. Com o modo estrito, que é o default e que o `sessionSetup()` do
     * dialeto preserva de propósito, o servidor RECUSA em vez de truncar; no PostgreSQL ele
     * sempre recusa. Ou seja: o caso é arriscado (falha), não destrutivo (apaga), e é
     * `isDestructive()` que o reporta.
     */
    public function discardsData(): bool
    {
        return false;
    }

    public function describe(): string
    {
        $aspects = $this->changes->changed();

        if ($aspects === []) {
            return "coluna '{$this->table}.{$this->to->name}' sem alteração";
        }

        return "altera a coluna '{$this->table}.{$this->to->name}' (" . implode(', ', $aspects) . ')';
    }
}
