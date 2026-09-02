<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Migrations\Diff\SchemaDiffer;
use Diogodg\Neoorm\Migrations\Exception\MigrationException;
use Diogodg\Neoorm\Migrations\SchemaApplier;

/**
 * Converge o banco com os models DIRETO, sem escrever arquivo de migração.
 *
 * Para desenvolvimento e para testes: a iteração em que se muda um model dez vezes por hora
 * e não se quer dez migrações no histórico. O diff é contra o BANCO INTROSPECTADO, não contra
 * um snapshot — é essa a diferença em relação ao `generate`.
 *
 * Recusa em produção. Não porque o SQL seria diferente — `PushEqualsMigrateTest` afirma que
 * não é —, mas porque `push` não deixa rastro: sem arquivo e sem linha na tabela de controle,
 * não há como saber depois o que foi aplicado, nem reproduzir o mesmo estado em outro
 * ambiente. Em produção o histórico é o ativo.
 */
final class Push
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function execute(bool $dryRun = false, bool $allowDestructive = false): PushResult
    {
        if ($this->context->isProduction()) {
            throw new MigrationException(
                'db:push não roda em produção. Ele aplica DDL sem escrever migração nenhuma, então '
                . 'não deixa como reproduzir o estado resultante em outro ambiente. Use '
                . 'migration:generate e migration:up.',
            );
        }

        $dialect = $this->context->dialect;
        $declared = $dialect->normalizeForStorage(
            $this->context->models->loadValidated($dialect->name()),
        );

        // Só as tabelas que os models descrevem. Introspectar o banco inteiro faria o differ
        // ver tabelas de outros sistemas como "a mais" — e o oposto de B3 não é apagar tudo
        // que não se reconhece, é não tocar no que não se declarou.
        $live = $this->context->introspector()->introspect($declared->tableNames());
        $operations = (new SchemaDiffer())->diff($live, $live->withSchema($declared));
        $statements = $dialect->compileAll($operations);

        $warnings = array_map(
            static fn (string $d): string => 'Destrutivo: ' . $d,
            $operations->destructive()->describe(),
        );

        if (count($operations) === 0) {
            $this->context->output->write('Nada a aplicar: o banco já corresponde aos models.');

            return new PushResult($operations, dryRun: $dryRun);
        }

        // Bloqueia o que APAGA, avisa sobre o que é arriscado — a mesma distinção do
        // `generate`, e pelo mesmo motivo: arriscado falha sem perder nada, descartar sucede
        // e perde.
        if ($operations->discardsData() && !$allowDestructive && $this->context->strict) {
            throw new MigrationException(
                "As mudanças apagam dados:\n  - "
                . implode("\n  - ", $operations->dataDiscarding()->describe())
                . "\n\nConfirme com --force. Nada foi aplicado.",
            );
        }

        if ($dryRun) {
            foreach ($operations->describe() as $line) {
                $this->context->output->write('  ' . $line);
            }

            return new PushResult($operations, $statements, $warnings, dryRun: true);
        }

        (new SchemaApplier($dialect, $this->context->executor(), $this->context->output))->apply($operations);

        $this->context->output->success(
            sprintf('Banco convergido: %d statement(s) aplicado(s).', count($statements)),
        );

        return new PushResult($operations, $statements, $warnings);
    }
}
