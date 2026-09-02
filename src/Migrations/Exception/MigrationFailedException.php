<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Exception;

use Throwable;

/**
 * Uma migração parou num statement específico.
 *
 * A mensagem diz QUAL statement, pelo índice e pelo SQL, e o que o servidor respondeu.
 * No MySQL — que não tem DDL transacional — isso não é conveniência de diagnóstico, é o
 * mecanismo de recuperação: a tabela de controle guarda `applied_index`, e reexecutar a
 * migração retoma exatamente dali.
 *
 * O contrato é diferente nos dois bancos, e está escrito aqui porque é aqui que ele
 * aparece:
 *
 * - PostgreSQL: DDL é transacional, então a migração é ATÔMICA. Nada foi aplicado.
 * - MySQL: cada DDL faz commit implícito, então a migração é RETOMÁVEL, não atômica. Os
 *   statements anteriores estão no banco e ficam lá.
 */
final class MigrationFailedException extends MigrationException
{
    public function __construct(
        public readonly string $tag,
        public readonly int $statementIndex,
        public readonly int $statementCount,
        public readonly string $sql,
        public readonly string $reason,
        public readonly bool $atomic,
        ?Throwable $previous = null,
    ) {
        $position = $statementIndex + 1;
        $recovery = $atomic
            ? 'O banco tem DDL transacional: nada desta migração foi aplicado.'
            : "Este banco não tem DDL transacional. Os {$statementIndex} statement(s) anteriores foram "
                . 'aplicados e continuam lá; corrija a causa e rode a migração de novo, que ela retoma '
                . 'deste ponto.';

        parent::__construct(
            "A migração '{$tag}' falhou no statement {$position} de {$statementCount}.\n"
            . "  SQL: {$sql}\n"
            . "  Erro: {$reason}\n"
            . $recovery,
            previous: $previous,
        );
    }
}
