<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Diogodg\Neoorm\Connection;
use Tests\App\Models\Appointment;
use Tests\App\Models\City;
use Tests\App\Models\Client;
use Tests\App\Models\Country;
use Tests\App\Models\Employee;
use Tests\App\Models\Schedule;
use Tests\App\Models\ScheduleUser;
use Tests\App\Models\State;
use Tests\App\Models\User;

class NeoOrmTest extends TestCase
{
    /**
     * @var Connection
     */
    protected static $connection;

    /**
     * Configure o ambiente de teste antes de executar qualquer teste
     */

    public static function setUpBeforeClass(): void
    {
        // Inicie uma migração limpa para o banco de dados de teste
        (new \Diogodg\Neoorm\Migrations\Migrate)->execute(true);
        
        // Inicialize alguns dados para testes
        self::seedTestData();
    }

    /**
     * Configure dados iniciais para testes
     */
    protected static function seedTestData(): void
    {
        // Crie um país para testes
        $country = new Country();
        $country->name = 'Test Country';
        $country->abbreviation = 'TC';
        $country->store();

        $country = new Client();
        $country->name = 'Test Client';
        $country->store();

        // Crie um estado para testes
        $state = new State();
        $state->name = 'Test State';
        $state->abbreviation = 'TS';
        $state->country = $country->id;
        $state->ibge = 99;
        $state->area_code = '99';
        $state->store();

        // Crie uma cidade para testes
        $city = new City();
        $city->name = 'Test City';
        $city->state = $state->id;
        $city->ibge = 9999;
        $city->store();

        // Crie um usuário para testes
        $user = new User();
        $user->name = 'Test User';
        $user->email = 'test@example.com';
        $user->phone = '5599999999';
        $user->tax_id = '12345678900';
        $user->store();

        // Crie um funcionário para testes
        $employee = new Employee();
        $employee->user_id = $user->id;
        $employee->name = 'Test Employee';
        $employee->tax_id = '00987654321';
        $employee->email = 'employee@example.com';
        $employee->phone = '5588888888';
        $employee->start_time = '08:00:00';
        $employee->end_time = '18:00:00';
        $employee->lunch_start = '12:00:00';
        $employee->lunch_end = '13:00:00';
        $employee->days = '1,2,3,4,5';
        $employee->store();

        // Crie uma agenda para testes
        $schedule = new Schedule();
        $schedule->name = 'Test Schedule';
        $schedule->company_id = 1;
        $schedule->employee_id = $employee->id;
        $schedule->store();

        // Crie um agendamento para testes
        $appointment = new Appointment();
        $appointment->user_id = $user->id;
        $appointment->schedule_id = $schedule->id;
        $appointment->client_id = 1;
        $appointment->employee_id = $employee->id;
        $appointment->start_date = '2025-04-16 10:00:00';
        $appointment->end_date = '2025-04-16 11:00:00';
        $appointment->status = 'confirmed';
        $appointment->store();
    }

    /**
     * Teste de seleção de registro por ID
     */
    public function testGetById(): void
    {
        // Obtenha um funcionário existente
        $employee = (new Employee())->getAll()[0];
        $id = $employee->id;

        // Teste a seleção por ID
        $result = (new Employee())->get($id);

        $this->assertNotNull($result);
        $this->assertEquals($id, $result->id);
        $this->assertEquals('Test Employee', $result->name);
    }

    /**
     * Teste de seleção de registro por campo específico
     */
    public function testGetByField(): void
    {
        // Teste a seleção por nome
        $result = (new Employee())->get('Test Employee', 'name');

        $this->assertNotNull($result);
        $this->assertEquals('Test Employee', $result->name);
        $this->assertEquals('00987654321', $result->tax_id);
    }

    /**
     * Teste de seleção de todos os registros
     */
    public function testGetAll(): void
    {
        $result = (new Employee())->getAll();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertGreaterThanOrEqual(1, count($result));
        $this->assertEquals('Test Employee', $result[0]->name);
    }

    /**
     * Teste de seleção com filtros
     */
    public function testSelectWithFilters(): void
    {
        $start_date = '2025-04-16 00:00:00';
        $end_date = '2025-04-16 23:59:59';
        $schedule_id = (new Schedule())->getAll()[0]->id;

        $db = new Appointment();
        $results = $db->addFilter("start_date", ">=", $start_date)
                      ->addFilter("end_date", "<=", $end_date)
                      ->addFilter("schedule_id", "=", intval($schedule_id))
                      ->addFilter("status", "!=", "cancelled")
                      ->selectAll();

        $this->assertIsArray($results);
        $this->assertNotEmpty($results);
        $this->assertEquals($schedule_id, $results[0]->schedule_id);
        $this->assertEquals('confirmed', $results[0]->status);
    }

    /**
     * Teste de seleção com joins e filtros
     */
    public function testSelectWithJoinsAndFilters(): void
    {
        $company_id = 1;

        $db = new Appointment();
        $result = $db->addJoin("users", "users.id", "appointment.user_id","LEFT")
                     ->addJoin("schedule", "schedule.id", "appointment.schedule_id")
                     ->addJoin("client", "client.id", "appointment.client_id","LEFT")
                     ->addJoin("employee", "employee.id", "appointment.employee_id")
                     ->addFilter("schedule.company_id", "=", $company_id)
                     ->selectColumns("appointment.id", "users.tax_id", ["client.name","client_name"], 
                                     new \Diogodg\Neoorm\Definitions\Raw("users.name as user_name"), 
                                     "users.email", "users.phone", ["schedule.name","schedule_name"], 
                                     ["employee.name","employee_name"], "start_date", "end_date");

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Verifique se as colunas selecionadas estão presentes
        $this->assertNotNull($result[0]->id);
        $this->assertNotNull($result[0]->tax_id);
        $this->assertNotNull($result[0]->user_name);
        $this->assertNotNull($result[0]->schedule_name);
        $this->assertNotNull($result[0]->employee_name);
        $this->assertEquals('Test Schedule', $result[0]->schedule_name);
        $this->assertEquals('Test Employee', $result[0]->employee_name);
    }

    /**
     * Teste de seleção com filtros e limite
     */
    public function testSelectWithFiltersAndLimit(): void
    {
        $name = 'Test';

        $db = new City();
        $result = $db->addFilter("name", "LIKE", "%" . $name . "%")
                     ->addLimit(1)
                     ->selectAll();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertCount(1, $result);
        $this->assertEquals('Test City', $result[0]->name);
    }

    /**
     * Teste de inserção de registro
     */
    public function testInsert(): void
    {
        $values = new Employee();
        $values->user_id = (new User())->getAll()[0]->id;
        $values->name = 'New Test Employee';
        $values->tax_id = '11122233344';
        $values->email = 'new.employee@example.com';
        $values->phone = '5577777777';
        $values->start_time = '09:00:00';
        $values->end_time = '17:00:00';
        $values->lunch_start = '12:00:00';
        $values->lunch_end = '13:00:00';
        $values->days = '1,3,5';

        $result = $values->store();

        $this->assertNotFalse($result);

        // Verifique se o registro foi realmente inserido
        $inserted = (new Employee())->get($values->id);
        $this->assertNotNull($inserted);
        $this->assertEquals('New Test Employee', $inserted->name);
        $this->assertEquals('11122233344', $inserted->tax_id);
    }

    /**
     * Teste de atualização de registro
     */
    public function testUpdate(): void
    {
        // Primeiro, obtenha um registro existente
        $employee = (new Employee())->get('Test Employee', 'name');
        
        // Faça algumas alterações
        $employee->name = 'Updated Employee';
        $employee->email = 'updated.employee@example.com';
        
        // Armazene as alterações
        $result = $employee->store();
        
        $this->assertNotFalse($result);
        // Verifique se as alterações foram salvas
        $updated = (new Employee())->get($employee->id);
        $this->assertEquals('Updated Employee', $updated->name);
        $this->assertEquals('updated.employee@example.com', $updated->email);
    }

    /**
     * Teste de exclusão de registro por ID
     */
    public function testDeleteById(): void
    {
        // Crie um registro para excluir
        $employee = new Employee();
        $employee->user_id = (new User())->getAll()[0]->id;
        $employee->name = 'Employee To Delete';
        $employee->tax_id = '55566677788';
        $employee->email = 'delete.me@example.com';
        $employee->phone = '5566666666';
        $employee->start_time = '09:00:00';
        $employee->end_time = '17:00:00';
        $employee->lunch_start = '12:00:00';
        $employee->lunch_end = '13:00:00';
        $employee->days = '2,4';
        $employee->store();

        $this->assertNotNull($employee);

        $id = $employee->id;

        $employee = new Employee();
        $result = $employee->delete($id);
        $this->assertTrue($result);

        $deleted = (new Employee())->get($id);
        $this->assertNull($deleted->id);
    }

    /**
     * Teste de exclusão de registro por filtro
     */
    public function testDeleteByFilter(): void
    {
        // Crie um registro para excluir
        $employee = new Employee();
        $employee->user_id = (new User())->getAll()[0]->id;
        $employee->name = 'Filter Delete Employee';
        $employee->tax_id = '99988877766';
        $employee->email = 'filter.delete@example.com';
        $employee->phone = '5555555555';
        $employee->start_time = '09:00:00';
        $employee->end_time = '17:00:00';
        $employee->lunch_start = '12:00:00';
        $employee->lunch_end = '13:00:00';
        $employee->days = '1,3,5';
        $employee->store();

        // Confirme que o registro existe
        $check = (new Employee())->get('Filter Delete Employee', 'name');
        $this->assertNotNull($check->id);

        // Exclua o registro por filtro
        $db = new Employee();
        $result = $db->addFilter("name", "=", "Filter Delete Employee")->deleteByFilter();

        $this->assertTrue($result);

        // Verifique se o registro foi excluído
        $deleted = (new Employee())->get('Filter Delete Employee', 'name');
        $this->assertNull($deleted->id);
    }

    /**
     * Teste de transações
     */
    public function testTransactions(): void
    {
        // Inicie uma transação
        Connection::beginTransaction();

        try {
            // Crie uma agenda
            $schedule = new Schedule();
            $schedule->name = 'Transaction Schedule';
            $schedule->company_id = 1;
            $schedule->employee_id = (new Employee())->getAll()[0]->id;
            $result = $schedule->store();

            $this->assertNotFalse($result);

            // Obtenha o ID do usuário para associar à agenda
            $userId = (new User())->getAll()[0]->id;

            // Crie a associação entre usuário e agenda
            $scheduleUser = new ScheduleUser();
            $scheduleUser->user_id = $userId;
            $scheduleUser->schedule_id = $schedule->id;
            $resultUser = $scheduleUser->store();

            $this->assertNotFalse($resultUser);

            // Commit a transação
            Connection::commit();

            // Verifique se os registros foram salvos
            $savedSchedule = (new Schedule())->get($schedule->id);
            $this->assertNotNull($savedSchedule);
            $this->assertEquals('Transaction Schedule', $savedSchedule->name);

            // Verifique a associação
            $db = new ScheduleUser();
            $savedAssociation = $db->addFilter('schedule_id', '=', $schedule->id)
                                   ->addFilter('user_id', '=', $userId)
                                   ->selectAll();
            $this->assertNotEmpty($savedAssociation);

        } catch (\Exception $e) {
            Connection::rollBack();
            $this->fail('A transação falhou: ' . $e->getMessage());
        }
    }

    /**
     * Teste de rollback de transação
     */
    public function testTransactionRollback(): void
    {
        // Obtenha a contagem atual de agendas
        $initialCount = count((new Schedule())->getAll());

        // Inicie uma transação
        Connection::beginTransaction();

        try {
            // Crie uma agenda
            $schedule = new Schedule();
            $schedule->name = 'Rollback Schedule';
            $schedule->company_id = 1;
            $schedule->employee_id = (new Employee())->getAll()[0]->id;
            $result = $schedule->store();

            $this->assertNotFalse($result);
            $scheduleId = $schedule->id;

            // Forçar um erro para acionar o rollback
            throw new \Exception('Teste de rollback');

            // Este código não deve ser executado
            Connection::commit();

        } catch (\Exception $e) {
            // Faça o rollback da transação
            Connection::rollBack();

            // Verifique se o número de agendas é o mesmo de antes
            $afterCount = count((new Schedule())->getAll());
            $this->assertEquals($initialCount, $afterCount);

            // Verifique se a agenda não foi salva
            $notSavedSchedule = (new Schedule())->get($scheduleId ?? 0);
            $this->assertNull($notSavedSchedule->id);
        }
    }

    /**
     * Teste de criação e modificação de tabela
     */
    public function testTableCreation(): void
    {
        // Esta é uma verificação básica para garantir que as tabelas foram criadas
        $this->assertTrue(class_exists('Tests\App\Models\State'));
        $this->assertTrue(class_exists('Tests\App\Models\City'));
        $this->assertTrue(class_exists('Tests\App\Models\Country'));
        
        // Verifique se os dados de seed foram inseridos
        $countries = (new Country())->getAll();
        $this->assertNotEmpty($countries);
        
        $states = (new State())->getAll();
        $this->assertNotEmpty($states);
        
        $cities = (new City())->getAll();
        $this->assertNotEmpty($cities);
    }
}