<?php

declare(strict_types=1);

/**
 * A prova de que a tipagem funciona.
 *
 * Este arquivo não é executado: é lido pelo PHPStan, e `assertType()` falha a análise
 * quando o tipo inferido difere do declarado. É o **único** lugar do projeto que
 * verifica os genéricos — um erro de docblock no `SelectBuilder` não quebraria teste
 * nenhum, só degradaria `all()` para `array<int,object>` em silêncio, e quem usa a
 * biblioteca perderia o autocomplete sem entender por quê.
 *
 * Roda com `composer test:types`.
 */

namespace Tests\Types;

use Diogodg\Neoorm\Query\Database;
use Tests\Golden\Codegen\Inserts\UsersInsert;
use Tests\Golden\Codegen\Tables;

use function Diogodg\Neoorm\Query\eq;
use function PHPStan\Testing\assertType;

function selectGenerics(Database $db): void
{
    $u = Tables::users();

    // O tipo entra por from(), e não por select(): quando select() é chamado ainda não
    // se sabe qual é a tabela.
    assertType('list<Tests\Golden\Codegen\Rows\UsersRow>', $db->select()->from($u)->all());

    // As cláusulas preservam o tipo da linha.
    assertType(
        'list<Tests\Golden\Codegen\Rows\UsersRow>',
        $db->select()->from($u)->where(eq($u->id, 1))->limit(10)->all(),
    );

    assertType('Tests\Golden\Codegen\Rows\UsersRow|null', $db->select()->from($u)->one());
    assertType('Tests\Golden\Codegen\Rows\UsersRow', $db->select()->from($u)->oneOrFail());
    assertType('Generator<int, Tests\Golden\Codegen\Rows\UsersRow, mixed, mixed>', $db->select()->from($u)->cursor());
    assertType('int', $db->select()->from($u)->count());

    // A coluna carrega o tipo declarado no schema.
    assertType('int', $db->select()->from($u)->all()[0]->id);
    // `numeric-string` e não `string`: o refinamento do docblock chega até aqui.
    assertType('numeric-string', $db->select()->from($u)->all()[0]->balance);
    assertType('bool', $db->select()->from($u)->all()[0]->active);
    assertType('DateTimeImmutable', $db->select()->from($u)->all()[0]->created_at);
    assertType('Tests\Golden\Codegen\Enums\UsersStatus', $db->select()->from($u)->all()[0]->status);
    assertType('string|null', $db->select()->from($u)->all()[0]->phone);

    // Seleção parcial: array, e isso está dito no tipo em vez de escondido. É a
    // concessão que o PHP impõe por não ter tipo estrutural.
    assertType('list<array<string, mixed>>', $db->selectFields([$u->id])->from($u)->all());

    // Escrita: o RETURNING devolve a linha tipada.
    assertType(
        'Tests\Golden\Codegen\Rows\UsersRow',
        $db->insert($u)->values(new UsersInsert(name: 'Diogo'))->returningOne(),
    );

    assertType('int', $db->update($u)->set(['phone' => 'x'])->where(eq($u->id, 1))->execute());
    assertType('int', $db->delete($u)->where(eq($u->id, 1))->execute());
}
