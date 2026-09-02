<?php

declare(strict_types=1);

namespace Tests\Support;

use DateTimeImmutable;
use Diogodg\Neoorm\Query\Database;
use Tests\Generated\Inserts\AppointmentInsert;
use Tests\Generated\Inserts\CityInsert;
use Tests\Generated\Inserts\CountryInsert;
use Tests\Generated\Inserts\EmployeeInsert;
use Tests\Generated\Inserts\ScheduleInsert;
use Tests\Generated\Inserts\StateInsert;
use Tests\Generated\Inserts\UsersInsert;
use Tests\Generated\Tables;

/**
 * Base dos testes da camada de consulta contra um banco de verdade.
 *
 * Cada caso começa do MESMO estado: `setUp` trunca tudo, reinicia os contadores de auto
 * incremento e semeia de novo. Custa um truncate e duas dezenas de inserts, e compra a
 * independência de ordem — o critério de aceitação de "não há dependência oculta entre
 * casos", e o que permite a suíte rodar embaralhada.
 *
 * A suíte de ORM antiga semeava uma vez por classe, e os casos que alteravam dados
 * deixavam o banco diferente para os seguintes: um teste passava porque outro havia
 * rodado antes dele, e só na ordem de declaração.
 *
 * O seed usa os DTOs de INSERT gerados, com argumentos nomeados. É de propósito: assim
 * a montagem do cenário exercita o caminho tipado, e um model de fixture que ganhe uma
 * coluna NOT NULL quebra aqui, em tempo de análise estática, e não numa violação de
 * constraint no meio de um teste sobre outra coisa.
 */
abstract class QueryTestCase extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        SchemaFixture::truncateAll();

        $this->seed();
    }

    protected function db(): Database
    {
        return Database::fromConfig();
    }

    /**
     * Dados determinísticos: dois países, três estados, quatro cidades, três usuários,
     * dois funcionários, duas agendas e cinco compromissos em três status.
     *
     * A forma importa mais que o volume — há mais de uma cidade por estado e mais de um
     * compromisso por status, senão um GROUP BY errado passaria por coincidência.
     */
    protected function seed(): void
    {
        $db = $this->db();

        $brasil = $db->insert(Tables::country())
            ->values(new CountryInsert(name: 'Brasil', abbreviation: 'BR'))->returningOne();
        $argentina = $db->insert(Tables::country())
            ->values(new CountryInsert(name: 'Argentina', abbreviation: 'AR'))->returningOne();

        $sp = $db->insert(Tables::state())->values(new StateInsert(
            name: 'São Paulo',
            abbreviation: 'SP',
            country: $brasil->id,
            ibge: 35,
            area_code: '11',
        ))->returningOne();

        $rj = $db->insert(Tables::state())->values(new StateInsert(
            name: 'Rio de Janeiro',
            abbreviation: 'RJ',
            country: $brasil->id,
            ibge: 33,
            area_code: '21',
        ))->returningOne();

        $ba = $db->insert(Tables::state())->values(new StateInsert(
            name: 'Buenos Aires',
            abbreviation: 'BA',
            country: $argentina->id,
            ibge: 99,
            area_code: '11',
        ))->returningOne();

        foreach ([
            ['São Paulo', $sp->id, 3550308],
            ['Campinas', $sp->id, 3509502],
            ['Rio de Janeiro', $rj->id, 3304557],
            ['La Plata', $ba->id, 9900001],
        ] as [$nome, $estado, $ibge]) {
            $db->insert(Tables::city())
                ->values(new CityInsert(name: $nome, state: $estado, ibge: $ibge))->execute();
        }

        $ana = $db->insert(Tables::users())->values(new UsersInsert(
            name: 'Ana Souza',
            email: 'ana@example.com',
            phone: '11999990001',
            tax_id: '10000000001',
        ))->returningOne();

        $bruno = $db->insert(Tables::users())->values(new UsersInsert(
            name: 'Bruno Lima',
            email: 'bruno@example.com',
            phone: '11999990002',
            tax_id: '10000000002',
        ))->returningOne();

        $db->insert(Tables::users())->values(new UsersInsert(
            name: 'Carla Dias',
            email: 'carla@example.com',
            phone: '11999990003',
            tax_id: '10000000003',
        ))->execute();

        $anaEmp = $this->insertEmployee($ana->id, 'Ana Souza', 'ana.emp@example.com', '20000000001');
        $brunoEmp = $this->insertEmployee($bruno->id, 'Bruno Lima', 'bruno.emp@example.com', '20000000002');

        $manha = $db->insert(Tables::schedule())
            ->values(new ScheduleInsert(name: 'Manhã', company_id: 1, employee_id: $anaEmp))
            ->returningOne();

        $tarde = $db->insert(Tables::schedule())
            ->values(new ScheduleInsert(name: 'Tarde', company_id: 1, employee_id: $brunoEmp))
            ->returningOne();

        foreach ([
            [$ana->id, $manha->id, $anaEmp, '2025-04-16 09:00:00', 'confirmed'],
            [$ana->id, $manha->id, $anaEmp, '2025-04-16 10:00:00', 'confirmed'],
            [$bruno->id, $tarde->id, $brunoEmp, '2025-04-16 14:00:00', 'confirmed'],
            [$bruno->id, $tarde->id, $brunoEmp, '2025-04-17 14:00:00', 'scheduled'],
            [$ana->id, $tarde->id, $brunoEmp, '2025-04-18 15:00:00', 'cancelled'],
        ] as [$usuario, $agenda, $funcionario, $inicio, $status]) {
            $comeco = new DateTimeImmutable($inicio);

            $db->insert(Tables::appointment())->values(new AppointmentInsert(
                user_id: $usuario,
                schedule_id: $agenda,
                employee_id: $funcionario,
                start_date: $comeco,
                end_date: $comeco->modify('+1 hour'),
                status: $status,
            ))->execute();
        }
    }

    private function insertEmployee(int $user, string $name, string $email, string $taxId): int
    {
        return $this->db()->insert(Tables::employee())->values(new EmployeeInsert(
            user_id: $user,
            name: $name,
            email: $email,
            start_time: '08:00:00',
            end_time: '18:00:00',
            days: '1,2,3,4,5',
            tax_id: $taxId,
            phone: '1188887777',
            lunch_start: '12:00:00',
            lunch_end: '13:00:00',
        ))->returningOne()->id;
    }
}
