# DBPrism

> **Refract your queries across any database.**
>
> Intent-based database abstraction layer with unified adapters, schema inspection, and migrations. Write once. Query everywhere.

[![PHP Version](https://img.shields.io/badge/php-%3E%3D8.1-blue.svg)](https://www.php.net)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg)](https://opensource.org/licenses/MIT)

---

## Table of Contents

- [What is DBPrism?](#what-is-dbprism)
- [Installation](#installation)
- [Quick Start](#quick-start)
- [Supported Databases](#supported-databases)
- [Query Building API](#query-building-api)
  - [SELECT Queries](#select-queries)
  - [INSERT Queries](#insert-queries)
  - [UPDATE Queries](#update-queries)
  - [DELETE Queries](#delete-queries)
- [Schema Operations](#schema-operations)
  - [CREATE TABLE](#create-table)
  - [ALTER TABLE](#alter-table)
  - [CREATE INDEX](#create-index)
  - [TRUNCATE TABLE](#truncate-table)
  - [DROP TABLE](#drop-table)
- [Schema Inspection](#schema-inspection)
- [Migrations](#migrations)
- [Advanced Features](#advanced-features)
- [API Reference](#api-reference)

---

## What is DBPrism?

DBPrism is a sophisticated, framework-agnostic database abstraction layer that unifies database operations across **MySQL**, **PostgreSQL**, and **SQLite**. 

Like a prism refracting light into its component colors, DBPrism takes your query intents and refracts them into engine-specific SQL—transparently, elegantly, and efficiently.

### Core Features

- **🔄 Unified Adapters** — Single API for 5+ database engines
- **🎯 Intent-Based Query Building** — Declarative query construction with automatic SQL rendering
- **🔍 Schema Inspection** — Deep schema introspection across all engines
- **🚀 Migrations** — Fluent migration API with helpers for schema transformations
- **⚡ Multi-Engine Rendering** — One query intent → Multiple engine-specific SQL outputs
- **💪 Type Normalization** — Consistent column types across databases
- **🧩 Framework-Agnostic** — Works standalone or integrated with any framework

---

## Installation

```bash
composer require callismart/dbprism
```

**Requirements:**
- PHP 8.1+
- One or more: MySQLi, PDO, PostgreSQL, SQLite extensions

---

## Quick Start

### 1. Initialize the Database

```php
use Callismart\DBPrism\Database;
use Callismart\DBPrism\Adapters\MysqliAdapter;
use Callismart\DBPrism\DBConfigDTO;

$config = new DBConfigDTO([
    'host'     => 'localhost',
    'username' => 'root',
    'password' => 'secret',
    'dbname'   => 'myapp',
    'driver'   => 'mysql',
]);

$adapter = new MysqliAdapter($config);
$db = new Database($adapter);
```

### 2. Execute Simple Queries

```php
// Insert
$user_id = $db->insert('users', [
    'name'  => 'John Doe',
    'email' => 'john@example.com',
]);

// Fetch
$user = $db->get_row('SELECT * FROM users WHERE id = ?', [$user_id]);

// Update
$db->update('users', 
    ['status' => 'active'],
    ['id' => $user_id]
);

// Delete
$db->delete('users', ['id' => $user_id]);
```

### 3. Build Complex Queries with Intents

```php
use Callismart\DBPrism\Query\SQLBuilder;

$builder = new SQLBuilder($db->get_driver());

// Build a SELECT query
$query = $builder->select('id', 'name', 'email')
    ->from('users')
    ->where('status', '=', 'active')
    ->where('created_at', '>', '2024-01-01')
    ->order_by('created_at', 'DESC')
    ->limit(10)
    ->build();

// Execute it
$users = $db->get_results($query);
```

---

## Supported Databases

### MySQL / MariaDB
```php
use Callismart\DBPrism\Adapters\MysqliAdapter;

$config = new DBConfigDTO([
    'host'     => 'localhost',
    'username' => 'root',
    'password' => 'secret',
    'dbname'   => 'myapp',
    'driver'   => 'mysql',
]);

$adapter = new MysqliAdapter($config);
```

### PostgreSQL
```php
use Callismart\DBPrism\Adapters\PostgresAdapter;

$config = new DBConfigDTO([
    'host'     => 'localhost',
    'username' => 'postgres',
    'password' => 'secret',
    'dbname'   => 'myapp',
    'driver'   => 'pgsql',
]);

$adapter = new PostgresAdapter($config);
```

### SQLite
```php
use Callismart\DBPrism\Adapters\SqliteAdapter;

$config = new DBConfigDTO([
    'dbname' => 'myapp',
    'driver' => 'sqlite',
    'path'   => '/path/to/database/'
]);

$adapter = new SqliteAdapter($config);
```

### PDO (Universal)
```php
use Callismart\DBPrism\Adapters\PdoAdapter;

$config = new DBConfigDTO([
    'dsn'      => 'mysql:host=localhost;dbname=myapp',
    'username' => 'root',
    'password' => 'secret',
    'driver'   => 'pdo',
]);

$adapter = new PdoAdapter($config);
```

### WordPress
```php
use Callismart\DBPrism\Adapters\WPDBAdapter;

$adapter = new WPDBAdapter();
$db = new Database($adapter);
```

---

## Query Building API

### SELECT Queries

Select queries are built using the `SelectionIntent` class, accessed via `SQLBuilder::select()`.

#### Basic SELECT

```php
$builder = new SQLBuilder('mysql');

$query = $builder->select('id', 'name', 'email')
    ->from('users')
    ->build();
// SELECT `id`, `name`, `email` FROM `users`;
```

#### SELECT with WHERE Conditions

```php
$query = $builder->select('*')
    ->from('orders')
    ->where('status', '=', 'completed')
    ->where('total', '>', 100)
    ->build();
// SELECT * FROM `orders` WHERE `status` = ? AND `total` > ?;
```

#### WHERE Operators

```php
// Basic comparison
->where('age', '>=', 18)
->where('name', '!=', 'Admin')
->where('email', 'LIKE', '%@example.com')

// IS NULL / IS NOT NULL
->where_null('deleted_at')
->where_not_null('verified_at')

// IN / NOT IN
->where_in('status', ['active', 'pending'])
->where_not_in('role', ['banned', 'suspended'])

// BETWEEN / NOT BETWEEN
->where_between('age', 18, 65)
->where_not_between('created_at', '2023-01-01', '2023-12-31')

// OR conditions
->where('status', '=', 'active')
->or_where('status', '=', 'pending')

// Grouped conditions
->where_group(function($q) {
    $q->where('status', '=', 'active')
      ->or_where('status', '=', 'pending');
})

// Raw SQL (use with caution!)
->where_raw('YEAR(created_at) = 2024', [])
```

#### JOINs

```php
// INNER JOIN
->join('orders', 'users.id', '=', 'orders.user_id')

// LEFT JOIN
->left_join('profiles', 'users.id', '=', 'profiles.user_id')

// RIGHT JOIN
->right_join('departments', 'employees.dept_id', '=', 'departments.id')

// CROSS JOIN
->cross_join('statuses')

// Multiple joins
->from('orders')
->join('customers', 'orders.customer_id', '=', 'customers.id')
->join('order_items', 'orders.id', '=', 'order_items.order_id')
->join('products', 'order_items.product_id', '=', 'products.id')
```

#### GROUP BY, ORDER BY, LIMIT/OFFSET

```php
$query = $builder->select('category', 'COUNT(*) as total')
    ->from('products')
    ->group_by('category')
    ->order_by('total', 'DESC')
    ->limit(10)
    ->offset(0)
    ->build();
// SELECT `category`, COUNT(*) as total FROM `products` 
// GROUP BY `category` ORDER BY `total` DESC LIMIT 10 OFFSET 0;
```

#### Complete SELECT Example

```php
$query = $builder->select('u.id', 'u.name', 'COUNT(o.id) as order_count')
    ->from('users as u')
    ->left_join('orders as o', 'u.id', '=', 'o.user_id')
    ->where('u.status', '=', 'active')
    ->where_group(function($q) {
        $q->where('o.total', '>', 100)
          ->or_where_null('o.id');
    })
    ->group_by('u.id', 'u.name')
    ->order_by('order_count', 'DESC')
    ->limit(50)
    ->build();

$results = $db->get_results($query, $builder->select(...)->get_bindings());
```

---

### INSERT Queries

Insert queries are built using the `PersistenceIntent` class, accessed via `SQLBuilder::insert()`.

#### Single Row Insert

```php
$intent = $builder->insert('users')
    ->values([
        'name'       => 'John Doe',
        'email'      => 'john@example.com',
        'password'   => hash('sha256', 'secret'),
        'created_at' => date('Y-m-d H:i:s'),
    ]);

$sql = $intent->build();
$bindings = $intent->get_bindings();

$id = $db->insert('users', $intent->get_data());
```

#### Multi-Row Insert (Bulk)

```php
$intent = $builder->insert('users')
    ->multi_values([
        [
            'name'  => 'John Doe',
            'email' => 'john@example.com',
        ],
        [
            'name'  => 'Jane Smith',
            'email' => 'jane@example.com',
        ],
        [
            'name'  => 'Bob Wilson',
            'email' => 'bob@example.com',
        ],
    ]);

$sql = $intent->build();
// INSERT INTO `users` (`name`, `email`) VALUES (?, ?), (?, ?), (?, ?);
```

#### Insert with SET Alias

```php
$intent = $builder->insert('users')
    ->set([
        'name'  => 'John Doe',
        'email' => 'john@example.com',
    ]);
```

---

### UPDATE Queries

Update queries are built using the `PersistenceIntent` class, accessed via `SQLBuilder::update()`.

#### Basic UPDATE

```php
$intent = $builder->update('users')
    ->values([
        'status'     => 'inactive',
        'updated_at' => date('Y-m-d H:i:s'),
    ])
    ->where('id', '=', 1);

$sql = $intent->build();
// UPDATE `users` SET `status` = ?, `updated_at` = ? WHERE `id` = ?;

$affected = $db->execute($sql, $intent->get_bindings());
```

#### UPDATE with Multiple WHERE Conditions

```php
$intent = $builder->update('users')
    ->set([
        'verified' => true,
        'verified_at' => date('Y-m-d H:i:s'),
    ])
    ->where('email_confirmed', '=', true)
    ->where('deleted_at', '=', null)
    ->where_in('status', ['pending', 'new']);

$sql = $intent->build();
```

#### UPDATE with Grouped Conditions

```php
$intent = $builder->update('orders')
    ->set(['status' => 'cancelled'])
    ->where_group(function($q) {
        $q->where('created_at', '<', date('Y-m-d', strtotime('-30 days')))
          ->and_where('total', '<', 10);
    })
    ->or_where('user_requested_cancel', '=', true);
```

---

### DELETE Queries

Delete queries are built using the `DeleteIntent` class, accessed via `SQLBuilder::delete()`.

#### Basic DELETE

```php
$intent = $builder->delete('users')
    ->where('id', '=', 1);

$sql = $intent->build();
// DELETE FROM `users` WHERE `id` = ?;

$deleted = $db->execute($sql, $intent->get_bindings());
```

#### DELETE with Multiple Conditions

```php
$intent = $builder->delete('sessions')
    ->where('user_id', '=', 5)
    ->where('expires_at', '<', date('Y-m-d H:i:s'));

$sql = $intent->build();
```

#### DELETE with Grouped Conditions

```php
$intent = $builder->delete('logs')
    ->where_group(function($q) {
        $q->where('level', '=', 'debug')
          ->or_where('level', '=', 'trace');
    })
    ->where('created_at', '<', date('Y-m-d', strtotime('-90 days')));
```

#### Safe DELETE (prevents accidental data loss)

```php
// Always use WHERE clause for safety!
// This prevents: DELETE FROM users; (dangerous!)

$intent = $builder->delete('users')
    ->where('status', '=', 'inactive')
    ->where('last_login', '<', date('Y-m-d', strtotime('-1 year')));
```

---

## Schema Operations

### CREATE TABLE

Create tables using the `CreateTableIntent` class and fluent helper methods.

#### Basic CREATE TABLE

```php
use Callismart\DBPrism\Migrations\Helpers\TableHelper;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;

$helper = new TableHelper($db, new SQLBuilder($db->get_driver()), 'users');

$helper->create()
    ->id()  // Auto-incrementing primary key
    ->string('name', 100)
    ->string('email', 100)->unique()
    ->string('password', 255)
    ->boolean('is_active')->default(true)
    ->timestamp('created_at')->default_current_timestamp()
    ->timestamp('updated_at')->nullable()
    ->save();

// Generates:
// CREATE TABLE `users` (
//     `id` BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
//     `name` VARCHAR(100) NOT NULL,
//     `email` VARCHAR(100) NOT NULL UNIQUE,
//     `password` VARCHAR(255) NOT NULL,
//     `is_active` TINYINT(1) NOT NULL DEFAULT 1,
//     `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
//     `updated_at` TIMESTAMP NULL
// ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

#### CREATE TABLE with Relationships

```php
$helper->create()
    ->id()
    ->string('name', 100)
    ->unsigned_big_integer('category_id')
    ->unsigned_big_integer('user_id')
    ->foreign_key('category_id')->references('categories', 'id')->on_delete('cascade')
    ->foreign_key('user_id')->references('users', 'id')->on_delete('restrict')
    ->index('name')
    ->unique('slug')
    ->save();
```

#### Column Types

```php
// Numeric
->integer('count')
->big_integer('big_count')
->tiny_integer('status')
->small_integer('priority')
->unsigned_integer('views')
->unsigned_big_integer('large_id')
->decimal('price', 10, 2)
->float('rating')

// String
->string('name', 100)
->text('description')
->char('code', 5)

// Boolean
->boolean('is_active')

// Date/Time
->date('birth_date')
->time('alarm_time')
->timestamp('created_at')
->datetime('scheduled_at')

// JSON
->json('metadata')

// Enumerations
->enum('status', ['active', 'inactive', 'pending'])
```

#### Column Modifiers

```php
->string('email', 100)->nullable()
->string('slug', 100)->unique()
->integer('order')->default(0)
->timestamp('deleted_at')->nullable()
->string('code', 10)->unique()->index()
->text('description')->comment('User bio')
```

#### Column Constraints

```php
->string('email', 100)->unique()  // UNIQUE constraint
->index('email')                   // INDEX
->primary('id')                    // PRIMARY KEY
->foreign_key('user_id')           // FOREIGN KEY
    ->references('users', 'id')
    ->on_delete('cascade')
```

### ALTER TABLE

Modify existing tables using the `AlterTableIntent` class.

#### Add Column

```php
$helper = new \Callismart\DBPrism\Migrations\Helpers\TableHelper(
    $db, 
    new SQLBuilder($db->get_driver()), 
    'users'
);

$helper->add_column(
    Column::varchar('phone', 20)->nullable()
);
```

#### Modify Column

```php
$helper->modify_column(
    Column::string('name', 255)  // Changed from 100 to 255
);
```

#### Rename Column

```php
$helper->rename_column('old_name', 'new_name');
```

#### Drop Column

```php
$helper->drop_column('deprecated_field');
```

#### Add Constraint

```php
$helper->add_constraint(
    Constraint::unique('email')
);
```

#### Drop Constraint/Index

```php
$helper->drop_constraint('uk_email');
$helper->drop_index('idx_created_at');
```

### CREATE INDEX

Create indexes on existing tables using the `CreateIndexIntent` class.

```php
use Callismart\DBPrism\Migrations\Helpers\IndexHelper;
use Callismart\DBPrism\Utils\Constraint;

$helper = new IndexHelper($db, new SQLBuilder($db->get_driver()), 'orders');

// Single column index
$helper->add('created_at', unique: false);

// Composite index
$helper->add('user_id')
       ->add('created_at')
       ->save();

// Unique index
$helper->add('email', unique: true)->save();
```

### TRUNCATE TABLE

Remove all data from a table using the `TruncateTableIntent` class.

```php
$intent = $builder->truncate_table('logs')
    ->restart_identity()  // Reset auto-increment
    ->cascade(false);     // Don't cascade to dependent tables

$sql = $intent->build();
```

### DROP TABLE

Drop tables using the `SQLBuilder::drop_table()` method.

```php
$sql = $builder->drop_table('old_table')
    ->if_exists()  // Optional: only drop if exists
    ->build();

$db->exec($sql);
```

---

## Schema Inspection

Inspect database schema using the `Inspector` class.

```php
use Callismart\DBPrism\Inspection\Inspector;

$inspector = new Inspector($db);
```

### Table Operations

```php
// List all tables
$tables = $inspector->get_all_tables();

// Check if table exists
if ($inspector->table_exists('users')) {
    echo "Table exists!";
}

// Get table metadata
$meta = $inspector->get_table_metadata('users');
// Returns: ['engine' => 'InnoDB', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci', 'row_count' => 1250, 'comment' => '']
```

### Column Operations

```php
// Get all column names
$columns = $inspector->get_columns('users');
// Returns: ['id', 'name', 'email', 'password', 'created_at', ...]

// Check if column exists
if ($inspector->column_exists('users', 'email')) {
    echo "Email column found!";
}

// Get column type (normalized)
$type = $inspector->get_column_type('users', 'id');
// Returns: 'bigint' (normalized across engines)

// Get detailed column information
$details = $inspector->get_column_details('users');
// Returns:
// [
//     'id' => ['type' => 'bigint', 'nullable' => false, 'default' => null, 'auto_increment' => true],
//     'name' => ['type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false],
//     'email' => ['type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false],
// ]

// Check if column is nullable
$is_nullable = $inspector->is_column_nullable('users', 'email');  // false

// Get column default
$default = $inspector->get_column_default('users', 'is_active');  // true
```

### Index Operations

```php
// Get all indexes
$indexes = $inspector->get_indexes('users');
// Returns:
// [
//     'PRIMARY' => ['columns' => ['id'], 'unique' => true],
//     'uk_email' => ['columns' => ['email'], 'unique' => true],
//     'idx_created_at' => ['columns' => ['created_at'], 'unique' => false],
// ]

// Check if index exists
if ($inspector->has_index('users', 'idx_created_at')) {
    echo "Index found!";
}
```

### Primary Key Operations

```php
// Get primary key
$pk = $inspector->get_primary_key('users');
// Returns: ['id'] or null if no primary key

// Composite primary key
$pk = $inspector->get_primary_key('order_items');
// Returns: ['order_id', 'product_id']
```

### Foreign Key Operations

```php
// Get all foreign keys
$fks = $inspector->get_foreign_keys('orders');
// Returns:
// [
//     'fk_orders_user' => [
//         'columns' => ['user_id'],
//         'referenced_table' => 'users',
//         'referenced_columns' => ['id'],
//     ],
// ]

// Check if foreign key exists
if ($inspector->has_foreign_key('orders', 'fk_orders_user')) {
    echo "Foreign key found!";
}
```

### Constraint Operations

```php
// Get unique constraints
$unique = $inspector->get_unique_constraints('users');
// Returns: ['uk_email' => ['email'], 'uk_username' => ['username']]

// Get check constraints
$checks = $inspector->get_check_constraints('products');
// Returns: ['chk_price' => ['definition' => 'price > 0']]
```

### System Information

```php
// Get database engine type
$engine = $inspector->get_engine_type();  // 'mysql', 'pgsql', 'sqlite'

// Get server version
$version = $inspector->get_server_version();  // '8.0.32'

// Get protocol version
$protocol = $inspector->get_protocol_version();  // 10

// Get host info
$host = $inspector->get_host_info();  // 'localhost via TCP/IP'
```

---

## Migrations

Database migrations provide version control for your schema.

### Creating a Migration

```php
use Callismart\DBPrism\Migrations\AbstractMigration;

class Migration0001 extends AbstractMigration {
    public function up(): void {
        // Create users table
        $this->table('users')
            ->create()
            ->id()
            ->string('name', 100)
            ->string('email', 100)->unique()
            ->string('password', 255)
            ->timestamp('created_at')->default_current_timestamp()
            ->timestamp('updated_at')->nullable()
            ->save();
        
        // Create orders table with foreign key
        $this->table('orders')
            ->create()
            ->id()
            ->unsigned_big_integer('user_id')
            ->decimal('total', 10, 2)
            ->enum('status', ['pending', 'completed', 'cancelled'])
            ->foreign_key('user_id')
                ->references('users', 'id')
                ->on_delete('cascade')
            ->timestamp('created_at')->default_current_timestamp()
            ->save();
    }
}
```

### Running Migrations

```php
use Callismart\DBPrism\Migrations\MigrationRunner;

$runner = new MigrationRunner($db);
$runner->run();  // Execute all pending migrations
```

### Fluent Migration Helpers

```php
// Table helper
$this->table('users')->create()->id()->string('name', 100)->save();

// Column helper
$this->column('users')->add(Column::varchar('phone', 20)->nullable());

// Index helper
$this->index('users')->add('created_at', unique: false)->save();

// Constraint helper
$this->constraint('orders')
    ->foreign_key('user_id')
    ->references('users', 'id')
    ->on_delete('cascade')
    ->save();

// Inspector helper
$tables = $this->inspect()->get_all_tables();
if ($this->inspect()->table_exists('posts')) {
    // Do something
}
```

---

## Advanced Features

### Transactions

Execute multiple queries atomically with automatic rollback on failure.

```php
try {
    $result = $db->transactional(function() use ($db) {
        // Insert order
        $order_id = $db->insert('orders', [
            'user_id' => 1,
            'total'   => 99.99,
        ]);
        
        // Insert order items
        $db->insert('order_items', [
            'order_id'   => $order_id,
            'product_id' => 5,
            'quantity'   => 2,
            'price'      => 49.99,
        ]);
        
        return $order_id;
    });
    
    echo "Order created: {$result}";
} catch (\Throwable $e) {
    error_log("Transaction failed: " . $e->getMessage());
}
```

### Raw SQL Execution

For complex queries, you can use raw SQL directly.

```php
// Parameterized query (safe)
$users = $db->get_results(
    'SELECT * FROM users WHERE status = ? AND created_at > ?',
    ['active', '2024-01-01']
);

// Raw query without parameters (use with caution!)
$db->exec('ALTER TABLE users ADD COLUMN verified BOOLEAN DEFAULT FALSE');
```

### Multi-Engine SQL Generation

Build queries for different engines without changing code.

```php
$intent = $builder->select('id', 'name')
    ->from('users')
    ->where('status', '=', 'active');

// MySQL SQL
$mysql_sql = (new MySQLRenderer())->render_select($intent);

// PostgreSQL SQL
$pgsql_sql = (new PostgreSQLRenderer())->render_select($intent);

// SQLite SQL
$sqlite_sql = (new SQLiteRenderer())->render_select($intent);
```

### Schema-Aware Logic

Use the inspector to make database-aware decisions.

```php
$inspector = new Inspector($db);

// Check if table needs migration
if ($inspector->table_exists('users') && !$inspector->column_exists('users', 'verified')) {
    // Add verified column
    $this->table('users')->add_column(Column::boolean('verified')->default(false))->save();
}

// Dynamically build queries based on schema
$columns = $inspector->get_column_details('products');
foreach ($columns as $name => $info) {
    if ($info['type'] === 'json') {
        echo "Column '$name' stores JSON data";
    }
}
```

---

## API Reference

### Database Class

#### Query Execution
- `get_row(string $query, array $params = []): ?array` — Fetch single row
- `get_results(string $query, array $params = []): array` — Fetch multiple rows
- `get_var(string $query, array $params = []): mixed` — Fetch scalar value
- `get_col(string $query, array $params = []): array` — Fetch single column
- `execute(string $query, array $params = []): int` — Execute parameterized query
- `exec(string $query): bool` — Execute raw SQL (unsafe)

#### Data Manipulation
- `insert(string $table, array $data): int|false` — Insert record
- `update(string $table, array $data, array $where): int|false` — Update records
- `delete(string $table, array $where): int|false` — Delete records

#### Transactions
- `begin_transaction(): void`
- `commit(): void`
- `rollback(): void`
- `transactional(callable $callback): mixed` — Execute callback in transaction

#### Connection Info
- `get_driver(): string` — Database engine name
- `get_server_version(): string` — Database version
- `get_host_info(): string` — Connection host/socket info
- `is_connected(): bool` — Check connection status

### SQLBuilder Class

#### Query Builders
- `select(string ...$columns): SelectionIntent` — Start SELECT query
- `insert(string $table): PersistenceIntent` — Start INSERT query
- `update(string $table): PersistenceIntent` — Start UPDATE query
- `delete(string $table): DeleteIntent` — Start DELETE query
- `create_table(string $table): CreateTableIntent` — Start CREATE TABLE query
- `alter_table(string $table): AlterTableIntent` — Start ALTER TABLE query
- `truncate_table(string ...$tables): TruncateTableIntent` — Start TRUNCATE query
- `drop_table(string $table): self` — Start DROP TABLE query

#### Helpers
- `build(): string` — Build the final SQL
- `engine(string $engine): self` — Set target engine
- `reset(): self` — Reset the builder
- `get_type(): ?string` — Get current query type
- `get_engine(): string` — Get target engine

### SelectionIntent Class

#### Columns & Tables
- `select(string ...$columns): static` — Set columns to select
- `from(string $table): static` — Set source table

#### Joins
- `join(string $table, string $first, string $operator, string $second): static` — INNER JOIN
- `left_join(...): static` — LEFT JOIN
- `right_join(...): static` — RIGHT JOIN
- `cross_join(string $table): static` — CROSS JOIN

#### Conditions
- `where(string $column, string $operator, $value, string $boolean = 'AND'): static`
- `or_where(string $column, string $operator, $value): static`
- `where_null(string $column, string $boolean = 'AND', bool $not = false): static`
- `where_not_null(string $column, string $boolean = 'AND'): static`
- `where_in(string $column, array $values, string $boolean = 'AND', bool $not = false): static`
- `where_not_in(string $column, array $values): static`
- `where_between(string $column, $from, $to, string $boolean = 'AND', bool $not = false): static`
- `where_not_between(string $column, $from, $to): static`
- `where_raw(string $expression, array $bindings = [], string $boolean = 'AND'): static`
- `where_group(callable $callback, string $boolean = 'AND'): static`
- `or_where_group(callable $callback): static`

#### Grouping & Ordering
- `group_by(string ...$columns): static` — GROUP BY columns
- `order_by(string $column, string $direction = 'ASC'): static` — ORDER BY

#### Pagination
- `limit(int $limit): static` — Set row limit
- `offset(int $offset): static` — Set row offset

#### Accessors
- `get_columns(): array`
- `get_table_name(): string`
- `get_joins(): array`
- `get_groups(): array`
- `get_orders(): array`
- `get_conditions(): array`
- `get_bindings(): array`
- `get_limit(): ?int`
- `get_offset(): ?int`

### PersistenceIntent Class (INSERT/UPDATE)

#### Data
- `values(array $data): static` — Set single row data
- `set(array $data): static` — Alias for values()
- `multi_values(array $rows): static` — Set multi-row data

#### Conditions (UPDATE only)
- `where(...): static` — WHERE clause
- `or_where(...): static` — OR WHERE clause
- `where_null(...): static` — IS NULL condition
- (all QueryCriteriaTrait methods)

#### Accessors
- `get_table_name(): string`
- `get_data(): array`
- `is_multi(): bool`
- `get_bindings(): array`

### DeleteIntent Class

#### Conditions
- `where(...): static` — WHERE clause
- `or_where(...): static` — OR WHERE clause
- `where_null(...): static` — IS NULL condition
- (all QueryCriteriaTrait methods)

#### Accessors
- `get_table_name(): string`
- `get_conditions(): array`
- `get_bindings(): array`

### CreateTableIntent Class

#### Columns
- `add_column(Column $column): static` — Add single column
- `add_columns(array $columns): static` — Add multiple columns

#### Constraints
- `add_constraint(Constraint $constraint): static` — Add constraint
- `add_constraints(array $constraints): static` — Add multiple constraints

#### Accessors
- `get_table_name(): string`
- `get_columns(): array`
- `get_constraints(): array`

### AlterTableIntent Class

#### Operations
- `rename(string $new_name): static` — Rename table
- `add_column(Column $column, ?Constraint $constraint = null): static` — Add column
- `modify_column(Column $column, ?Constraint $constraint = null): static` — Modify column
- `drop_column(string $name): static` — Drop column
- `rename_column(string $from, string $to): static` — Rename column
- `add_constraint(Constraint $constraint): static` — Add constraint
- `drop_constraint(string $constraint_name): static` — Drop constraint
- `drop_index(string $index_name): static` — Drop index

#### Accessors
- `get_table_name(): string`
- `get_operations(): array`

### Inspector Class

#### Tables
- `get_all_tables(): array`
- `table_exists(string $table): bool`
- `get_table_metadata(string $table): array`

#### Columns
- `get_columns(string $table): array`
- `column_exists(string $table, string $column): bool`
- `get_column_type(string $table, string $column): ?string`
- `get_column_details(string $table): array`
- `is_column_nullable(string $table, string $column): ?bool`
- `get_column_default(string $table, string $column): mixed`

#### Indexes
- `get_indexes(string $table): array`
- `has_index(string $table, string $index_name): bool`

#### Keys
- `get_primary_key(string $table): ?array`
- `get_foreign_keys(string $table): array`
- `has_foreign_key(string $table, string $constraint): bool`

#### Constraints
- `get_unique_constraints(string $table): array`
- `get_check_constraints(string $table): array`

#### System Info
- `get_engine_type(): string`
- `get_server_version(): string`
- `get_protocol_version(): ?string`
- `get_host_info(): string`

---

## Type Normalization

DBPrism normalizes column types across engines for consistency:

| Normalized | MySQL | PostgreSQL | SQLite |
|-----------|-------|-----------|--------|
| `int` | `INT` | `INTEGER` | `INTEGER` |
| `bigint` | `BIGINT` | `BIGINT` | `INTEGER` |
| `varchar` | `VARCHAR` | `CHARACTER VARYING` | `TEXT` |
| `text` | `TEXT` | `TEXT` | `TEXT` |
| `decimal` | `DECIMAL` | `NUMERIC` | `REAL` |
| `boolean` | `TINYINT` | `BOOLEAN` | `INTEGER` |
| `datetime` | `DATETIME` | `TIMESTAMP` | `TEXT` |
| `json` | `JSON` | `JSONB` | `TEXT` |

---

## Best Practices

1. **Always Use Parameterized Queries**
   ```php
   // ✓ Good
   $user = $db->get_row('SELECT * FROM users WHERE id = ?', [$id]);
   
   // ✗ Bad
   $user = $db->get_row("SELECT * FROM users WHERE id = {$id}");
   ```

2. **Use Transactions for Related Operations**
   ```php
   $db->transactional(function() use ($db) {
       $order_id = $db->insert('orders', $order_data);
       foreach ($items as $item) {
           $db->insert('order_items', [...$item, 'order_id' => $order_id]);
       }
   });
   ```

3. **Leverage the Inspector**
   ```php
   $inspector = new Inspector($db);
   if ($inspector->table_exists('users') && 
       $inspector->column_exists('users', 'email')) {
       // Safe to query
   }
   ```

4. **Use Migrations for Schema Changes**
   - Don't use raw SQL for DDL operations
   - Migrations are version-controlled and reversible

5. **Handle Database Errors Gracefully**
   ```php
   try {
       $result = $db->transactional(function() use ($db) {
           // operations
       });
   } catch (\Throwable $e) {
       error_log($db->get_last_error());
   }
   ```

---

## Testing

Run the test suite:

```bash
composer test
```

Or with PHPUnit directly:

```bash
./vendor/bin/phpunit --colors=always --testdox
```

---

## Contributing

Contributions are welcome! Please:

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

---

## License

This project is licensed under the MIT License — see the [LICENSE](LICENSE) file for details.

---

## Author

**Callistus Nwachukwu**
- Email: admin@callismart.com.ng
- Website: https://callismart.com.ng

---

## Support

For issues, questions, or feature requests:
- 🐛 [Report a bug](https://github.com/callismart/dbprism/issues)
- 💡 [Request a feature](https://github.com/callismart/dbprism/issues)
- 📖 [Read the documentation](https://github.com/callismart/dbprism/wiki)

---

**Made with ❤️ by Callismart**