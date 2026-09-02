<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Exception;

/**
 * Há uma migração pendente com índice MENOR que uma já aplicada.
 *
 * O caso típico: dois branches geraram migrações em paralelo, um mergeou depois de o
 * outro já ter sido aplicado, e agora a `0007` de um está pendente enquanto a `0008` do
 * outro já rodou.
 *
 * Aplicar assim é perigoso justamente porque costuma FUNCIONAR: a `0007` roda, ninguém vê
 * erro, e o banco resultante não é o que nenhum dos dois snapshots descreve — a `0008` foi
 * gerada contra um estado que nunca existiu. A partir daí todo diff é contra um schema
 * imaginário.
 *
 * A saída é renumerar: gerar a migração de novo por cima do estado mergeado.
 */
final class OutOfOrderMigrationException extends MigrationException
{
    /**
     * @param list<string> $pendingTags
     */
    public function __construct(
        public readonly array $pendingTags,
        public readonly string $appliedTag,
        public readonly int $appliedIndex,
    ) {
        parent::__construct(
            "Migração fora de ordem: '" . implode("', '", $pendingTags) . "' está pendente, mas "
            . "'{$appliedTag}' (índice {$appliedIndex}) já foi aplicada.\n"
            . 'Isso é merge de branches paralelos. Renumere a pendente gerando-a de novo sobre o '
            . 'estado atual — aplicá-la agora produziria um banco que nenhum snapshot descreve.',
        );
    }
}
