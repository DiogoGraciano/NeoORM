<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Refs;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Anotação errada — o caso de copiar um model e esquecer de trocar a classe.
 *
 * Nada em tempo de execução a contradiz: `ref()` devolve a tabela certa, e só a IDE e o
 * PHPStan passam a concordar com colunas que não existem.
 *
 * @extends Model<CorrectTable>
 */
final class Wrong extends Model
{
    public const table = 'ref_wrong';

    public static function table(): Table
    {
        return Table::make(self::table)->columns(['id' => Col::id()]);
    }
}
