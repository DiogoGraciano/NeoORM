<?php

declare(strict_types=1);

namespace Tests\App\RenameModels;

use Diogodg\Neoorm\Migrations\Col;
use Diogodg\Neoorm\Migrations\Table;

/**
 * Root de models só para o teste de convergência (P4).
 *
 * Separado de `tests/App/Models/` porque `PATH_MODEL` é varrido por INTEIRO: estes models
 * são criados e derrubados no meio da suíte, e no root de domínio eles apareceriam no
 * schema dos testes de ORM.
 *
 * O prefixo `zz_` nos nomes de tabela evita colisão com qualquer coisa do schema de
 * domínio — que foi exatamente o problema que os schemas sintéticos do P3 causaram quando
 * usaram `city` e `state`.
 */
final class Box
{
    public const table = 'zz_box';

    public static function table(): Table
    {
        return Table::make(self::table, comment: 'Caixas')
            ->columns([
                'id' => Col::id()->comment('ID da caixa'),
                'name' => Col::varchar(120)->notNull()->comment('Nome'),
                'code' => Col::varchar(40)->notNull()->unique(),
            ]);
    }
}
