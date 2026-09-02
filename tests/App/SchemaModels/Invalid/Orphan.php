<?php

declare(strict_types=1);

namespace Tests\App\SchemaModels\Invalid;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Model com VÁRIOS problemas de uma vez, todos detectáveis sem banco.
 *
 * Existe para provar que `loadValidated()` reúne a lista inteira. O builder antigo
 * lançava de dentro do construtor de `Column`, um problema por execução, e cada correção
 * revelava o seguinte — corrigir cinco erros exigia cinco rodadas.
 */
final class Orphan
{
    public const table = 'orphan';

    public static function table(): Table
    {
        return Table::make(self::table)
            ->columns([
                'id' => Col::int()->primary(),
                // NOT NULL com DEFAULT NULL: sem efeito e sempre errado.
                'nome' => Col::varchar(40)->notNull()->defaultNull(),
            ])
            // FK para tabela que não existe no schema.
            ->foreignKey('nao_existe', 'id')
            // Índice sobre coluna que não existe.
            ->index('orphan_ghost_index', ['fantasma']);
    }
}
