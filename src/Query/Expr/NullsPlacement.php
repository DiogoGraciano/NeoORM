<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * Onde os nulos caem numa ordenação.
 *
 * Importa mais do que parece: os dois bancos discordam por padrão. O PostgreSQL
 * trata NULL como o maior valor (primeiro no DESC), o MySQL como o menor
 * (primeiro no ASC). Uma consulta paginada por uma coluna nullable devolve páginas
 * diferentes nos dois bancos sem que nada acuse o problema.
 *
 * Declarar a posição é a forma de a resposta não depender do banco. Só o
 * PostgreSQL tem a sintaxe `NULLS FIRST|LAST`; no MySQL o compilador emula com uma
 * chave de ordenação extra.
 */
enum NullsPlacement: string
{
    case First = 'FIRST';
    case Last = 'LAST';
}
