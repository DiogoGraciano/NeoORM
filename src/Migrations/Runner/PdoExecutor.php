<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

use Diogodg\Neoorm\Migrations\Exception\StatementFailedException;
use PDO;
use Throwable;

/**
 * Manda o statement para o banco, e traduz a falha em algo que diga onde doeu.
 *
 * A mensagem do PDO diz o que o servidor achou; ela não diz qual statement foi.
 * Numa migração de trinta statements, essa diferença é a diferença entre corrigir em
 * um minuto e ir procurar.
 */
final class PdoExecutor implements Executor
{
    public function __construct(private readonly PDO $pdo)
    {
    }

    public function execute(string $sql): void
    {
        try {
            $this->pdo->exec($sql);
        } catch (Throwable $e) {
            throw new StatementFailedException($sql, $e->getMessage(), previous: $e);
        }
    }
}
