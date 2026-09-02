<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Compiler;

use Diogodg\Neoorm\Dialect\Dialect;
use Diogodg\Neoorm\Query\Expr\Aliased;
use Diogodg\Neoorm\Query\Func;
use Diogodg\Neoorm\Query\State\SelectState;

/**
 * Quantas linhas a consulta devolveria.
 *
 * Trocar as colunas por `COUNT(*)` só responde isso quando a consulta não agrupa nem
 * deduplica. Nos outros dois casos a resposta muda de pergunta, em silêncio:
 *
 * - com `GROUP BY`, `COUNT(*)` devolve **uma linha por grupo**, e quem lê a primeira
 *   coluna da primeira linha recebe o tamanho do primeiro grupo achando que recebeu o
 *   total;
 * - com `DISTINCT`, remover as colunas remove também o que estava sendo deduplicado, e
 *   a contagem passa a incluir as repetidas.
 *
 * Nos dois a contagem certa é sobre o resultado da consulta, e não sobre a tabela: daí a
 * subconsulta. É uma linha de SQL a mais e uma classe de erro a menos.
 */
final class CountCompiler
{
    /**
     * O nome do derivado. Exigido pelo MySQL, que recusa subconsulta em `FROM` sem alias.
     */
    private const ALIAS = 'neoorm_count';

    public function __construct(private readonly SelectCompiler $select = new SelectCompiler())
    {
    }

    public function compile(SelectState $state, Dialect $dialect): CompiledQuery
    {
        // Paginação e ordenação saem sempre: contar uma consulta limitada a 10 devolveria
        // no máximo 10, e quem pagina quer o total. A ordenação não muda a contagem e
        // custa no plano — dentro de subconsulta, alguns bancos nem a respeitam.
        $base = $state->withoutPagination()->withoutOrder();

        if (!self::needsSubquery($base)) {
            return $this->select->compile($base->withColumns([Func::count()]), $dialect);
        }

        $compiled = $this->select->compile(self::innerColumns($base), $dialect);

        return new CompiledQuery(
            'SELECT COUNT(*) FROM (' . $compiled->sql . ') AS ' . $dialect->quoteIdentifier(self::ALIAS),
            $compiled->binds,
        );
    }

    /**
     * `HAVING` entra na lista mesmo sem `GROUP BY`: ali ele filtra o agregado da tabela
     * inteira, e a consulta devolve uma linha ou nenhuma. Contar isso é contar o
     * resultado, não as linhas da tabela.
     */
    private static function needsSubquery(SelectState $state): bool
    {
        return $state->distinct || $state->groupBy !== [] || $state->having !== null;
    }

    /**
     * O que a subconsulta seleciona.
     *
     * Com `GROUP BY`, são as próprias expressões do agrupamento — e não as colunas
     * originais: `SELECT "users".* … GROUP BY "users"."status"` é recusado pelos dois
     * bancos, que exigem que toda coluna selecionada esteja agrupada ou agregada.
     *
     * Cada uma sai apelidada por posição. Nada lê esses nomes, mas o MySQL recusa
     * derivado com nome de coluna repetido, e agrupar por `users.id` e `posts.id` num
     * join produziria exatamente isso.
     */
    private static function innerColumns(SelectState $state): SelectState
    {
        if ($state->groupBy === []) {
            // HAVING sem GROUP BY agrega a entrada inteira num único grupo. Preservar a
            // projeção tipada (`table.*`) produziria SQL inválido no PostgreSQL, porque
            // colunas não agregadas não podem ser selecionadas nesse contexto. Uma
            // expressão agregada conserva exatamente a cardinalidade relevante: uma
            // linha quando o HAVING passa, nenhuma quando falha.
            return $state->having === null
                ? $state
                : $state->withColumns([new Aliased(Func::count(), 'c0')]);
        }

        $columns = [];

        foreach ($state->groupBy as $position => $expression) {
            $columns[] = new Aliased($expression, 'c' . $position);
        }

        return $state->withColumns($columns);
    }
}
