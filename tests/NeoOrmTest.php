<?php

namespace Tests;

use PHPUnit\Framework\TestCase;
use Diogodg\Neoorm\Connection;
use Diogodg\Neoorm\Enums\OrderCondition;
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
        $state->ibge = random_int(1, 99999);
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
     * Limpa todas as tabelas de teste
     */
    protected static function clearTables(): void
    {
        $pdo = Connection::getConnection();
        
        $pdo->exec("DROP TABLE IF EXISTS schedule_user");
        $pdo->exec("DROP TABLE IF EXISTS appointment");
        $pdo->exec("DROP TABLE IF EXISTS schedule_employee");
        $pdo->exec("DROP TABLE IF EXISTS schedule");
        $pdo->exec("DROP TABLE IF EXISTS employee");
        $pdo->exec("DROP TABLE IF EXISTS users");
        $pdo->exec("DROP TABLE IF EXISTS city");
        $pdo->exec("DROP TABLE IF EXISTS state");
        $pdo->exec("DROP TABLE IF EXISTS country");
        $pdo->exec("DROP TABLE IF EXISTS client");
        $pdo->exec("DROP TABLE IF EXISTS users");
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
     * Teste de multiplos ordenamentos
    */
    public function testMultipleOrder(): void
    {
        $db = new Country();
        $db->name = "Test Country";
        $db->abbreviation = "T1";
        $db->store();

        $db = new Country();
        $db->name = "Test Country 2";
        $db->abbreviation = "T2";
        $db->store();

        $db = new Country();
        $db->name = "Test Country 3";
        $db->abbreviation = "T3";
        $db->store();

        $result = $db->addOrder("id", OrderCondition::ASC)
                     ->addOrder("name", OrderCondition::DESC)
                     ->addOrder("abbreviation", OrderCondition::ASC)
                     ->selectAll();

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
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
            $afterCount = (new Schedule())->count();
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

    /**
     * Teste de paginação
     */
    public function testPagination(): void
    {
        // Criar alguns registros adicionais para testar paginação
        for ($i = 1; $i <= 25; $i++) {
            $employee = new Employee();
            $employee->user_id = (new User())->getAll()[0]->id;
            $employee->name = "Test Employee {$i}";
            $employee->tax_id = "11122233345{$i}";
            $employee->email = "employee{$i}@example.com";
            $employee->phone = "5588888888{$i}";
            $employee->start_time = '08:00:00';
            $employee->end_time = '18:00:00';
            $employee->lunch_start = '12:00:00';
            $employee->lunch_end = '13:00:00';
            $employee->days = '1,2,3,4,5';
            $employee->store();
        }

        $db = new Employee();
        
        // Teste página 1 com limite padrão (15)
        $result = $db->paginate(1)->selectAll();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertCount(15, $result);
        $this->assertEquals(1, $db->getCurrentPage());
        $this->assertEquals(15, $db->getLimit());
        $this->assertEquals(0, $db->getOffset());
        $this->assertEquals(1, $db->getPreviousPage());
        $this->assertEquals(2, $db->getNextPage());
        $this->assertEquals(2, $db->getLastPage());

        // Teste página 2 com limite personalizado (10)
        $result = $db->paginate(2, 10)->selectAll();
        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertCount(10, $result);
        $this->assertEquals(2, $db->getCurrentPage());
        $this->assertEquals(10, $db->getLimit());
        $this->assertEquals(10, $db->getOffset());
        $this->assertEquals(1, $db->getPreviousPage());
        $this->assertEquals(3, $db->getNextPage());
        $this->assertEquals(3, $db->getLastPage());

        // Teste página inválida (deve retornar página 1)
        $result = $db->paginate(0, 10)->selectAll();
        $this->assertEquals(1, $db->getCurrentPage());
        $this->assertEquals(0, $db->getOffset());

        // Teste limite inválido (deve usar limite padrão 15)
        $result = $db->paginate(1, 0)->selectAll();
        $this->assertEquals(15, $db->getLimit());

        // Teste última página
        $result = $db->paginate(3, 10)->selectAll();
        $this->assertEquals(3, $db->getCurrentPage());
        $this->assertEquals(3, $db->getNextPage()); // Não deve avançar além da última página
        $this->assertEquals(2, $db->getPreviousPage());
    }

    /**
     * Teste da função de limpeza de tabelas
     */
    public static function tearDownAfterClass(): void
    {
        self::clearTables();
    }

    /**
     * Teste de agrupamento simples com GROUP BY
     */
    public function testSimpleGroupBy(): void
    {
        // Criar alguns funcionários com diferentes usuários para testar agrupamento
        $users = (new User())->getAll();
        $userId = $users[0]->id;

        // Criar múltiplos funcionários para o mesmo usuário
        for ($i = 1; $i <= 3; $i++) {
            $employee = new Employee();
            $employee->user_id = $userId;
            $employee->name = "Group Test Employee {$i}";
            $employee->tax_id = "12345678{$i}00";
            $employee->email = "group{$i}@example.com";
            $employee->phone = "559999999{$i}";
            $employee->start_time = '08:00:00';
            $employee->end_time = '18:00:00';
            $employee->lunch_start = '12:00:00';
            $employee->lunch_end = '13:00:00';
            $employee->days = '1,2,3,4,5';
            $employee->store();
        }

        $db = new Employee();
        $result = $db->addGroup('user_id')
                     ->selectColumns('user_id', new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_employees'));

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Verificar se existe pelo menos um grupo com o user_id testado
        $found = false;
        foreach ($result as $row) {
            if ($row->user_id == $userId) {
                $this->assertGreaterThanOrEqual(3, (int)$row->total_employees);
                $found = true;
                break;
            }
        }
        $this->assertTrue($found, "Grupo com user_id {$userId} não foi encontrado");
    }

    /**
     * Teste de agrupamento múltiplo com GROUP BY
     */
    public function testMultipleGroupBy(): void
    {
        // Criar agendamentos em datas diferentes para o mesmo funcionário
        $employee = (new Employee())->getAll()[0];
        $user = (new User())->getAll()[0];
        $schedule = (new Schedule())->getAll()[0];

        $dates = ['2025-04-17', '2025-04-18', '2025-04-17'];
        $statuses = ['confirmed', 'pending', 'confirmed'];

        for ($i = 0; $i < 3; $i++) {
            $appointment = new Appointment();
            $appointment->user_id = $user->id;
            $appointment->schedule_id = $schedule->id;
            $appointment->client_id = 1;
            $appointment->employee_id = $employee->id;
            $appointment->start_date = $dates[$i] . ' 10:00:00';
            $appointment->end_date = $dates[$i] . ' 11:00:00';
            $appointment->status = $statuses[$i];
            $appointment->store();
        }

        $db = new Appointment();
        $result = $db->addGroup('employee_id', 'status')
                     ->selectColumns(
                         'employee_id', 
                         'status', 
                         new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_appointments')
                     );

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Verificar se temos agrupamentos por employee_id e status
        foreach ($result as $row) {
            $this->assertNotNull($row->employee_id);
            $this->assertNotNull($row->status);
            $this->assertGreaterThan(0, (int)$row->total_appointments);
        }
    }

    /**
     * Teste de GROUP BY com HAVING
     */
    public function testGroupByWithHaving(): void
    {
        // Criar mais alguns funcionários para garantir que temos dados suficientes
        $users = (new User())->getAll();
        $userId = $users[0]->id;

        for ($i = 1; $i <= 5; $i++) {
            $employee = new Employee();
            $employee->user_id = $userId;
            $employee->name = "Having Test Employee {$i}";
            $employee->tax_id = "98765432{$i}00";
            $employee->email = "having{$i}@example.com";
            $employee->phone = "558888888{$i}";
            $employee->start_time = '08:00:00';
            $employee->end_time = '18:00:00';
            $employee->lunch_start = '12:00:00';
            $employee->lunch_end = '13:00:00';
            $employee->days = '1,2,3,4,5';
            $employee->store();
        }

        $db = new Employee();
        $result = $db->addGroup('user_id')
                     ->addHaving(new \Diogodg\Neoorm\Definitions\Raw('COUNT(*)'), '>', '2')
                     ->selectColumns('user_id', new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_employees'));

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Verificar se todos os grupos retornados têm mais de 2 funcionários
        foreach ($result as $row) {
            $this->assertGreaterThan(2, (int)$row->total_employees);
        }
    }

    /**
     * Teste de GROUP BY com ORDER BY
     */
    public function testGroupByWithOrderBy(): void
    {
        $db = new Employee();
        $result = $db->addGroup('user_id')
                     ->addOrder(new \Diogodg\Neoorm\Definitions\Raw('COUNT(*)'), OrderCondition::DESC)
                     ->selectColumns('user_id', new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_employees'));

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        // Verificar se está ordenado corretamente (descendente por contagem)
        $previousCount = PHP_INT_MAX;
        foreach ($result as $row) {
            $currentCount = (int)$row->total_employees;
            $this->assertLessThanOrEqual($previousCount, $currentCount);
            $previousCount = $currentCount;
        }
    }

    /**
     * Teste de GROUP BY com JOIN
     */
    public function testGroupByWithJoin(): void
    {
        $db = new Appointment();
        $result = $db->addJoin('employee', 'employee.id', 'appointment.employee_id')
                     ->addJoin('users', 'users.id', 'appointment.user_id')
                     ->addGroup('appointment.employee_id', 'employee.name')
                     ->selectColumns(
                         'appointment.employee_id',
                         ["employee.name", "employee_name"],
                         new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_appointments')
                     );

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        foreach ($result as $row) {
            $this->assertNotNull($row->employee_id);
            $this->assertNotNull($row->employee_name);
            $this->assertGreaterThan(0, (int)$row->total_appointments);
        }
    }

    /**
     * Teste de GROUP BY com funções de agregação múltiplas
     */
    public function testGroupByWithMultipleAggregations(): void
    {
        // Criar agendamentos com diferentes durações
        $employee = (new Employee())->getAll()[0];
        $user = (new User())->getAll()[0];
        $schedule = (new Schedule())->getAll()[0];

        $durations = [
            ['10:00:00', '11:00:00'], // 1 hora
            ['14:00:00', '15:30:00'], // 1.5 horas
            ['16:00:00', '17:00:00']  // 1 hora
        ];

        foreach ($durations as $duration) {
            $appointment = new Appointment();
            $appointment->user_id = $user->id;
            $appointment->schedule_id = $schedule->id;
            $appointment->client_id = 1;
            $appointment->employee_id = $employee->id;
            $appointment->start_date = '2025-04-19 ' . $duration[0];
            $appointment->end_date = '2025-04-19 ' . $duration[1];
            $appointment->status = 'confirmed';
            $appointment->store();
        }

        $db = new Appointment();
        $result = $db->addGroup('employee_id')
                     ->selectColumns(
                         'employee_id',
                         new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_appointments'),
                         new \Diogodg\Neoorm\Definitions\Raw('MIN(start_date) as first_appointment'),
                         new \Diogodg\Neoorm\Definitions\Raw('MAX(end_date) as last_appointment')
                     );

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        
        foreach ($result as $row) {
            $this->assertNotNull($row->employee_id);
            $this->assertGreaterThan(0, (int)$row->total_appointments);
            $this->assertNotNull($row->first_appointment);
            $this->assertNotNull($row->last_appointment);
        }
    }

    /**
     * Teste de COUNT com GROUP BY
     */
    public function testCountWithGroupBy(): void
    {
        // Criar múltiplos funcionários para diferentes usuários para testar contagem com agrupamento
        $users = (new User())->getAll();
        $user1 = $users[0];

        // Criar um segundo usuário para testar
        $user2 = new User();
        $user2->name = 'Count Test User';
        $user2->email = 'count.test@example.com';
        $user2->phone = '5544444444';
        $user2->tax_id = '99999999999';
        $user2->store();

        // Criar funcionários para o primeiro usuário
        for ($i = 1; $i <= 3; $i++) {
            $employee = new Employee();
            $employee->user_id = $user1->id;
            $employee->name = "Count Test Employee User1 {$i}";
            $employee->tax_id = "11111111{$i}00";
            $employee->email = "count.user1.{$i}@example.com";
            $employee->phone = "551111111{$i}";
            $employee->start_time = '08:00:00';
            $employee->end_time = '18:00:00';
            $employee->lunch_start = '12:00:00';
            $employee->lunch_end = '13:00:00';
            $employee->days = '1,2,3,4,5';
            $employee->store();
        }

        // Criar funcionários para o segundo usuário
        for ($i = 1; $i <= 2; $i++) {
            $employee = new Employee();
            $employee->user_id = $user2->id;
            $employee->name = "Count Test Employee User2 {$i}";
            $employee->tax_id = "22222222{$i}00";
            $employee->email = "count.user2.{$i}@example.com";
            $employee->phone = "552222222{$i}";
            $employee->start_time = '08:00:00';
            $employee->end_time = '18:00:00';
            $employee->lunch_start = '12:00:00';
            $employee->lunch_end = '13:00:00';
            $employee->days = '1,2,3,4,5';
            $employee->store();
        }

        // Testar count com GROUP BY
        $db = new Employee();
        $count = $db->addGroup('user_id')->count(true);

        // O count com GROUP BY deve retornar o número de grupos únicos
        // Esperamos pelo menos 2 grupos (user1 e user2)
        $this->assertGreaterThanOrEqual(2, $count);

        // Verificar se conseguimos obter os dados agrupados
        $grouped = $db->addGroup('user_id')
                      ->selectColumns('user_id', new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_employees'));

        $this->assertIsArray($grouped);
        $this->assertGreaterThanOrEqual(2, count($grouped));

        // Verificar se os grupos estão corretos
        $user1Found = false;
        $user2Found = false;
        
        foreach ($grouped as $group) {
            if ($group->user_id == $user1->id) {
                $this->assertGreaterThanOrEqual(3, (int)$group->total_employees);
                $user1Found = true;
            }
            if ($group->user_id == $user2->id) {
                $this->assertEquals(2, (int)$group->total_employees);
                $user2Found = true;
            }
        }

        $this->assertTrue($user1Found, "Grupo do usuário 1 não foi encontrado");
        $this->assertTrue($user2Found, "Grupo do usuário 2 não foi encontrado");

        // Testar count com GROUP BY e HAVING
        $db4 = new Employee();
        $countWithHaving = $db4->addGroup('user_id')
                               ->addHaving(new \Diogodg\Neoorm\Definitions\Raw('COUNT(*)'), '>=', '2')
                               ->count();

        // Deve retornar pelo menos 2 grupos (user1 tem 3+ funcionários, user2 tem 2 funcionários)
        $this->assertGreaterThanOrEqual(2, $countWithHaving);

        // Verificar que funciona com diferentes condições HAVING
        $db5 = new Employee();
        $countWithHaving2 = $db5->addGroup('user_id')
                                ->addHaving(new \Diogodg\Neoorm\Definitions\Raw('COUNT(*)'), '>', '2')
                                ->count();

        // Deve retornar apenas 1 grupo (user1 tem mais de 2 funcionários)
        $this->assertGreaterThanOrEqual(1, $countWithHaving2);
        $this->assertLessThanOrEqual($countWithHaving, $countWithHaving2);
    }

    /**
     * Teste de PAGINATE com GROUP BY
     */
    public function testPaginateWithGroupBy(): void
    {
        // Criar múltiplos funcionários para diferentes usuários para testar paginação com agrupamento
        $users = (new User())->getAll();
        $user1 = $users[0];

        // Criar mais usuários para ter dados suficientes para paginação
        $additionalUsers = [];
        for ($i = 1; $i <= 5; $i++) {
            $user = new User();
            $user->name = "Paginate Test User {$i}";
            $user->email = "paginate.user{$i}@example.com";
            $user->phone = "5533333333{$i}";
            $user->tax_id = "88888888{$i}00";
            $user->store();
            $additionalUsers[] = $user;

            // Criar funcionários para cada usuário
            for ($j = 1; $j <= 2; $j++) {
                $employee = new Employee();
                $employee->user_id = $user->id;
                $employee->name = "Paginate Employee User{$i} Emp{$j}";
                $employee->tax_id = "77777777{$i}{$j}0";
                $employee->email = "paginate.emp{$i}.{$j}@example.com";
                $employee->phone = "5577777777{$i}";
                $employee->start_time = '08:00:00';
                $employee->end_time = '18:00:00';
                $employee->lunch_start = '12:00:00';
                $employee->lunch_end = '13:00:00';
                $employee->days = '1,2,3,4,5';
                $employee->store();
            }
        }

        $db = new Employee();
        
        // Testar paginação com GROUP BY - primeira página com 3 grupos por página
        $result = $db->addGroup('user_id')
                     ->paginate(1, 3)
                     ->selectColumns('user_id', new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_employees'));

        $this->assertIsArray($result);
        $this->assertNotEmpty($result);
        $this->assertLessThanOrEqual(3, count($result)); // Máximo 3 grupos por página
        $this->assertEquals(1, $db->getCurrentPage());
        $this->assertEquals(3, $db->getLimit());
        $this->assertEquals(0, $db->getOffset());

        // Verificar se todos os grupos retornados têm dados válidos
        foreach ($result as $group) {
            $this->assertNotNull($group->user_id);
            $this->assertGreaterThan(0, (int)$group->total_employees);
        }

        // Testar segunda página
        $db2 = new Employee();
        $result2 = $db2->addGroup('user_id')
                       ->paginate(2, 3)
                       ->selectColumns('user_id', new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_employees'));

        $this->assertIsArray($result2);
        $this->assertEquals(2, $db2->getCurrentPage());
        $this->assertEquals(3, $db2->getLimit());
        $this->assertEquals(3, $db2->getOffset());
        $this->assertEquals(1, $db2->getPreviousPage());

        // Verificar se as páginas retornam grupos diferentes
        if (!empty($result) && !empty($result2)) {
            $page1UserIds = array_column($result, 'user_id');
            $page2UserIds = array_column($result2, 'user_id');
            
            // Não deve haver interseção entre os grupos das diferentes páginas
            $intersection = array_intersect($page1UserIds, $page2UserIds);
            $this->assertEmpty($intersection, "As páginas não devem conter os mesmos grupos");
        }

        // Testar paginação com HAVING
        $db3 = new Employee();
        $result3 = $db3->addGroup('user_id')
                       ->addHaving(new \Diogodg\Neoorm\Definitions\Raw('COUNT(*)'), '>=', '2')
                       ->paginate(1, 2)
                       ->selectColumns('user_id', new \Diogodg\Neoorm\Definitions\Raw('COUNT(*) as total_employees'));

        $this->assertIsArray($result3);
        $this->assertNotEmpty($result3);
        
        // Verificar se todos os grupos têm pelo menos 2 funcionários
        foreach ($result3 as $group) {
            $this->assertGreaterThanOrEqual(2, (int)$group->total_employees);
        }
    }
}