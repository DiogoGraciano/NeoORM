<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Migrations\Runner\RunnerStatus;

/**
 * Mostra o estado das migrações sem aplicar nada e sem lançar por inconsistência.
 *
 * Não lançar é o ponto do comando: é ele que se roda JUSTAMENTE quando algo está errado, e
 * morrer na primeira inconsistência mostraria uma e esconderia as outras. Quem precisa que
 * inconsistência seja erro usa `db:check`, que é o gate de CI.
 */
final class Status
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function execute(): RunnerStatus
    {
        $status = $this->context->runner()->status();

        if (!$status->controlTableExists) {
            $this->context->output->warning(
                'A tabela de controle ainda não existe: nenhuma migração foi aplicada neste banco.',
            );
        }

        if ($status->lines === []) {
            $this->context->output->write('Nenhuma migração no repositório.');
        }

        foreach ($status->lines as $line) {
            $this->context->output->write($line->describe());
        }

        foreach ($status->unknownTags as $tag) {
            $this->context->output->warning(
                "'{$tag}' está aplicada no banco e não existe neste repositório. O checkout "
                . 'provavelmente está atrás do banco.',
            );
        }

        foreach ($status->orphanFiles as $file) {
            $this->context->output->warning(
                "'{$file}' está no disco e não no journal. Normalmente é merge mal resolvido: a "
                . 'migração precisa ser renumerada.',
            );
        }

        $this->context->output->write(sprintf(
            '%d aplicada(s), %d pendente(s), %d problema(s).',
            count($status->applied()),
            count($status->pending()),
            count($status->problems()),
        ));

        return $status;
    }
}
