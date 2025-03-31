# NeoORM

NeoORM is a PHP library for database mapping that allows you to create and update tables, as well as insert, update, delete, and select records from one or more tables.

## Installation
```bash
composer require diogodg/neoorm
```

Create a .env file in the root of your project with the following variables:

```env
# Database configuration
DRIVER=mysql
DBHOST=localhost
DBPORT=3306
DBNAME=db
DBCHARSET=utf8mb4
DBUSER=root
DBPASSWORD=

# Model path configuration
PATH_MODEL=./app/models
MODEL_NAMESPACE=app\models
```

## Examples

### Selecting Records

#### Select by ID
```php
// Returns an object with all table columns based on the provided $id
$result = (new Appointment)->get($id);
```

#### Select by Name
```php
// Returns an object with all table columns based on the provided $name
$result = (new Appointment)->get($name, "name");
```

#### Select All Records
```php
// Returns an array of objects with all columns and records from the table
$result = (new Appointment)->getAll();
```

#### Select with Filters
```php
// Returns an array of objects with all table columns based on the provided filters
$db = new Appointment;
$results = $db->addFilter("start_date", ">=", $start_date)
              ->addFilter("end_date", "<=", $end_date)
              ->addFilter("schedule_id", "=", intval($schedule_id))
              ->addFilter("status", "!=", $status)
              ->selectAll();
```

#### Select with Joins and Filters
```php
// Returns an array of objects with the specified columns, based on the added filters and joins
$db = new Appointment;
$result = $db->addJoin("LEFT", "user", "user.id", "appointment.user_id")
             ->addJoin("INNER", "schedule", "schedule.id", "appointment.schedule_id")
             ->addJoin("LEFT", "client", "client.id", "appointment.client_id")
             ->addJoin("INNER", "employee", "employee.id", "appointment.employee_id")
             ->addFilter("schedule.company_id", "=", $company_id)
             ->selectColumns("appointment.id", "user.tax_id", "client.name as client_name", "user.name as user_name", "user.email", "user.phone", "schedule.name as schedule_name", "employee.name as employee_name", "start_date", "end_date");
```

#### Select with Filters and Limit
```php
// Returns an array of objects with the specified columns that match the provided values, based on the specified filters and limit
$db = new City;
$result = $db->addFilter("name", "LIKE", "%" . $name . "%")
             ->addLimit(1)
             ->selectByValues(["state"], [$state_id], true);
```

### Insert/Update Records

```php
$values = new Employee;

// If $values->id is null, empty, or 0, it will attempt an INSERT command. Otherwise, it will attempt an UPDATE.
$values->id = null; // or "" or 0
$values->user_id = $user_id;
$values->name = $name;
$values->tax_id = $tax_id;
$values->email = $email;
$values->phone = $phone;
$values->start_time = $start_time;
$values->end_time = $end_time;
$values->lunch_start = $lunch_start;
$values->lunch_end = $lunch_end;
$values->days = $days;

// Returns false or the record ID
$result = $values->store();
```

### Delete Records

#### Delete by Filter
```php
$db = new Employee;

// Returns true or false
$result = $db->addFilter("name", "=", "John")->deleteByFilter();
```

#### Delete by ID
```php
$id = 1;
$db = new Employee;

// Returns true or false
$result = $db->delete($id);
```

### Using Transactions

```php
try {   
    connection::beginTransaction();

    if ($schedule->set()){ 
        $scheduleUser = new ScheduleUser;
        $scheduleUser->user_id = $user->id;
        $scheduleUser->schedule_id = $schedule->id;
        $scheduleUser->set();

        if($schedule->employee_id){
            $scheduleEmployee = new ScheduleEmployee;
            $scheduleEmployee->employee_id = $schedule->employee_id;
            $scheduleEmployee->schedule_id = $schedule->id;
            $scheduleEmployee->set();
        }
        connection::commit();
    }
} catch (\exception $e){
    connection::rollBack();
}
```

### Other Examples

#### Using the DB Class Directly
```php
$id = 1;
$db = new db("tb_employee");

// Returns true or false
$result = $db->delete($id);
```

## Database Creation/Modification

### Creating a Table

Inside the app/models folder, you should create a class that will represent your table in the database, as shown in the example below:

```php
<?php
namespace App\Models;

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class State extends Model {
    // Required parameter that will define the table name in the database
    public const table = "state";

    // Must be in this format
    public function __construct() {
        parent::__construct(self::table);
    }

    // Method responsible for creating the table
    public static function table(){
        return (new Table(self::table, comment:"States table"))
                ->addColumn((new Column("id", "INT"))->isPrimary()->setComment("State ID"))
                ->addColumn((new Column("name", "VARCHAR", 120))->isNotNull()->setComment("State name"))
                ->addColumn((new Column("abbreviation", "VARCHAR", 2))->isNotNull()->setComment("State abbreviation"))
                ->addColumn((new Column("country", "INT"))->isNotNull()->setComment("Country ID of the state"))
                ->addForeignKey(Country::table, column:"country")
                ->addColumn((new Column("ibge", "INT"))->isUnique()->setComment("IBGE ID of the state"))
                ->addColumn((new Column("area_code", "VARCHAR", 50))->setComment("Area codes separated by comma"));
    }

    // Method responsible for inserting initial data into the table
    public static function seed(){
        $object = new self;
        if(!$object->addLimit(1)->selectColumns("id")){
            $object->name = "Acre";
            $object->abbreviation = "AC";
            $object->country = 1;
            $object->ibge = 12;
            $object->area_code = "68";
            $object->store();

            $object->name = "Alagoas";
            $object->abbreviation = "AL";
            $object->country = 1;
            $object->ibge = 27;
            $object->area_code = "82";
            $object->store();

            $object->name = "Amapá";
            $object->abbreviation = "AP";
            $object->country = 1;
            $object->ibge = 16;
            $object->area_code = "96";
            $object->store();

            $object->name = "Amazonas";
            $object->abbreviation = "AM";
            $object->country = 1;
            $object->ibge = 13;
            $object->area_code = "92,97";
            $object->store();
        }
    }
}
```

After creating all the classes, simply call the following class as shown in the example below:

```php
<?php

use Diogodg\Neoorm\Migrations\Migrate;

// If the recreate parameter is true, all tables will be removed and then recreated
(new Migrate)->execute($recreate = false);
```
