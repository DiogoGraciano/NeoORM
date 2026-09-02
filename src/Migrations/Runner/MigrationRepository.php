<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * A tabela de controle, como contrato.
 *
 * É a costura que torna o runner testável sem banco: um repositório em memória responde
 * as mesmas perguntas, e assim as guardas do runner — hash divergente, migração fora de
 * ordem, tag desconhecida, retomada — são exercitadas sem servidor nenhum. Essas guardas
 * são a parte do runner com mais casos e são justamente a parte que ninguém consegue
 * reproduzir à mão num banco de verdade.
 */
interface MigrationRepository
{
    /**
     * Cria a tabela se ela não existe.
     *
     * Chamado SEMPRE fora de transação, e é a razão de este método existir separado. O
     * sistema antigo rodava cinco `CREATE TABLE IF NOT EXISTS` dentro do construtor do
     * rastreador, uma vez por model — e no MySQL cada um desses DDL faz commit implícito,
     * o que furava qualquer transação aberta. Era assim que um rollback deixava dados
     * commitados.
     */
    public function ensureTable(): void;

    public function tableExists(): bool;

    /**
     * @return array<string,MigrationRecord> indexado por tag
     */
    public function all(): array;

    public function find(string $tag): ?MigrationRecord;

    /**
     * Registra o início. Se a tag já existe (retomada), atualiza em vez de duplicar.
     */
    public function start(string $tag, string $hash, int $statements): void;

    /**
     * Marca que os primeiros `$appliedIndex` statements passaram.
     */
    public function progress(string $tag, int $appliedIndex): void;

    public function finish(string $tag, int $statements): void;

    public function fail(string $tag, int $appliedIndex, string $error): void;
}
