<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Runner;

/**
 * Em que pé está uma migração do journal, cruzando repositório e disco.
 *
 * Cinco estados e não dois, porque "aplicada ou pendente" esconde exatamente os casos que
 * fazem uma migração dar errado a três ambientes de distância: o arquivo editado depois de
 * aplicado, o arquivo que desapareceu, e a migração que parou no meio num banco sem DDL
 * transacional.
 */
enum MigrationState: string
{
    case Applied = 'applied';
    case Pending = 'pending';

    /** Começou e não terminou. Só acontece onde DDL não é transacional. */
    case Interrupted = 'interrupted';

    /** Aplicada, mas o arquivo em disco não é mais o que foi aplicado. */
    case Tampered = 'tampered';

    /** Está no journal e o arquivo `.sql` não está no disco. */
    case FileMissing = 'file_missing';

    public function isProblem(): bool
    {
        return $this === self::Tampered || $this === self::FileMissing;
    }
}
