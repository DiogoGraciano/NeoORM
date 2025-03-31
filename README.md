Okay, here is the content formatted for a GitHub `README.md` file, based on the English translation you provided.

```markdown
# NeoORM

[![Latest Stable Version](https://img.shields.io/packagist/v/diogodg/neoorm.svg?style=flat-square)](https://packagist.org/packages/diogodg/neoorm) <!-- Replace if this isn't the correct packagist link -->
[![Total Downloads](https://img.shields.io/packagist/dt/diogodg/neoorm.svg?style=flat-square)](https://packagist.org/packages/diogodg/neoorm) <!-- Replace if this isn't the correct packagist link -->
<!-- Add other badges as needed (Build Status, License, etc.) -->

NeoORM is a PHP ORM (Object-Relational Mapper) library designed for straightforward database interaction. It allows you to define models, manage database schema (create/update tables), and perform CRUD (Create, Read, Update, Delete) operations easily.

## Table of Contents

- [Installation](#installation)
- [Configuration](#configuration)
- [Usage Examples](#usage-examples)
  - [Selecting Records](#selecting-records)
    - [Select by ID](#select-by-id)
    - [Select by Specific Column](#select-by-specific-column)
    - [Select All Records](#select-all-records)
    - [Select with Filters](#select-with-filters)
    - [Select with Joins and Filters](#select-with-joins-and-filters)
    - [Select with Filters and Limit](#select-with-filters-and-limit)
  - [Inserting/Updating Records](#insertingupdating-records)
  - [Deleting Records](#deleting-records)
    - [Delete by Filter](#delete-by-filter)
    - [Delete by ID](#delete-by-id)
  - [Using Transactions](#using-transactions)
  - [Using the DB Class Directly (Optional)](#using-the-db-class-directly-optional)
- [Database Schema Management (Migrations)](#database-schema-management-migrations)
  - [Creating a Model and Table](#creating-a-model-and-table)
  - [Running Migrations](#running-migrations)
  - [Seeding Initial Data](#seeding-initial-data)
- [Contributing](#contributing)
- [License](#license)

## Installation

Install NeoORM using Composer:

```bash
composer require diogodg/neoorm
```

## Configuration

Create a `.env` file in the root directory of your project and add the following configuration variables:

```dotenv
# Database configuration
DRIVER=mysql        # Database driver (e.g., mysql, pgsql)
DBHOST=localhost    # Database host
DBPORT=3306         # Database port
DBNAME=your_database_name # Your database name
DBCHARSET=utf8mb4   # Database character set
DBUSER=root         # Database username
DBPASSWORD=your_password # Database password (leave empty if none)

# Model configuration
PATH_MODEL=./app/models      # Path to your Model classes directory
MODEL_NAMESPACE=App\Models   # Namespace for your Model classes (adjust '\' for your OS if needed in code, but use '\\' in PHP strings)
```

*Ensure you have a library like `vlucas/phpdotenv` installed and loaded in your application bootstrap to read these variables.*

## Usage Examples

### Selecting Records

Assume you have an `Agendamento` model (`App\Models\Agendamento`).

#### Select by ID

```php
use App\Models\Agendamento;

$id = 1;
// Returns an Agendamento object or null if not found
$agendamento = (new Agendamento)->get($id);

if ($agendamento) {
    echo "Found: " . $agendamento->some_property;
}
```

#### Select by Specific Column

```php
use App\Models\Agendamento;

$nome = "Specific Name";
// Returns the first Agendamento object matching the name or null
$agendamento = (new Agendamento)->get($nome, "nome"); // Searches in the 'nome' column
```

#### Select All Records

```php
use App\Models\Agendamento;

// Returns an array of Agendamento objects
$allAgendamentos = (new Agendamento)->getAll();

foreach ($allAgendamentos as $agendamento) {
    // ... process each record
}
```

#### Select with Filters

```php
use App\Models\Agendamento;

$db = new Agendamento;
$results = $db->addFilter("dt_ini", ">=", $startDate) // Use descriptive variable names
              ->addFilter("dt_fim", "<=", $endDate)
              ->addFilter("id_agenda", "=", intval($agendaId))
              ->addFilter("status", "!=", $excludedStatus)
              ->selectAll(); // Executes the query and returns an array of objects

foreach ($results as $result) {
    // ...
}
```

#### Select with Joins and Filters

```php
use App\Models\Agendamento;

$db = new Agendamento; // Base table for the query
$results = $db->addJoin("LEFT", "usuario", "usuario.id", "agendamento.id_usuario")
              ->addJoin("INNER", "agenda", "agenda.id", "agendamento.id_agenda")
              ->addJoin("LEFT", "cliente", "cliente.id", "agendamento.id_cliente")
              ->addJoin("INNER", "funcionario", "funcionario.id", "agendamento.id_funcionario")
              ->addFilter("agenda.id_empresa", "=", $companyId)
              // Select specific columns, optionally using aliases
              ->selectColumns(
                  "agendamento.id",
                  "usuario.cpf_cnpj",
                  "cliente.nome as client_name",
                  "usuario.nome as user_name",
                  "usuario.email",
                  "usuario.telefone",
                  "agenda.nome as agenda_name",
                  "funcionario.nome as employee_name",
                  "dt_ini",
                  "dt_fim"
              ); // Returns an array of stdClass objects (or similar) with selected columns

foreach ($results as $result) {
    echo "Client: " . $result->client_name;
    echo "User: " . $result->user_name;
    // ... access other selected columns
}
```

#### Select with Filters and Limit

```php
use App\Models\Cidade; // Assuming a Cidade (City) model

$db = new Cidade;
// Assuming selectByValues searches specific columns for given values
$result = $db->addFilter("nome", "LIKE", "%" . $cityNamePart . "%")
             ->addLimit(1) // Get only the first match
             ->selectByValues(["uf"], [$stateId], true); // Example: find city matching name pattern in a specific state (uf)
```
*Note: The exact behavior of `selectByValues` might need clarification in the library's documentation.*

### Inserting/Updating Records

NeoORM uses the same `store()` method for both inserting and updating. It checks the model's primary key (`id` by default). If the `id` is null, empty, or 0, it performs an `INSERT`. Otherwise, it performs an `UPDATE`.

```php
use App\Models\Funcionario; // Assuming a Funcionario (Employee) model

$employee = new Funcionario;

// For INSERT: ensure ID is null or not set
// $employee->id = null; // Explicitly null for clarity

// For UPDATE: set the ID of the record to update
// $employee->id = 5;

// Set properties
$employee->id_usuario = $userId;
$employee->nome = $name;
$employee->cpf_cnpj = $documentNumber;
$employee->email = $email;
$employee->telefone = $phone;
$employee->hora_ini = $startTime;       // e.g., '09:00:00'
$employee->hora_fim = $endTime;         // e.g., '18:00:00'
$employee->hora_almoco_ini = $lunchStart; // e.g., '12:00:00'
$employee->hora_almoco_fim = $lunchEnd;   // e.g., '13:00:00'
$employee->dias = $workDays;            // e.g., 'Mon,Tue,Wed,Thu,Fri' or JSON

// Attempt to save (INSERT or UPDATE)
$result = $employee->store(); // Returns the inserted ID on success, true on update success, or false on failure

if ($result) {
    echo "Employee saved successfully. ID: " . ($employee->id ?? $result); // Access the ID property after successful insert/update
} else {
    echo "Failed to save employee.";
}
```

### Deleting Records

#### Delete by Filter

```php
use App\Models\Funcionario;

$db = new Funcionario;

// Delete all employees named 'Diogo'
$success = $db->addFilter("nome", "=", "Diogo")->deleteByFilter();

if ($success) {
    echo "Records deleted successfully.";
} else {
    echo "Failed to delete records.";
}
```

#### Delete by ID

```php
use App\Models\Funcionario;

$idToDelete = 1;
$db = new Funcionario;

// Delete the employee with the specified ID
$success = $db->delete($idToDelete);

if ($success) {
    echo "Employee with ID {$idToDelete} deleted.";
} else {
    echo "Failed to delete employee with ID {$idToDelete}.";
}
```

### Using Transactions

Wrap multiple database operations in a transaction to ensure atomicity (all succeed or all fail).

```php
use Diogodg\Neoorm\Connection; // Assuming Connection class handles transactions
use App\Models\Agenda;
use App\Models\AgendaUsuario;
use App\Models\AgendaFuncionario;
// Assuming $user, $agenda data are prepared

$agenda = new Agenda(/* pass initial data if constructor supports it */);
// Set agenda properties...
// $agenda->property = $value;

try {
    Connection::beginTransaction(); // Start transaction

    // Attempt to save the main record
    if ($agenda->store()) {
        $agendaId = $agenda->id; // Get the ID of the newly created/updated agenda

        // Save related record
        $agendaUsuario = new AgendaUsuario;
        $agendaUsuario->id_usuario = $user->id;
        $agendaUsuario->id_agenda = $agendaId;
        if (!$agendaUsuario->store()) {
             throw new \Exception("Failed to save AgendaUsuario."); // Trigger rollback
        }

        // Conditionally save another related record
        if (!empty($agenda->id_funcionario)) {
            $agendaFuncionario = new AgendaFuncionario;
            $agendaFuncionario->id_funcionario = $agenda->id_funcionario;
            $agendaFuncionario->id_agenda = $agendaId;
             if (!$agendaFuncionario->store()) {
                throw new \Exception("Failed to save AgendaFuncionario."); // Trigger rollback
             }
        }

        Connection::commit(); // All operations successful, commit changes
        echo "Transaction committed successfully.";

    } else {
        // The initial $agenda->store() failed
        Connection::rollBack(); // Rollback immediately
        echo "Failed to save initial agenda record. Transaction rolled back.";
    }

} catch (\Exception $e) {
    Connection::rollBack(); // Rollback transaction on any error
    echo "An error occurred: " . $e->getMessage() . ". Transaction rolled back.";
    // Log the error: error_log($e->getMessage());
}
```

### Using the DB Class Directly (Optional)

If you need to interact with a table without a dedicated Model class, you might be able to use a generic `DB` class (if provided by the library).

```php
use Diogodg\Neoorm\DB; // Assuming this class exists

$tableName = "some_other_table";
$idToDelete = 10;

$db = new DB($tableName);

// Assuming a generic delete method exists
$success = $db->delete($idToDelete);

if ($success) {
    echo "Record deleted from {$tableName}.";
} else {
    echo "Failed to delete record from {$tableName}.";
}
```
*Note: Verify the existence and usage of a generic `DB` class in the library's source or documentation.*

## Database Schema Management (Migrations)

NeoORM provides tools to define your database schema using Model classes and apply these definitions to your database.

### Creating a Model and Table

Create Model classes within the directory specified by `PATH_MODEL` (e.g., `app/models`) and use the namespace defined in `MODEL_NAMESPACE` (e.g., `App\Models`).

Each Model should extend `Diogodg\Neoorm\Abstract\Model` and define the table structure using a static `table()` method and optionally initial data using a static `seed()` method.

**Example: `app/models/Estado.php`**

```php
<?php
namespace App\Models; // Matches MODEL_NAMESPACE

use Diogodg\Neoorm\Abstract\Model;
use Diogodg\Neoorm\Migrations\Table;
use Diogodg\Neoorm\Migrations\Column;

class Estado extends Model { // "Estado" means State
    // Required: Defines the actual table name in the database
    public const table = "estado";

    // Required: Call parent constructor with the table name
    public function __construct() {
        parent::__construct(self::table);
    }

    // Required for Migrations: Defines the table structure
    public static function table(): Table
    {
        return (new Table(self::table, comment: "Table of states"))
                ->addColumn((new Column("id", "INT"))->isPrimary()->isAutoIncrement()->setComment("Primary Key")) // Added AutoIncrement
                ->addColumn((new Column("nome", "VARCHAR", 120))->isNotNull()->setComment("Name of the state"))
                ->addColumn((new Column("uf", "VARCHAR", 2))->isNotNull()->isUnique()->setComment("Abbreviation (UF), Unique")) // Added Unique
                ->addColumn((new Column("pais", "INT"))->isNotNull()->setComment("ID of the country")) // "pais" means Country
                ->addForeignKey(Pais::table, // Assumes a Pais (Country) Model exists
                                column: "pais", // Local column
                                references: "id", // Foreign column in Pais table
                                onDelete: "CASCADE") // Optional: Action on delete
                ->addColumn((new Column("ibge", "INT"))->isUnique()->setComment("IBGE ID (Unique)")) // IBGE is a Brazilian specific ID
                ->addColumn((new Column("ddd", "VARCHAR", 50))->setComment("Comma-separated DDDs (area codes)"));
                // Add Timestamps (created_at, updated_at) if desired/supported
                // ->addTimestamps();
    }

    // Optional for Seeding: Defines initial data
    public static function seed(): void
    {
        $estado = new self;
        // Check if table is empty before seeding to avoid duplicates
        if (!$estado->addLimit(1)->selectColumns("id")) {
            echo "Seeding ".self::table."...\n";

            (new self)->fill([
                "nome" => "Acre", "uf" => "AC", "pais" => 1, "ibge" => 12, "ddd" => "68"
            ])->store();

            (new self)->fill([
                "nome" => "Alagoas", "uf" => "AL", "pais" => 1, "ibge" => 27, "ddd" => "82"
            ])->store();

            (new self)->fill([
                "nome" => "Amapá", "uf" => "AP", "pais" => 1, "ibge" => 16, "ddd" => "96"
            ])->store();

             (new self)->fill([
                "nome" => "Amazonas", "uf" => "AM", "pais" => 1, "ibge" => 13, "ddd" => "92,97"
            ])->store();

            // ... Add other states using fill() and store() for clarity ...

            echo self::table." seeding complete.\n";
        } else {
             echo self::table." already contains data, skipping seed.\n";
        }
    }

    // Helper method for seeding (optional)
    public function fill(array $attributes): self
    {
        foreach ($attributes as $key => $value) {
            $this->$key = $value;
        }
        // Ensure ID is null for insertion during seeding
        $this->id = null;
        return $this;
    }
}
```

### Running Migrations

After defining your Model classes with their `table()` methods, use the `Migrate` class to create or update the database tables. Create a script (e.g., `migrate.php`) in your project root:

**`migrate.php`**

```php
<?php

// Include Composer's autoloader
require 'vendor/autoload.php';

// Load .env variables (using a library like vlucas/phpdotenv)
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    $dotenv->required(['DRIVER', 'DBHOST', 'DBPORT', 'DBNAME', 'DBCHARSET', 'DBUSER', 'DBPASSWORD', 'PATH_MODEL', 'MODEL_NAMESPACE']); // Validate required vars
} catch (\Throwable $e) {
    die("Error loading .env file: " . $e->getMessage());
}

use Diogodg\Neoorm\Migrations\Migrate;

echo "Starting migration...\n";

try {
    // Set $recreate to true to drop all managed tables and recreate them (USE WITH CAUTION!)
    // Set to false to only create missing tables or apply changes (if supported)
    $recreate = false; // Default to non-destructive migration

    // Check for command-line argument to force recreate
    if (isset($argv[1]) && $argv[1] === '--recreate') {
        echo "Recreate flag detected. All managed tables will be dropped and recreated.\n";
        $recreate = true;
    }

    (new Migrate)->execute($recreate);

    echo "Migration completed successfully.\n";

} catch (\Exception $e) {
    echo "Migration failed: " . $e->getMessage() . "\n";
    // Optional: Log detailed error $e->getTraceAsString()
    exit(1); // Exit with error code
}

exit(0); // Exit successfully
```

Run the migration script from your terminal:

```bash
php migrate.php
```

To force a recreation (drop and create):

```bash
php migrate.php --recreate
```

### Seeding Initial Data

If your Models have a `seed()` method, you can create a separate script or modify the migration script to call the seeder after the tables are created/updated.

**Example: `seed.php`**

```php
<?php

// Include Composer's autoloader
require 'vendor/autoload.php';

// Load .env variables
try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__);
    $dotenv->load();
    $dotenv->required(['DRIVER', 'DBHOST', 'DBPORT', 'DBNAME', 'DBCHARSET', 'DBUSER', 'DBPASSWORD', 'PATH_MODEL', 'MODEL_NAMESPACE']);
} catch (\Throwable $e) {
    die("Error loading .env file: " . $e->getMessage());
}

use Diogodg\Neoorm\Migrations\Migrate; // Assuming Migrate class has the seed runner

echo "Starting seeder...\n";

try {
    // Assuming the Migrate class has a method to run all seeds
    (new Migrate)->seed();

    echo "Seeding completed successfully.\n";

} catch (\Exception $e) {
    echo "Seeding failed: " . $e->getMessage() . "\n";
    exit(1);
}

exit(0);
```

Run the seeder:

```bash
php seed.php
```

*Note: The existence and implementation of `(new Migrate)->seed()` depend on the NeoORM library. If it doesn't exist, you might need to manually instantiate each Model and call its `seed()` method.*

## Contributing

Contributions are welcome! Please feel free to submit pull requests or open issues on the GitHub repository. <!-- Add link to repo -->

## License

<!-- Specify the license, e.g., MIT -->
This project is licensed under the MIT License - see the [LICENSE](LICENSE) file for details (if a LICENSE file exists).
```
