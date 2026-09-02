<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Query\Expr;

/**
 * Um nó de expressão SQL.
 *
 * A interface é vazia, e isso é o desenho, não preguiça: **nós não sabem virar
 * SQL**. Não existe `compile()` aqui.
 *
 * A alternativa — cada nó saber se compilar — obriga a passar dialeto e coletor de
 * binds por toda a recursão, e o preço aparece em três lugares: um nó só pode ser
 * testado executando a compilação inteira, a mesma árvore não pode render dois
 * dialetos, e a decisão de como o `ILIKE` vira SQL no MySQL fica espalhada por
 * dentro do nó em vez de morar no dialeto.
 *
 * Aqui o nó é dado puro. Quem traduz é `Query\Compiler\ExpressionCompiler`, que
 * recebe a árvore e um `Dialect`, e por isso um teste de AST afirma sobre um grafo
 * de objetos — sem banco, sem SQL, sem dialeto.
 */
interface Expression
{
}
