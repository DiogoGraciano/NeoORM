<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Command;

use Diogodg\Neoorm\Migrations\Operation\OperationList;

/**
 * Drift em TRÊS eixos, porque "está sincronizado?" tem três respostas diferentes e
 * confundi-las é o que faz um sistema de migração perder a confiança de quem o usa.
 *
 * 1. **models vs snapshot** — alguém mudou um model e esqueceu de rodar `generate`. O
 *    repositório está inconsistente consigo mesmo; nenhum banco está envolvido.
 * 2. **snapshot vs banco** — o banco derivou (alguém rodou DDL à mão) ou faltam migrações.
 * 3. **bookkeeping** — migração pendente, arquivo adulterado, tabela que nenhum model
 *    descreve.
 *
 * Verde só quando os três concordam. O sistema antigo tinha exatamente um eixo — o model
 * contra as tabelas `_schema_*`, que registravam o que o código AFIRMOU ter feito —, e por
 * isso conseguia dizer "sincronizado" com o banco em qualquer estado.
 */
final readonly class CheckResult
{
    /**
     * @param list<string> $pendingTags
     * @param list<string> $tamperedTags
     * @param list<string> $unknownTables tabelas no banco que nenhum model descreve
     * @param list<string> $advisories divergências reportadas e NÃO corrigíveis por DDL
     */
    public function __construct(
        public OperationList $modelsVsSnapshot,
        public OperationList $snapshotVsLive,
        public array $pendingTags = [],
        public array $tamperedTags = [],
        public array $unknownTables = [],
        public array $advisories = [],
    ) {
    }

    public function isClean(): bool
    {
        return count($this->modelsVsSnapshot) === 0
            && count($this->snapshotVsLive) === 0
            && $this->pendingTags === []
            && $this->tamperedTags === [];
    }

    /**
     * Tabela desconhecida NÃO conta como drift por default.
     *
     * É deliberadamente o oposto do bug B3: lá, uma tabela pré-existente era considerada
     * sincronizada para sempre. Aqui ela é REPORTADA, com nome, e nenhum comando gera
     * `DROP TABLE` para ela sem `--include-unknown` — porque a tabela pode ser de outro
     * sistema que compartilha o banco, e apagá-la seria estrago irreversível a partir de uma
     * suposição.
     *
     * `--strict` é para quem sabe que o banco é só desta aplicação.
     */
    public function isCleanStrict(): bool
    {
        return $this->isClean() && $this->unknownTables === [];
    }

    /**
     * @return list<string>
     */
    public function describe(): array
    {
        $lines = [];

        foreach ($this->modelsVsSnapshot->describe() as $operation) {
            $lines[] = "models vs snapshot: {$operation}";
        }

        foreach ($this->snapshotVsLive->describe() as $operation) {
            $lines[] = "snapshot vs banco: {$operation}";
        }

        foreach ($this->pendingTags as $tag) {
            $lines[] = "migração pendente: {$tag}";
        }

        foreach ($this->tamperedTags as $tag) {
            $lines[] = "migração alterada depois de aplicada: {$tag}";
        }

        foreach ($this->unknownTables as $table) {
            $lines[] = "tabela que nenhum model descreve: {$table}";
        }

        foreach ($this->advisories as $advisory) {
            $lines[] = "advisório: {$advisory}";
        }

        return $lines;
    }
}
