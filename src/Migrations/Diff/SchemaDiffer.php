<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Diff;

use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Operation\AddCheckConstraint;
use Diogodg\Neoorm\Migrations\Operation\AddColumn;
use Diogodg\Neoorm\Migrations\Operation\AddForeignKey;
use Diogodg\Neoorm\Migrations\Operation\AddPrimaryKey;
use Diogodg\Neoorm\Migrations\Operation\AddUniqueConstraint;
use Diogodg\Neoorm\Migrations\Operation\AlterColumn;
use Diogodg\Neoorm\Migrations\Operation\ColumnChangeSet;
use Diogodg\Neoorm\Migrations\Operation\CreateIndex;
use Diogodg\Neoorm\Migrations\Operation\CreateTable;
use Diogodg\Neoorm\Migrations\Operation\DropCheckConstraint;
use Diogodg\Neoorm\Migrations\Operation\DropColumn;
use Diogodg\Neoorm\Migrations\Operation\DropForeignKey;
use Diogodg\Neoorm\Migrations\Operation\DropIndex;
use Diogodg\Neoorm\Migrations\Operation\DropPrimaryKey;
use Diogodg\Neoorm\Migrations\Operation\DropTable;
use Diogodg\Neoorm\Migrations\Operation\DropUniqueConstraint;
use Diogodg\Neoorm\Migrations\Operation\OperationList;
use Diogodg\Neoorm\Migrations\Operation\RenameColumn;
use Diogodg\Neoorm\Migrations\Operation\RenameTable;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Migrations\Operation\SetTableComment;
use Diogodg\Neoorm\Migrations\Operation\SetTableOptions;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Schema\TableDefinition;
use Diogodg\Neoorm\Schema\TableOptions;

/**
 * Compara dois snapshots e devolve as operações que levam de um ao outro.
 *
 * Puro. Sem PDO, sem Config, sem sistema de arquivos, sem relógio, sem
 * aleatoriedade. É a diferença de fundo em relação ao sistema antigo, que comparava
 * o model contra cinco tabelas `_schema_*` dentro do próprio banco de destino —
 * tabelas que registravam o que o código afirmou ter feito, não o que o banco
 * tinha. Aqui as duas entradas são arquivos JSON commitados, e a saída é função
 * exclusiva delas: mesma entrada, mesma saída, em qualquer máquina.
 *
 * Duas regras que valem ler antes do código:
 *
 * A fronteira do rename. A fase P3 renomeia. Toda operação anterior a ela usa o
 * nome ANTIGO da tabela, porque é o nome que está no banco naquele instante; toda
 * operação posterior usa o novo. Por isso quase todo método daqui recebe
 * `$fromName` e `$toName` em vez de um nome só.
 *
 * A chave primária é comparada apenas pela lista de colunas, ignorando o nome da
 * restrição. É o que permite adotar um banco existente cuja PK o servidor batizou
 * de `city_pkey` sem gerar uma migração que só troca o nome de uma constraint.
 */
final class SchemaDiffer
{
    public function __construct(
        private readonly RenameResolver $renames = new NoRenameResolver(),
        private readonly OperationSorter $sorter = new OperationSorter(),
    ) {
    }

    public function diff(Snapshot $from, Snapshot $to): OperationList
    {
        $this->assertComparable($from, $to);

        $tableRenames = $this->tableRenames($from, $to);

        /** @var list<SchemaOperation> $operations */
        $operations = [];

        /** @var array<string,string> $survivors nome novo => nome antigo */
        $survivors = [];

        foreach ($from->tables() as $fromName => $fromTable) {
            $toName = $tableRenames[$fromName] ?? $fromName;

            if ($to->schema->hasTable($toName)) {
                $survivors[$toName] = $fromName;

                continue;
            }

            // As foreign keys da tabela que sai são soltas explicitamente na P0,
            // mesmo que o `DROP TABLE` fosse levá-las embora de qualquer forma.
            //
            // Não é zelo: com duas tabelas de foreign key mútua sendo removidas
            // juntas, NÃO EXISTE ordem de `DROP TABLE` que funcione — cada uma é
            // referenciada pela outra, e os dois bancos recusam derrubar uma tabela
            // referenciada. Soltar toda referência antes de qualquer remoção é o que
            // torna a ordem irrelevante para o banco, e é o que deixa a ordenação
            // topológica ser o que ela deveria ser: legibilidade.
            foreach ($fromTable->foreignKeys as $name => $unused) {
                $operations[] = new DropForeignKey($fromName, $name);
            }

            $operations[] = new DropTable($fromName);
        }

        foreach ($tableRenames as $fromName => $toName) {
            $operations[] = new RenameTable($fromName, $toName);
        }

        foreach ($to->tables() as $toName => $toTable) {
            if (!isset($survivors[$toName])) {
                $operations = [...$operations, new CreateTable($toTable), ...$this->constraintsOf($toTable)];
            }
        }

        /** @var array<string,list<string>> $typeChanged */
        $typeChanged = [];
        /** @var array<string,true> $pkChanged */
        $pkChanged = [];
        /** @var array<string,list<string>> $rebuiltForeignKeys */
        $rebuiltForeignKeys = [];

        foreach ($survivors as $toName => $fromName) {
            $fromTable = $from->table($fromName);
            $toTable = $to->table($toName);

            if ($fromTable === null || $toTable === null) {
                continue;
            }

            $columnRenames = $this->columnRenames($fromName, $fromTable, $toTable, $to);
            $diff = $this->diffTable(
                $fromName,
                $toName,
                $fromTable,
                $toTable,
                $columnRenames,
                $from->isIntrospected() || $to->isIntrospected(),
            );

            $operations = [...$operations, ...$diff->operations];

            if ($diff->typeChangedColumns !== []) {
                $typeChanged[$toName] = $diff->typeChangedColumns;
            }

            if ($diff->primaryKeyChanged) {
                $pkChanged[$toName] = true;
            }

            $rebuiltForeignKeys[$toName] = $diff->rebuiltForeignKeys;
        }

        $operations = [
            ...$operations,
            ...$this->shieldForeignKeys(
                $from,
                $to,
                $survivors,
                $tableRenames,
                $typeChanged,
                $pkChanged,
                $rebuiltForeignKeys,
            ),
        ];

        return $this->sorter->sort(new OperationList($operations), $from->schema, $to->schema);
    }

    /**
     * As ambiguidades que este diff deixou sem resolver.
     *
     * Existe separado de `diff()` porque `migration:generate` roda em duas etapas:
     * a primeira pergunta o que fazer com as ambiguidades, a segunda gera com as
     * respostas. Uma etapa só obrigaria a biblioteca a saber o que é um terminal.
     */
    public function candidates(Snapshot $from, Snapshot $to): RenameCandidates
    {
        $this->assertComparable($from, $to);

        $tableRenames = $this->tableRenames($from, $to);

        $removedTables = array_values(array_diff(
            $from->schema->tableNames(),
            $to->schema->tableNames(),
            array_keys($tableRenames),
        ));
        $addedTables = array_values(array_diff(
            $to->schema->tableNames(),
            $from->schema->tableNames(),
            array_values($tableRenames),
        ));

        $columns = [];

        foreach ($from->tables() as $fromName => $fromTable) {
            $toName = $tableRenames[$fromName] ?? $fromName;
            $toTable = $to->table($toName);

            if ($toTable === null) {
                continue;
            }

            $columnRenames = $this->columnRenames($fromName, $fromTable, $toTable, $to);

            $removed = array_values(array_diff(
                $fromTable->getColumnNames(),
                $toTable->getColumnNames(),
                array_keys($columnRenames),
            ));
            $added = array_values(array_diff(
                $toTable->getColumnNames(),
                $fromTable->getColumnNames(),
                array_values($columnRenames),
            ));

            if ($removed !== [] || $added !== []) {
                $columns[$toName] = ['removed' => $removed, 'added' => $added];
            }
        }

        ksort($columns, SORT_STRING);

        return new RenameCandidates($removedTables, $addedTables, $columns);
    }

    // ------------------------------------------------------------ por tabela

    /**
     * @param array<string,string> $columnRenames nome antigo => nome novo
     */
    private function diffTable(
        string $fromName,
        string $toName,
        TableDefinition $from,
        TableDefinition $to,
        array $columnRenames,
        bool $checkExpressionsAreAdvisory = false,
    ): TableDiff {
        /** @var list<SchemaOperation> $operations */
        $operations = [];
        /** @var list<string> $typeChanged */
        $typeChanged = [];

        foreach ($columnRenames as $old => $new) {
            $operations[] = new RenameColumn($toName, $old, $new);
        }

        // Alinha o lado antigo no espaço de nomes novo. Depois disso a comparação é
        // por nome e a renomeação deixa de ser um caso especial.
        $aligned = [];

        foreach ($from->columns as $name => $column) {
            $newName = $columnRenames[$name] ?? $name;

            if (!$to->hasColumn($newName)) {
                $operations[] = new DropColumn($toName, $name);

                continue;
            }

            $aligned[$newName] = $column->withName($newName);
        }

        foreach ($to->columns as $name => $column) {
            if (!isset($aligned[$name])) {
                $operations[] = new AddColumn($toName, $column);

                continue;
            }

            $changes = ColumnChangeSet::between($aligned[$name], $column);

            if ($changes->isEmpty()) {
                continue;
            }

            $operations[] = new AlterColumn($toName, $aligned[$name], $column);

            if ($changes->type) {
                $typeChanged[] = $name;
            }
        }

        if ($from->comment !== $to->comment) {
            $operations[] = new SetTableComment($toName, $to->comment);
        }

        $options = $this->optionsChange($from->options, $to->options);

        if ($options !== null) {
            $operations[] = new SetTableOptions($toName, $options);
        }

        $primaryKeyChanged = false;
        $fromPk = $this->mapColumns($from->getPrimaryKeyColumns(), $columnRenames);

        if ($fromPk !== $to->getPrimaryKeyColumns()) {
            $primaryKeyChanged = true;

            if ($from->primaryKey !== null) {
                $operations[] = new DropPrimaryKey(
                    $fromName,
                    $from->primaryKey->name,
                    $this->autoIncrementInPrimaryKey($from),
                );
            }

            if ($to->primaryKey !== null) {
                $operations[] = new AddPrimaryKey($toName, $to->primaryKey);
            }
        }

        $rebuiltForeignKeys = [];

        // Restrições e índices são casados por NOME. Consequência assumida: como os
        // nomes gerados embutem o nome da tabela, renomear uma tabela derruba e
        // recria todos os seus índices e restrições. É correto — o banco preserva os
        // nomes antigos num rename, e o snapshot novo declara os novos — e é
        // preferível a casar por lista de colunas, que confundiria dois índices
        // sobre as mesmas colunas.
        foreach ($from->uniqueConstraints as $name => $unique) {
            $target = $to->uniqueConstraints[$name] ?? null;

            if ($target === null) {
                $operations[] = new DropUniqueConstraint($fromName, $name);

                continue;
            }

            if ($this->mapColumns($unique->columns, $columnRenames) !== $target->columns) {
                $operations[] = new DropUniqueConstraint($fromName, $name);
                $operations[] = new AddUniqueConstraint($toName, $target);
            }
        }

        foreach ($to->uniqueConstraints as $name => $unique) {
            if (!isset($from->uniqueConstraints[$name])) {
                $operations[] = new AddUniqueConstraint($toName, $unique);
            }
        }

        foreach ($from->indexes as $name => $index) {
            $target = $to->indexes[$name] ?? null;

            if ($target === null) {
                $operations[] = new DropIndex($fromName, $name);

                continue;
            }

            $sameColumns = $this->mapColumns($index->columns, $columnRenames) === $target->columns;

            if (!$sameColumns || $index->unique !== $target->unique || $index->method !== $target->method) {
                $operations[] = new DropIndex($fromName, $name);
                $operations[] = new CreateIndex($toName, $target);
            }
        }

        foreach ($to->indexes as $name => $index) {
            if (!isset($from->indexes[$name])) {
                $operations[] = new CreateIndex($toName, $index);
            }
        }

        // CHECK é comparado pela forma normalizada, e aqui isso é seguro: as duas
        // pontas são snapshots nossos, com a expressão como foi escrita. O caso em
        // que a comparação de CHECK é apenas advisória é outro — `db:check` contra
        // um banco vivo, onde o PostgreSQL devolve a expressão reformatada.
        foreach ($from->checks as $name => $check) {
            $target = $to->checks[$name] ?? null;

            if ($target === null) {
                $operations[] = new DropCheckConstraint($fromName, $name);

                continue;
            }

            // O CHECK existe nos dois lados com o mesmo nome e a expressão difere. Se um
            // dos lados veio de um banco, essa diferença provavelmente é reescrita do
            // servidor e não mudança de intenção — o PostgreSQL transforma
            // `x in ('a','b')` em `x = ANY (ARRAY[...])`, e nenhum normalizador razoável
            // reverte isso. Gerar DDL a partir de uma comparação com falso positivo
            // conhecido é pior que não gerar: produziria uma migração que roda em toda
            // execução e nunca converge. `db:check` reporta como advisório.
            if (!$check->equals($target) && !$checkExpressionsAreAdvisory) {
                $operations[] = new DropCheckConstraint($fromName, $name);
                $operations[] = new AddCheckConstraint($toName, $target);
            }
        }

        foreach ($to->checks as $name => $check) {
            if (!isset($from->checks[$name])) {
                $operations[] = new AddCheckConstraint($toName, $check);
            }
        }

        foreach ($from->foreignKeys as $name => $foreignKey) {
            $target = $to->foreignKeys[$name] ?? null;

            if ($target === null) {
                $operations[] = new DropForeignKey($fromName, $name);

                continue;
            }

            $same = $this->mapColumns($foreignKey->columns, $columnRenames) === $target->columns
                && $foreignKey->referencedTable === $target->referencedTable
                && $foreignKey->referencedColumns === $target->referencedColumns
                && $foreignKey->onDelete === $target->onDelete
                && $foreignKey->onUpdate === $target->onUpdate;

            if (!$same) {
                $operations[] = new DropForeignKey($fromName, $name);
                $operations[] = new AddForeignKey($toName, $target);
                $rebuiltForeignKeys[] = $name;
            }
        }

        foreach ($to->foreignKeys as $name => $foreignKey) {
            if (!isset($from->foreignKeys[$name])) {
                $operations[] = new AddForeignKey($toName, $foreignKey);
            }
        }

        return new TableDiff($operations, $typeChanged, $primaryKeyChanged, $rebuiltForeignKeys);
    }

    /**
     * As foreign keys que precisam sair da frente mesmo sem terem mudado.
     *
     * Uma referência intacta ainda assim atravanca duas coisas. Trocar o tipo de uma
     * coluna que participa dela: o MySQL recusa com "referencing column and
     * referenced column are incompatible", porque as duas pontas têm que casar e o
     * ALTER mexe numa só. E mexer na chave primária de uma tabela referenciada: a
     * FK depende do índice da PK, e derrubá-lo com a FK de pé é recusado.
     *
     * Nos dois casos a FK sai na P0 e volta na P8, com a definição do schema novo.
     * FK que a comparação normal já derruba e recria não entra aqui — seria um par
     * duplicado, e o segundo `ADD CONSTRAINT` falharia com "constraint já existe",
     * que é literalmente o bug B4 do sistema antigo.
     *
     * @param array<string,string>      $survivors      nome novo => nome antigo
     * @param array<string,string>      $tableRenames   nome antigo => nome novo
     * @param array<string,list<string>> $typeChanged   tabela (nome novo) => colunas
     * @param array<string,true>        $pkChanged      tabelas (nome novo)
     * @param array<string,list<string>> $rebuilt       tabela (nome novo) => FKs já refeitas
     * @return list<SchemaOperation>
     */
    private function shieldForeignKeys(
        Snapshot $from,
        Snapshot $to,
        array $survivors,
        array $tableRenames,
        array $typeChanged,
        array $pkChanged,
        array $rebuilt,
    ): array {
        if ($typeChanged === [] && $pkChanged === []) {
            return [];
        }

        $operations = [];

        foreach ($survivors as $toName => $fromName) {
            $fromTable = $from->table($fromName);
            $toTable = $to->table($toName);

            if ($fromTable === null || $toTable === null) {
                continue;
            }

            $alreadyRebuilt = array_fill_keys($rebuilt[$toName] ?? [], true);

            foreach ($fromTable->foreignKeys as $name => $foreignKey) {
                $target = $toTable->foreignKeys[$name] ?? null;

                if ($target === null || isset($alreadyRebuilt[$name])) {
                    continue;
                }

                $referenced = $tableRenames[$foreignKey->referencedTable] ?? $foreignKey->referencedTable;

                $blocked = isset($pkChanged[$toName])
                    || isset($pkChanged[$referenced])
                    || array_intersect($target->columns, $typeChanged[$toName] ?? []) !== []
                    || array_intersect($target->referencedColumns, $typeChanged[$referenced] ?? []) !== [];

                if ($blocked) {
                    $operations[] = new DropForeignKey($fromName, $name);
                    $operations[] = new AddForeignKey($toName, $target);
                }
            }
        }

        return $operations;
    }

    // ------------------------------------------------------------- auxiliares

    /**
     * As restrições e índices de uma tabela nova.
     *
     * Não inclui a chave primária, que vai inline no `CreateTable` porque o MySQL
     * não aceita coluna auto incremento sem chave no mesmo statement, nem comentário
     * e opções, que também são inline.
     *
     * @return list<SchemaOperation>
     */
    private function constraintsOf(TableDefinition $table): array
    {
        $operations = [];

        foreach ($table->uniqueConstraints as $unique) {
            $operations[] = new AddUniqueConstraint($table->name, $unique);
        }

        foreach ($table->indexes as $index) {
            $operations[] = new CreateIndex($table->name, $index);
        }

        foreach ($table->checks as $check) {
            $operations[] = new AddCheckConstraint($table->name, $check);
        }

        foreach ($table->foreignKeys as $foreignKey) {
            $operations[] = new AddForeignKey($table->name, $foreignKey);
        }

        return $operations;
    }

    /**
     * `null` no destino significa "default do servidor, não compare".
     *
     * É esta regra que mata o diff fantasma de collation: o builder antigo assumia
     * `utf8mb4_general_ci` e comparava contra um MySQL 8 cujo default é
     * `utf8mb4_0900_ai_ci`, gerando um ALTER que a comparação seguinte nunca
     * aceitava. A comparação é insensível a caixa porque o MySQL devolve `InnoDB`
     * para quem escreveu `innodb`.
     */
    private function optionsChange(TableOptions $from, TableOptions $to): ?TableOptions
    {
        $engine = ($to->engine !== null && strcasecmp($to->engine, (string) $from->engine) !== 0)
            ? $to->engine
            : null;

        $collation = ($to->collation !== null && strcasecmp($to->collation, (string) $from->collation) !== 0)
            ? $to->collation
            : null;

        if ($engine === null && $collation === null) {
            return null;
        }

        return new TableOptions(engine: $engine, collation: $collation);
    }

    /**
     * A coluna auto incremento, quando ela faz parte da chave primária.
     *
     * Só nesse caso o MySQL exige desarmar o AUTO_INCREMENT antes do
     * `DROP PRIMARY KEY`. Auto incremento fora da PK não bloqueia nada.
     */
    private function autoIncrementInPrimaryKey(TableDefinition $table): ?\Diogodg\Neoorm\Schema\ColumnDefinition
    {
        $column = $table->autoIncrementColumn();

        if ($column === null || !in_array($column->name, $table->getPrimaryKeyColumns(), true)) {
            return null;
        }

        return $column;
    }

    /**
     * @param list<string>         $columns
     * @param array<string,string> $renames
     * @return list<string>
     */
    private function mapColumns(array $columns, array $renames): array
    {
        return array_map(static fn (string $column): string => $renames[$column] ?? $column, $columns);
    }

    // --------------------------------------------------------------- renames

    /**
     * @return array<string,string> nome antigo => nome novo
     */
    private function tableRenames(Snapshot $from, Snapshot $to): array
    {
        $removed = array_values(array_diff($from->schema->tableNames(), $to->schema->tableNames()));
        $added = array_values(array_diff($to->schema->tableNames(), $from->schema->tableNames()));

        return $this->pairUp(
            $removed,
            $added,
            static fn (string $old): ?string => $to->meta->renamedTable($old),
            fn (array $pendingRemoved, array $pendingAdded): array
                => $this->renames->resolveTables($pendingRemoved, $pendingAdded),
        );
    }

    /**
     * @return array<string,string> nome antigo => nome novo
     */
    private function columnRenames(
        string $fromName,
        TableDefinition $fromTable,
        TableDefinition $toTable,
        Snapshot $to,
    ): array {
        $removed = array_values(array_diff($fromTable->getColumnNames(), $toTable->getColumnNames()));
        $added = array_values(array_diff($toTable->getColumnNames(), $fromTable->getColumnNames()));

        return $this->pairUp(
            $removed,
            $added,
            // Consulta pelos DOIS nomes da tabela: quem registrou a renomeação de
            // coluna pode tê-la registrado sob o nome novo da tabela ou sob o antigo,
            // e as duas leituras são razoáveis para quem escreveu.
            static fn (string $old): ?string
                => $to->meta->renamedColumn($toTable->name, $old) ?? $to->meta->renamedColumn($fromName, $old),
            fn (array $pendingRemoved, array $pendingAdded): array
                => $this->renames->resolveColumns($toTable->name, $pendingRemoved, $pendingAdded),
        );
    }

    /**
     * Casa o que sumiu com o que apareceu: primeiro pelo que o snapshot já registra,
     * depois pelo resolvedor injetado.
     *
     * O snapshot vem primeiro e é autoridade. É o que faz um `generate` em CI, sem
     * terminal e sem ninguém para responder, chegar exatamente ao mesmo resultado
     * que a máquina de quem gerou a migração.
     *
     * @param list<string>                                       $removed
     * @param list<string>                                       $added
     * @param callable(string):?string                           $recorded
     * @param callable(list<string>,list<string>):array<string,string> $resolve
     * @return array<string,string>
     */
    private function pairUp(array $removed, array $added, callable $recorded, callable $resolve): array
    {
        if ($removed === [] || $added === []) {
            return [];
        }

        $renames = [];
        $takenTargets = [];

        foreach ($removed as $old) {
            $target = $recorded($old);

            if ($target !== null && in_array($target, $added, true) && !isset($takenTargets[$target])) {
                $renames[$old] = $target;
                $takenTargets[$target] = true;
            }
        }

        $pendingRemoved = array_values(array_diff($removed, array_keys($renames)));
        $pendingAdded = array_values(array_diff($added, array_keys($takenTargets)));

        if ($pendingRemoved !== [] && $pendingAdded !== []) {
            foreach ($resolve($pendingRemoved, $pendingAdded) as $old => $new) {
                // O resolvedor é código de fora — CLI, prompt, arquivo. Um par que
                // não corresponde a uma diferença real geraria um RenameColumn para
                // coluna inexistente, então é descartado em vez de aceito.
                if (
                    in_array($old, $pendingRemoved, true)
                    && in_array($new, $pendingAdded, true)
                    && !isset($takenTargets[$new])
                    && !isset($renames[$old])
                ) {
                    $renames[(string) $old] = $new;
                    $takenTargets[$new] = true;
                }
            }
        }

        ksort($renames, SORT_STRING);

        return $renames;
    }

    private function assertComparable(Snapshot $from, Snapshot $to): void
    {
        if ($from->dialect !== $to->dialect) {
            throw new MigrationException(
                "Não dá para comparar um snapshot {$from->dialect} com um {$to->dialect}. "
                . 'Cada dialeto tem sua própria cadeia de snapshots, em diretórios separados, porque '
                . 'engine e collation só existem no MySQL e o SQL gerado é específico de cada banco.',
            );
        }
    }
}
