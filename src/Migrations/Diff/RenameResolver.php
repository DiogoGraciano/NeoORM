<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Diff;

/**
 * Decide o que é renomeação e o que é remoção mais criação.
 *
 * O differ não tem como saber. Olhando dois snapshots, uma coluna renomeada e uma
 * coluna removida junto com outra adicionada são o mesmo par de fatos. Adivinhar
 * por semelhança de nome ou de tipo apagaria dados de produção quando o chute
 * errasse, então a decisão entra por aqui, injetada, e o differ continua puro:
 * sem PDO, sem Config, sem sistema de arquivos, sem relógio, sem terminal.
 *
 * Antes de consultar o resolvedor, o differ olha `_meta` do snapshot de destino.
 * Uma decisão já registrada lá é autoridade e não é perguntada de novo — é o que
 * faz um `generate` em CI, sem ninguém para responder, chegar ao mesmo resultado
 * que a máquina de quem gerou a migração.
 */
interface RenameResolver
{
    /**
     * @param list<string> $removed tabelas que só existem no schema anterior
     * @param list<string> $added   tabelas que só existem no schema novo
     * @return array<string,string> nome antigo => nome novo, só os pares confirmados
     */
    public function resolveTables(array $removed, array $added): array;

    /**
     * @param list<string> $removed colunas que só existem no schema anterior
     * @param list<string> $added   colunas que só existem no schema novo
     * @return array<string,string> nome antigo => nome novo, só os pares confirmados
     */
    public function resolveColumns(string $table, array $removed, array $added): array;
}
