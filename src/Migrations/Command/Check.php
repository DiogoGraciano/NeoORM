<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Migrations\Runner\MigrationState;
use Diogodg\Neoorm\Migrations\Runner\MigrationStatusLine;

/**
 * Detecta drift nos três eixos. É o gate de CI.
 *
 * Só lê: não aplica nada, não escreve nada. O valor está em ser rodável em qualquer
 * ambiente, inclusive produção, sem consequência.
 */
final class Check
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function execute(): CheckResult
    {
        $dialect = $this->context->dialect;

        $declared = $dialect->normalizeForStorage(
            $this->context->models->loadValidated($dialect->name()),
        );

        $snapshot = $this->context->directory->latestSnapshot();
        $differ = new SchemaDiffer();

        // Eixo 1: o repositório contra si mesmo. Não envolve banco nenhum, e é o erro mais
        // comum — mudar um model e esquecer de rodar generate.
        $modelsVsSnapshot = $differ->diff($snapshot, $snapshot->withSchema($declared));

        $introspector = $this->context->introspector();
        $live = $introspector->introspect($declared->tableNames());

        // Eixo 2: o snapshot contra o banco. `live` é o lado ESQUERDO porque a pergunta é
        // "o que falta fazer no banco para ele virar o snapshot?".
        $snapshotVsLive = $differ->diff($live, $live->withSchema($snapshot->schema));

        $status = $this->context->runner()->status();

        $result = new CheckResult(
            $modelsVsSnapshot,
            $snapshotVsLive,
            $status->pendingTags(),
            array_map(
                static fn (MigrationStatusLine $l): string => $l->tag,
                array_values(array_filter(
                    $status->lines,
                    static fn (MigrationStatusLine $l): bool => $l->state === MigrationState::Tampered,
                )),
            ),
            $introspector->unknownTables($declared->tableNames(), $this->context->migrationsTable),
            $this->advisoriesFor($live, $snapshot->schema),
        );

        foreach ($result->describe() as $line) {
            $this->context->output->warning($line);
        }

        if ($result->isClean()) {
            $this->context->output->success('Sem drift: models, snapshot e banco concordam.');
        }

        return $result;
    }

    /**
     * Divergências que são REPORTADAS e nunca viram DDL.
     *
     * Expressão de CHECK é o caso: o PostgreSQL não guarda a expressão que recebeu, ele a
     * reescreve — `status in ('a','b')` volta como `status = ANY (ARRAY['a','b'])`. Não é
     * formatação, é outra árvore sintática com o mesmo significado, e reverter exigiria um
     * otimizador de consultas.
     *
     * Gerar `ALTER` a partir de uma comparação com falso positivo conhecido seria pior que
     * não gerar: a migração rodaria, o banco continuaria "divergente", e a próxima execução
     * geraria a mesma migração de novo — para sempre. Então isto é uma nota para quem lê, não
     * uma tarefa para o sistema.
     *
     * @return list<string>
     */
    private function advisoriesFor(
        \Diogodg\Neoorm\Migrations\Snapshot\Snapshot $live,
        \Diogodg\Neoorm\Schema\SchemaDefinition $expected,
    ): array {
        $advisories = [];

        foreach ($expected->tables as $name => $table) {
            $liveTable = $live->table($name);

            if ($liveTable === null) {
                continue;
            }

            foreach ($table->checks as $checkName => $check) {
                $liveCheck = $liveTable->checks[$checkName] ?? null;

                if ($liveCheck === null) {
                    continue;
                }

                if ($liveCheck->normalizedExpression() !== $check->normalizedExpression()) {
                    $advisories[] = sprintf(
                        '%s.%s: a expressão do CHECK no banco (%s) difere da declarada (%s). O '
                        . 'PostgreSQL reescreve expressões ao armazená-las, então isto normalmente '
                        . 'não é divergência real — e por isso nenhum ALTER é gerado.',
                        $name,
                        $checkName,
                        $liveCheck->expression,
                        $check->expression,
                    );
                }
            }
        }

        return $advisories;
    }
}
