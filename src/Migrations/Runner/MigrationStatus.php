<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * Em que estado uma migração está na tabela de controle.
 *
 * `Running` existir é o que torna o MySQL recuperável. Lá cada DDL faz commit implícito,
 * então não há como "não ter começado": a linha é inserida como `Running` ANTES do primeiro
 * statement, em autocommit, de propósito visível. Se o processo morrer no meio, a linha
 * fica, com `applied_index` dizendo até onde foi.
 *
 * No PostgreSQL a linha nasce e morre dentro da mesma transação do DDL, então `Running`
 * nunca é observável de fora — e é exatamente isso que significa "atômico".
 */
enum MigrationStatus: string
{
    case Running = 'running';
    case Applied = 'applied';
    case Failed = 'failed';
}
