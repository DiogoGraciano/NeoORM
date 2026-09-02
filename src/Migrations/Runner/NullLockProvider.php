<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * Não trava nada.
 *
 * Para os testes do runner, que exercitam as guardas com um repositório em memória — não
 * há sessão, então não há o que travar —, e para o caminho de `db:push`, que já roda dentro
 * do lock de quem o chamou.
 */
final class NullLockProvider implements LockProvider
{
    public function acquire(): void
    {
    }

    public function release(): void
    {
    }
}
