<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Codegen;

/**
 * O que a escrita fez. Devolvido, não impresso — quem imprime é o CLI.
 */
final readonly class WriteReport
{
    /**
     * @param list<string> $written arquivos criados ou atualizados
     * @param list<string> $unchanged já estavam idênticos
     * @param list<string> $removed gerados que não são mais necessários
     * @param list<string> $foreign arquivos alheios no diretório, preservados
     */
    public function __construct(
        public array $written = [],
        public array $unchanged = [],
        public array $removed = [],
        public array $foreign = [],
    ) {
    }

    public function summary(): string
    {
        $parts = [
            count($this->written) . ' escrito(s)',
            count($this->unchanged) . ' sem mudança',
        ];

        if ($this->removed !== []) {
            $parts[] = count($this->removed) . ' removido(s)';
        }

        if ($this->foreign !== []) {
            $parts[] = count($this->foreign) . ' alheio(s) preservado(s): '
                . implode(', ', $this->foreign);
        }

        return implode(', ', $parts) . '.';
    }
}
