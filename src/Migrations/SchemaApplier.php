<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Migrations\Operation\OperationList;
use Diogodg\Neoorm\Migrations\Runner\Executor;
use Diogodg\Neoorm\Support\Output\NullOutput;
use Diogodg\Neoorm\Support\Output\Output;

/**
 * Compila uma lista de operações e manda cada statement, um por vez.
 *
 * Pequeno de propósito: é a única costura entre "decidi o que fazer" e "o banco fez".
 * Tudo acima dela é puro e testável offline; abaixo há um `Executor`, que em teste é
 * um dublê que só anota o que recebeu.
 *
 * Um statement por chamada, e `MYSQL_ATTR_MULTI_STATEMENTS` desligado. Isso é o que
 * permite dizer em qual statement uma migração parou — e no MySQL, que não tem DDL
 * transacional, saber isso é a diferença entre retomar e recomeçar.
 */
final class SchemaApplier
{
    public function __construct(
        private readonly Dialect $dialect,
        private readonly Executor $executor,
        private readonly Output $output = new NullOutput(),
    ) {
    }

    /**
     * @return list<string> os statements executados, na ordem
     */
    public function apply(OperationList $operations): array
    {
        $applied = [];

        foreach ($operations as $operation) {
            $statements = $this->dialect->compile($operation);

            if ($statements === []) {
                continue;
            }

            $this->output->write('  ' . $operation->describe());

            foreach ($statements as $sql) {
                $this->executor->execute($sql);
                $applied[] = $sql;
            }
        }

        return $applied;
    }
}
