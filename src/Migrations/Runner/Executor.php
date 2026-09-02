<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * Executa um statement. Um só, por chamada.
 *
 * A costura que torna o runner testável sem banco, e a razão de ser um statement por
 * chamada: `MYSQL_ATTR_MULTI_STATEMENTS` fica desligado e cada `exec()` leva um
 * comando. Assim, quando uma migração falha no MySQL — que não tem DDL transacional —
 * dá para dizer exatamente em qual statement ela parou, e retomar dali.
 */
interface Executor
{
    public function execute(string $sql): void;
}
