<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Exception;

use Throwable;

/**
 * Um statement específico foi recusado pelo banco.
 *
 * Guarda o SQL, e não só a mensagem do servidor, porque é o SQL que diz o que
 * corrigir. O sistema antigo enterrava isso em `try { } catch { /* ignora *\/ }` e
 * seguia adiante, o que é a razão de um migrate poder "funcionar" sem ter aplicado
 * metade do que prometeu.
 */
final class StatementFailedException extends MigrationException
{
    public function __construct(
        public readonly string $sql,
        public readonly string $reason,
        ?Throwable $previous = null,
    ) {
        parent::__construct("O banco recusou o statement.\n  SQL: {$sql}\n  Erro: {$reason}", previous: $previous);
    }
}
