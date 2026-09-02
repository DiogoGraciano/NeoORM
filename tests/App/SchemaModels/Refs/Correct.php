<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Refs;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Anotação certa: `ref_correct` gera `RefCorrectTable`.
 *
 * @extends Model<\Tests\Generated\RefCorrectTable>
 */
final class Correct extends Model
{
    public const table = 'ref_correct';

    public static function table(): Table
    {
        return Table::make(self::table)->columns(['id' => Col::id()]);
    }
}
