<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\DialectSpecific;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Válido no MySQL, inválido no PostgreSQL.
 *
 * `UNSIGNED` não existe no PostgreSQL, e um `DATETIME` lá seria renderizado como
 * `TIMESTAMP` — a introspecção o traria de volta como TIMESTAMP e a coluna divergiria do
 * model para sempre, sem que migração nenhuma pudesse resolver. Por isso o validador
 * RECUSA declarar o inexprimível em vez de descartá-lo em silêncio.
 *
 * O que este fixture prova é que `loadValidated()` recebe o dialeto e o usa: o mesmo
 * diretório de models tem duas respostas.
 */
final class Counter
{
    public const table = 'counter';

    public static function table(): Table
    {
        return Table::make(self::table)
            ->columns([
                'id' => Col::int()->unsigned()->primary()->autoIncrement(),
                'registrado_em' => Col::dateTime()->notNull(),
            ]);
    }
}
