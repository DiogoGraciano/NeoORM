<?php

declare(strict_types=1);

namespace Diogodg\Neoorm\Migrations\Seed;

use Diogodg\Neoorm\Query\Executor;

/**
 * Os dados iniciais de uma tabela, num arquivo próprio.
 *
 * ```php
 * namespace App\Seeders;
 *
 * final class StateSeeder extends Seeder
 * {
 *     public static function table(): string
 *     {
 *         return State::table;
 *     }
 *
 *     public function run(Executor $db): void
 *     {
 *         if ($db->select()->from(Tables::state())->count() > 0) {
 *             return;
 *         }
 *
 *         $db->insert(Tables::state())
 *             ->values(new StateInsert(name: 'Acre', abbreviation: 'AC', country: 1, ibge: 12))
 *             ->execute();
 *     }
 * }
 * ```
 *
 * Isto era um `public static function seed()` DENTRO do model. Sair de lá é o ponto:
 * descrever a tabela e povoá-la são trabalhos diferentes, com ritmos diferentes — o
 * schema muda uma vez por trimestre, a lista de estados cresce quando a lei muda — e
 * mantê-los no mesmo arquivo fazia o model de uma tabela de domínio grande ser
 * majoritariamente dado.
 *
 * O executor chega por PARÂMETRO. Antes cada `seed()` chamava `Database::fromConfig()`
 * por conta própria e só por acidente compartilhava o PDO da transação que o
 * `SeedRunner` tinha aberto: bastava alguém passar um `DatabaseConfig` para o seed
 * escrever fora da transação e sobreviver a um rollback. Recebendo o `Tx`, o vínculo é
 * explícito, e um seeder passa a ser testável com um duplo.
 *
 * Ordem NÃO se declara: ela vem do grafo de foreign keys do schema, então um seeder de
 * `state` roda depois do de `country` porque a tabela depende dela, não porque alguém
 * lembrou de dizer isso.
 */
abstract class Seeder
{
    /**
     * A tabela que este seeder popula. Um seeder por tabela.
     *
     * Devolver a constante do model (`State::table`) em vez de repetir a string é o que
     * mantém as duas pontas juntas quando a tabela é renomeada.
     */
    abstract public static function table(): string;

    /**
     * Insere os dados. Roda dentro da transação que o `SeedRunner` abriu.
     *
     * Precisa ser idempotente: `db:seed` pode rodar de novo, e o runner não guarda o que
     * já foi semeado. A guarda usual é conferir se a tabela já tem linha.
     */
    abstract public function run(Executor $db): void;
}
