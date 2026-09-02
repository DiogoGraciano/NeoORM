<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Operation;

use Diogodg\Neoorm\Schema\Naming\IdentifierValidator;

/**
 * Renomeia uma tabela preservando os dados.
 *
 * Só existe porque alguém disse que era um rename: o differ, olhando dois
 * snapshots, vê uma tabela que desapareceu e outra que apareceu, e não tem como
 * saber a diferença entre renomear e recriar do zero. Quem decide é o
 * `RenameResolver`, e a decisão fica gravada em `_meta.renamedTables` do
 * snapshot novo para não ser perguntada duas vezes.
 */
final readonly class RenameTable implements SchemaOperation
{
    public string $from;

    public string $to;

    public function __construct(string $from, string $to)
    {
        $this->from = IdentifierValidator::normalize($from, 'Nome de tabela de origem');
        $this->to = IdentifierValidator::normalize($to, 'Nome de tabela de destino');
    }

    /**
     * O nome de ORIGEM: é o que existe no banco no momento em que a operação
     * roda, e é por ele que o sorter acha as FKs que precisam sair da frente.
     */
    public function tableName(): string
    {
        return $this->from;
    }

    public function isDestructive(): bool
    {
        return false;
    }

    public function discardsData(): bool
    {
        return false;
    }

    public function describe(): string
    {
        return "renomeia a tabela '{$this->from}' para '{$this->to}'";
    }
}
