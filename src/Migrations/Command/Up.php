<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Migrations\Runner\UpResult;

/**
 * Aplica as migrações pendentes.
 *
 * Uma casca fina sobre o `MigrationRunner`, e fina de propósito: toda a lógica que importa —
 * as guardas, a atomicidade assimétrica, a retomada — está no runner, que é testável sem
 * banco. Um entrypoint gordo seria lógica só exercitável com servidor de pé.
 *
 * NÃO faz seed. Schema e dados são coisas separadas: `db:seed` é quem popula, e misturar os
 * dois era o que fazia o `Migrate` antigo rodar seed no meio do loop de criação, antes de as
 * foreign keys existirem.
 */
final class Up
{
    public function __construct(private readonly MigrationContext $context)
    {
    }

    public function execute(?string $to = null, ?int $step = null, bool $dryRun = false): UpResult
    {
        $result = $this->context->runner()->up($to, $step, $dryRun);

        if ($result->isEmpty()) {
            $this->context->output->write('Nada a aplicar: o banco está na última migração.');

            return $result;
        }

        $verb = $dryRun ? 'aplicaria' : 'aplicou';

        $this->context->output->success(sprintf(
            '%s %d migração(ões), %d statement(s): %s',
            ucfirst($verb),
            count($result->applied),
            $result->statementCount(),
            implode(', ', $result->tags()),
        ));

        if ($result->skipped !== []) {
            $this->context->output->write('Ficaram pendentes: ' . implode(', ', $result->skipped));
        }

        return $result;
    }
}
