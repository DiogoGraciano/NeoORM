<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

enum OrderDirection: string
{
    case Asc = 'ASC';
    case Desc = 'DESC';
}
