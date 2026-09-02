<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\TableDefinition;

/**
 * Cria uma tabela com suas colunas e sua chave primária — e nada mais.
 *
 * Uniques, índices, checks e foreign keys da tabela nova NÃO entram aqui: o
 * differ os emite como operações próprias, que as fases P7 e P8 colocam depois
 * de todos os `CreateTable`. Assim cada conceito tem um único lugar onde é
 * renderizado, valendo para tabela nova e tabela existente, e uma FK entre duas
 * tabelas novas nunca depende de qual das duas o differ visitou primeiro. Era
 * exatamente esse acoplamento entre ordem de criação e ordem alfabética dos
 * arquivos de model que fazia o sistema antigo precisar de um segundo passe de
 * foreign keys.
 *
 * A chave primária é a exceção porque não pode ser separada: o MySQL recusa
 * `CREATE TABLE t (id INT AUTO_INCREMENT)` com "there can be only one auto
 * column and it must be defined as a key".
 */
final readonly class CreateTable implements SchemaOperation
{
    public function __construct(
        public TableDefinition $table,
        /**
         * Só o runner usa. O differ nunca liga esta flag: uma migração descreve
         * uma transição exata, e `IF NOT EXISTS` transformaria "criar" em "criar
         * se der", que é precisamente como o sistema antigo passava a considerar
         * uma tabela sincronizada para sempre sem nunca ter olhado para ela.
         *
         * O uso legítimo é a própria tabela de controle das migrações, cujo DDL
         * roda antes de qualquer bookkeeping existir para dizer se ela já rodou.
         */
        public bool $ifNotExists = false,
    ) {
    }

    public function tableName(): string
    {
        return $this->table->name;
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function discardsData(): bool
    {
        return false;
    }

    public function describe(): string
    {
        $count = count($this->table->columns);

        return "cria a tabela '{$this->table->name}' com {$count} coluna" . ($count === 1 ? '' : 's');
    }
}
