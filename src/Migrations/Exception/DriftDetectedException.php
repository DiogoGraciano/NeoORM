<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Exception;

/**
 * O banco registra migrações aplicadas que o repositório não conhece.
 *
 * Ou seja: a tabela de controle tem uma tag sem entrada no journal. Normalmente é um
 * checkout mais antigo que o banco — alguém aplicou uma migração e depois trocou de
 * branch — ou um `.sql` apagado do repositório.
 *
 * Isto é o OPOSTO do bug B3 do sistema antigo, que ao encontrar uma tabela pré-existente
 * a considerava sincronizada e gravava um snapshot dizendo isso; a partir dali aquela
 * tabela nunca mais era comparada com nada. Aqui, discordância entre banco e repositório
 * para o comando e diz exatamente qual tag falta.
 */
final class DriftDetectedException extends MigrationException
{
    /**
     * @param list<string> $unknownTags
     */
    public function __construct(public readonly array $unknownTags)
    {
        parent::__construct(
            "O banco tem migrações que este repositório não conhece: '"
            . implode("', '", $unknownTags) . "'.\n"
            . 'O checkout provavelmente está atrás do banco. Atualize o repositório; se as migrações '
            . 'foram removidas de propósito, o banco precisa ser reconciliado à mão.',
        );
    }
}
