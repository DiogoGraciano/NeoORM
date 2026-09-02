<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Refs;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Sem anotação. Não é erro: `ref()` degrada para `Query\Table` e segue funcionando.
 */
final class Unannotated extends Model
{
    public const table = 'ref_unannotated';

    public static function table(): Table
    {
        return Table::make(self::table)->columns(['id' => Col::id()]);
    }
}
