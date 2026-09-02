<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

/**
 * O que está fora de sincronia entre os models e o código gerado.
 *
 * Existe porque os arquivos gerados são commitados: sem uma conferência, alguém muda o
 * schema, esquece de regerar, e o repositório passa a ter DTOs que descrevem uma tabela
 * que não existe mais. `--check` no CI fecha isso por ~25 linhas.
 */
final readonly class DriftReport
{
    /**
     * @param list<string> $missing deveriam existir e não existem
     * @param list<string> $changed existem com conteúdo diferente do que seria gerado
     * @param list<string> $orphans gerados que não são mais necessários
     */
    public function __construct(
        public array $missing = [],
        public array $changed = [],
        public array $orphans = [],
    ) {
    }

    public function isClean(): bool
    {
        return $this->missing === [] && $this->changed === [] && $this->orphans === [];
    }

    public function summary(): string
    {
        if ($this->isClean()) {
            return 'O código gerado está em dia com os models.';
        }

        $lines = ['O código gerado divergiu dos models. Rode `vendor/bin/neoorm generate:types`.'];

        foreach (['faltando' => $this->missing, 'desatualizado' => $this->changed, 'órfão' => $this->orphans] as $label => $files) {
            foreach ($files as $file) {
                $lines[] = "  {$label}: {$file}";
            }
        }

        return implode("\n", $lines);
    }
}
