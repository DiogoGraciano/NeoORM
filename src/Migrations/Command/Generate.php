<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Dialect\SqlWriter;
use Diogodg\Neoorm\Migrations\Diff\NoRenameResolver;
use Diogodg\Neoorm\Migrations\Diff\RenameCandidates;
use Diogodg\Neoorm\Migrations\Diff\RenameResolver;
use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\Operation\CreateTable;
use Diogodg\Neoorm\Migrations\Operation\OperationList;
use Diogodg\Neoorm\Migrations\Operation\RenameColumn;
use Diogodg\Neoorm\Migrations\Operation\RenameTable;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Migrations\Snapshot\MigrationDirectory;
use Diogodg\Neoorm\Migrations\Snapshot\Snapshot;
use Diogodg\Neoorm\Migrations\Snapshot\SnapshotMeta;
use Diogodg\Neoorm\Schema\SchemaDefinition;

/**
 * Escreve uma migração a partir da diferença entre os models e o último snapshot.
 *
 * **É 100% offline.** Não abre conexão, não consulta o banco: compara dois arquivos JSON e
 * escreve um `.sql`. Isso não é otimização, é o que permite gerar migração num CI sem
 * serviço de banco e revisar o SQL no pull request antes de ele tocar em ambiente nenhum.
 * O sistema antigo não podia fazer isso porque comparava o model contra tabelas dentro do
 * próprio banco de destino.
 *
 * Em duas fases quando há rename a resolver: `execute(dryRun: true)` devolve os candidatos,
 * quem tem terminal pergunta, e a segunda chamada recebe as respostas. É assim que o prompt
 * existe sem a biblioteca depender de pacote de CLI.
 */
final class Generate
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    /**
     * @param string|null $name nome livre da migração; sem ele, um nome derivado das operações
     * @param bool $empty escreve uma migração vazia, para SQL escrito à mão
     * @param bool $allowDestructive libera DROP de tabela e de coluna, e a forma ambígua
     */
    public function execute(
        ?string $name = null,
        bool $empty = false,
        bool $dryRun = false,
        ?RenameResolver $renames = null,
        bool $allowDestructive = false,
    ): GenerateResult {
        $previous = $this->context->directory->latestSnapshot();
        $index = $this->context->directory->journal()->nextIndex();

        if ($empty) {
            return $this->writeEmpty($name, $previous, $index, $dryRun);
        }

        // `normalizeForStorage` descarta o que este banco não sabe guardar. Sem ele, um
        // `engine: 'InnoDB'` declarado nos models faria o snapshot do PostgreSQL divergir em
        // toda execução, com uma operação que compila para zero statements — uma migração
        // que afirma mudar algo, não muda nada, e nunca converge.
        $declared = $this->context->dialect->normalizeForStorage(
            $this->context->models->loadValidated($this->context->dialect->name()),
        );

        $target = $this->targetSnapshot($previous, $declared, $index);

        // Os candidatos saem de um differ SEM resolvedor, porque a pergunta é "isto pode ser
        // um rename?" — e quem já respondeu não deve ser perguntado de novo.
        $candidates = $renames === null
            ? (new SchemaDiffer())->candidates($previous, $target)
            : new RenameCandidates();

        $operations = (new SchemaDiffer($renames ?? new NoRenameResolver()))->diff($previous, $target);
        $statements = $this->context->dialect->compileAll($operations);
        $sql = (new SqlWriter())->write($statements);
        $warnings = $this->warningsFor($operations, $candidates);

        if (count($operations) === 0) {
            $this->context->output->write('Nada a gerar: os models já correspondem ao último snapshot.');

            return new GenerateResult($operations, warnings: $warnings, pendingRenames: $candidates, dryRun: $dryRun);
        }

        // Forma ambígua — um DropColumn e um AddColumn na mesma tabela — não escreve NADA sem
        // decisão explícita. É o caso em que gerar a coisa errada custa dados: a diferença
        // entre renomear uma coluna e apagá-la para criar outra vazia é invisível no diff e
        // total no banco.
        if (!$candidates->isEmpty() && $renames === null && !$allowDestructive) {
            throw new MigrationException(
                "A diferença tem forma ambígua e pode ser rename:\n  - "
                . implode("\n  - ", $candidates->describe())
                . "\n\nDecida com --rename antigo:novo (ou tabela.antigo:novo), ou confirme que é "
                . 'remoção e criação com --allow-destructive. Nada foi escrito.',
            );
        }

        // Bloqueia só o que APAGA — `DropTable` e `DropColumn` —, não tudo que é arriscado.
        // Uma operação arriscada falha quando os dados não a comportam: o banco recusa e nada
        // se perde, então ela vale um aviso. Uma que descarta sucede, e sem `down` o que ela
        // levou só volta de um backup. Bloquear as duas classes juntas exigiria
        // `--allow-destructive` na primeira migração de qualquer projeto, porque criar uma
        // tabela com restrição de unicidade já conta como arriscado.
        if ($operations->discardsData() && !$allowDestructive) {
            throw new MigrationException(
                "A migração apaga dados:\n  - "
                . implode("\n  - ", $operations->dataDiscarding()->describe())
                . "\n\nConfirme com --allow-destructive. Não há down: o que for removido só volta "
                . 'de um backup.',
            );
        }

        $tag = $this->tagFor($name, $operations, $index);

        if ($dryRun) {
            return new GenerateResult(
                $operations,
                $statements,
                $sql,
                warnings: $warnings,
                pendingRenames: $candidates,
                tag: $tag,
                index: $index,
                dryRun: true,
            );
        }

        // A decisão de rename fica GRAVADA no snapshot, para o próximo diff não perguntar de
        // novo — e para quem revisa o pull request ver que houve uma decisão.
        $this->context->directory->write($index, $tag, $sql, $target->withMeta($this->metaFor($operations)));

        $this->context->output->success(
            sprintf('Migração %04d_%s escrita com %d statement(s).', $index, $tag, count($statements)),
        );

        return new GenerateResult(
            $operations,
            $statements,
            $sql,
            [
                $this->context->directory->sqlPath($index, $tag),
                $this->context->directory->snapshotPath($index),
                $this->context->directory->journalPath(),
            ],
            $warnings,
            $candidates,
            $tag,
            $index,
        );
    }

    /**
     * O snapshot que esta migração vai gravar.
     *
     * A primeira é um caso à parte por um motivo só: a baseline vazia JÁ ocupa o índice 0, e
     * o snapshot da migração 0 também. Então a primeira não é `baseline->next()` — seria o
     * índice 1 — e sim um snapshot de índice 0 com o schema declarado. As duas são diffs
     * normais; o que difere é a numeração, não o caminho de código.
     */
    private function targetSnapshot(Snapshot $previous, SchemaDefinition $declared, int $index): Snapshot
    {
        return $index === 0
            ? Snapshot::initial($this->context->dialect->name(), $declared)
            : $previous->next($declared);
    }

    private function writeEmpty(?string $name, Snapshot $previous, int $index, bool $dryRun): GenerateResult
    {
        $operations = OperationList::of();
        $tag = MigrationDirectory::slug($name ?? 'manual') ?: 'manual';

        if ($dryRun) {
            return new GenerateResult($operations, tag: $tag, index: $index, dryRun: true);
        }

        // O snapshot de uma migração vazia repete o schema anterior: o SQL escrito à mão não
        // é interpretado por ninguém, então o differ não pode saber o que ele fez. Quem
        // escreve SQL à mão assume essa consequência, e é por isso que `--empty` é escotilha
        // e não caminho normal.
        $this->context->directory->write(
            $index,
            $tag,
            '',
            $this->targetSnapshot($previous, $previous->schema, $index),
        );

        $this->context->output->warning(
            sprintf(
                'Migração vazia %04d_%s escrita. O snapshot repete o estado anterior: o que você '
                . 'escrever nela não aparecerá nos diffs futuros.',
                $index,
                $tag,
            ),
        );

        return new GenerateResult(
            $operations,
            writtenFiles: [$this->context->directory->sqlPath($index, $tag)],
            tag: $tag,
            index: $index,
        );
    }

    /**
     * @return list<string>
     */
    private function warningsFor(OperationList $operations, RenameCandidates $candidates): array
    {
        $warnings = [];

        foreach ($operations->destructive() as $operation) {
            $warnings[] = 'Destrutivo: ' . $operation->describe();
        }

        foreach ($candidates->describe() as $candidate) {
            $warnings[] = 'Pode ser rename: ' . $candidate;
        }

        return $warnings;
    }

    /**
     * O nome do arquivo: o que foi pedido, ou algo derivado das operações.
     *
     * Derivar em vez de exigir é o que faz `migration:generate` sem argumento funcionar. E
     * `0003_alter_city` diz mais no `git log` que `0003_migration` — o histórico é o que
     * alguém vai ler dentro de um ano.
     */
    private function tagFor(?string $name, OperationList $operations, int $index): string
    {
        if ($name !== null && trim($name) !== '') {
            $slug = MigrationDirectory::slug($name);

            if ($slug !== '') {
                return $slug;
            }
        }

        $tables = $operations->tables();

        // "É uma migração de criação" não é "só tem CreateTable": uma tabela nova traz junto
        // suas foreign keys, restrições de unicidade e índices, que são operações à parte
        // porque o differ as ordena em fases diferentes. O critério certo é que toda operação
        // caia sobre uma tabela que ESTÁ SENDO CRIADA — aí não há nada preexistente sendo
        // alterado, e `initial` descreve o arquivo melhor que `change_2_tables`.
        $created = [];

        foreach ($operations as $operation) {
            if ($operation instanceof CreateTable) {
                $created[$operation->tableName()] = true;
            }
        }

        $onlyCreates = $created !== [] && count($operations->filter(
            static fn (SchemaOperation $o): bool => !isset($created[$o->tableName()]),
        )) === 0;

        $derived = match (true) {
            $onlyCreates && count($tables) === 1 => 'create_' . $tables[0],
            $onlyCreates => 'initial',
            count($tables) === 1 => 'alter_' . $tables[0],
            default => 'change_' . count($tables) . '_tables',
        };

        return MigrationDirectory::slug($derived) ?: 'migration_' . $index;
    }

    /**
     * Renames aplicados viram metadado do snapshot.
     */
    private function metaFor(OperationList $operations): SnapshotMeta
    {
        $tables = [];
        $columns = [];

        foreach ($operations as $operation) {
            if ($operation instanceof RenameTable) {
                $tables[$operation->from] = $operation->to;
            }

            if ($operation instanceof RenameColumn) {
                $columns[$operation->table . '.' . $operation->from] = $operation->to;
            }
        }

        return new SnapshotMeta($tables, $columns);
    }
}
