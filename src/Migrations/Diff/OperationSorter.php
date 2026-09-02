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
use Diogodg\Neoorm\Migrations\Operation\RawSql;
use Diogodg\Neoorm\Migrations\Operation\RenameColumn;
use Diogodg\Neoorm\Migrations\Operation\RenameTable;
use Diogodg\Neoorm\Migrations\Operation\SchemaOperation;
use Diogodg\Neoorm\Migrations\Operation\SetTableComment;
use Diogodg\Neoorm\Migrations\Operation\SetTableOptions;
use Diogodg\Neoorm\Schema\SchemaDefinition;

/**
 * Coloca as operações na ordem em que o banco as aceita.
 *
 * Um pipeline fixo, sempre o mesmo, em vez de "resolver dependências conforme
 * aparecem". A diferença prática está numa linha do pipeline: como TODA foreign
 * key sai na P0 e volta na P8, deixa de existir a pergunta "esta tabela já
 * existe?" no momento de criar uma referência. Com ela desaparecem, de uma vez, o
 * segundo passe de foreign keys, a dependência da ordem alfabética dos arquivos de
 * model, e o `ADD CONSTRAINT` sem idempotência que fazia a segunda execução do
 * migrate antigo estourar com "constraint já existe".
 *
 * As fases, na ordem: solta as referências, solta os índices, solta a chave
 * primária, renomeia, cria tabelas, mexe nas colunas, refaz a chave primária,
 * refaz índices, refaz referências, e só então remove coluna e tabela. Remover
 * por último é o que dá a toda operação anterior um schema onde tudo em que ela
 * toca ainda existe.
 *
 * A fronteira da P3 é uma regra que vale ter na cabeça ao ler o differ: operação
 * ANTES da P3 usa o nome ANTIGO da tabela (é o nome que está no banco naquele
 * momento); operação depois usa o novo.
 */
final class OperationSorter
{
    /**
     * As fases, em ordem. A P3 do desenho aparece aqui como duas etapas, porque
     * renomear a tabela antes de renomear suas colunas não é preferência: as
     * operações de coluna já vêm com o nome novo da tabela.
     *
     * @var list<list<class-string<SchemaOperation>>>
     */
    private const PIPELINE = [
        [DropForeignKey::class],
        [DropIndex::class, DropUniqueConstraint::class, DropCheckConstraint::class],
        [DropPrimaryKey::class],
        [RenameTable::class],
        [RenameColumn::class],
        [CreateTable::class],
        [AddColumn::class, AlterColumn::class, SetTableComment::class, SetTableOptions::class],
        [AddPrimaryKey::class],
        [AddUniqueConstraint::class, CreateIndex::class, AddCheckConstraint::class],
        [AddForeignKey::class],
        [DropColumn::class],
        [DropTable::class],
    ];

    public function sort(
        OperationList $operations,
        SchemaDefinition $from,
        SchemaDefinition $to,
    ): OperationList {
        $buckets = array_fill(0, count(self::PIPELINE), []);

        foreach ($operations as $operation) {
            $buckets[$this->phaseOf($operation)][] = $operation;
        }

        $sorted = [];

        foreach ($buckets as $phase => $bucket) {
            $sorted = [...$sorted, ...$this->orderWithinPhase($phase, $bucket, $from, $to)];
        }

        return new OperationList($sorted);
    }

    private function phaseOf(SchemaOperation $operation): int
    {
        if ($operation instanceof RawSql) {
            // Não há resposta correta: SQL escrito à mão não tem como ser
            // classificado, e reordená-lo por chute mudaria o que a pessoa quis
            // dizer. Uma migração com RawSql é escrita na ordem em que vai rodar.
            throw new MigrationException(
                'O OperationSorter não ordena RawSql: SQL escrito à mão roda na ordem em que foi '
                . 'escrito. Este ordenador serve à saída do differ, que nunca produz RawSql.',
            );
        }

        foreach (self::PIPELINE as $phase => $classes) {
            foreach ($classes as $class) {
                if ($operation instanceof $class) {
                    return $phase;
                }
            }
        }

        throw new MigrationException(
            'Operação fora do pipeline de ordenação: ' . $operation::class
            . '. Toda operação nova precisa de uma fase, senão rodaria em posição indefinida.',
        );
    }

    /**
     * Dentro de uma fase, a ordem de entrada é preservada.
     *
     * O differ constrói percorrendo mapas ordenados por nome, então a ordem natural
     * já é determinística E agrupa as operações de uma mesma tabela — que lê muito
     * melhor do que ordenar por nome de objeto atravessando tabelas. O efeito é que
     * as fases de restrição saem em ordem alfabética de tabela, e as de criação e
     * remoção em ordem topológica: o `.sql` cria de pai para filho e depois anexa as
     * restrições em ordem alfabética.
     *
     * A inconsistência entre as duas ordens é aceita de propósito. Tornar todas
     * topológicas exigiria decidir, por fase, qual dos dois schemas manda — e seria
     * uma decisão nova para errar em troca de nada, porque nenhuma dessas ordens é
     * necessária para o banco aceitar o resultado.
     *
     * @param list<SchemaOperation> $bucket
     * @return list<SchemaOperation>
     */
    private function orderWithinPhase(
        int $phase,
        array $bucket,
        SchemaDefinition $from,
        SchemaDefinition $to,
    ): array {
        if ($bucket === [] || count($bucket) === 1) {
            return $bucket;
        }

        $creates = self::PIPELINE[$phase] === [CreateTable::class];
        $drops = self::PIPELINE[$phase] === [DropTable::class];

        if (!$creates && !$drops) {
            return $bucket;
        }

        $graph = DependencyGraph::fromSchema($creates ? $to : $from);
        $names = array_map(static fn (SchemaOperation $o): string => $o->tableName(), $bucket);
        $order = $graph->orderOf($names);

        if ($drops) {
            $order = array_reverse($order);
        }

        $byTable = [];

        foreach ($bucket as $operation) {
            $byTable[$operation->tableName()][] = $operation;
        }

        $ordered = [];

        foreach ($order as $table) {
            foreach ($byTable[$table] ?? [] as $operation) {
                $ordered[] = $operation;
            }
        }

        return $ordered;
    }
}
