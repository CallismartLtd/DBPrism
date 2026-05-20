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
- [Schema Inspection](#schema-inspection)
- [Migrations](#migrations)
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
$intent = $builder->select('id', 'name', 'email')
    ->from('users')
    ->where('status', '=', 'active')
    ->where('created_at', '>', '2024-01-01')
    ->order_by('created_at', 'DESC')
    ->limit(10);

$sql = $intent->build();
$bindings = $intent->get_bindings();

$users = $db->get_results($sql, $bindings);
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
$db = new Database($adapter);
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
$db = new Database($adapter);
```

### SQLite
```php
use Callismart\DBPrism\Adapters\SqliteAdapter;

$config = new DBConfigDTO([
    'dbname' => '/path/to/database.sqlite',
    'driver' => 'sqlite',
]);

$adapter = new SqliteAdapter($config);
$db = new Database($adapter);
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
$db = new Database($adapter);
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

$intent = $builder->select('id', 'name', 'email')
    ->from('users');

$sql = $intent->build();
// SELECT `id`, `name`, `email` FROM `users`;
```

#### SELECT with WHERE Conditions

```php
$intent = $builder->select('*')
    ->from('orders')
    ->where('status', '=', 'completed')
    ->where('total', '>', 100);

$sql = $intent->build();
$bindings = $intent->get_bindings();
```

#### WHERE Operators & Conditions

```php
// Basic comparison
->where('age', '>=', 18)
->where('name', '!=', 'Admin')
->where('email', 'LIKE', '%@example.com')

// IS NULL / IS NOT NULL
->where_null('deleted_at')
->where_not_null('verified_at')

// Direct SQL operators
->where('deleted_at', 'IS NULL')
->where('verified_at', 'IS NOT NULL')

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

// Raw SQL
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
```

#### GROUP BY, ORDER BY, LIMIT/OFFSET

```php
$intent = $builder->select('category', 'COUNT(*) as total')
    ->from('products')
    ->group_by('category')
    ->order_by('total', 'DESC')
    ->limit(10)
    ->offset(0);

$sql = $intent->build();
```

### INSERT Queries

Insert queries are built using the `PersistenceIntent` class, accessed via `SQLBuilder::insert()`.

#### Single Row Insert

```php
$intent = $builder->insert('users')
    ->values([
        'name'       => 'John Doe',
        'email'      => 'john@example.com',
        'password'   => hash('sha256', 'secret'),
    ]);

$sql = $intent->build();
$bindings = $intent->get_bindings();
```

#### Multi-Row Insert (Bulk)

```php
$intent = $builder->insert('users')
    ->multi_values([
        ['name' => 'John Doe', 'email' => 'john@example.com'],
        ['name' => 'Jane Smith', 'email' => 'jane@example.com'],
        ['name' => 'Bob Wilson', 'email' => 'bob@example.com'],
    ]);

$sql = $intent->build();
```

#### Insert with SET Alias

```php
$intent = $builder->insert('users')
    ->set(['name' => 'John Doe', 'email' => 'john@example.com']);
```

### UPDATE Queries

Update queries are built using the `PersistenceIntent` class, accessed via `SQLBuilder::update()`.

#### Basic UPDATE

```php
$intent = $builder->update('users')
    ->set([
        'status'     => 'inactive',
        'updated_at' => date('Y-m-d H:i:s'),
    ])
    ->where('id', '=', 1);

$sql = $intent->build();
```

#### UPDATE with Multiple WHERE Conditions

```php
$intent = $builder->update('users')
    ->set([
        'verified' => true,
        'verified_at' => date('Y-m-d H:i:s'),
    ])
    ->where('email_confirmed', '=', true)
    ->where_null('deleted_at')
    ->where_in('status', ['pending', 'new']);
```

### DELETE Queries

Delete queries are built using the `DeleteIntent` class, accessed via `SQLBuilder::delete()`.

#### Basic DELETE

```php
$intent = $builder->delete('users')
    ->where('id', '=', 1);

$sql = $intent->build();
```

#### DELETE with Multiple Conditions

```php
$intent = $builder->delete('sessions')
    ->where('user_id', '=', 5)
    ->where('expires_at', '<', date('Y-m-d H:i:s'));
```

---

## Schema Operations

### CREATE TABLE

Create tables using the `CreateTableIntent` class with fluent `Column` and `Constraint` builders.

#### Basic CREATE TABLE

```php
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;

$builder = new SQLBuilder($db->get_driver());

$intent = $builder->create_table('users')
    ->add_columns([
        Column::make('id')
            ->type(ColumnType::BIG_INT)
            ->auto_increment()
            ->unsigned()
            ->required(),
        
        Column::make('name')
            ->type(ColumnType::VARCHAR)
            ->size(100)
            ->required(),
        
        Column::make('email')
            ->type(ColumnType::VARCHAR)
            ->size(100)
            ->required(),
        
        Column::make('password')
            ->type(ColumnType::VARCHAR)
            ->size(255)
            ->required(),
        
        Column::make('created_at')
            ->type(ColumnType::DATETIME)
            ->default(DefaultColumnValue::expression('CURRENT_TIMESTAMP')),
    ])
    ->add_constraints([
        Constraint::primary('users_pk')
            ->on('id'),
        
        Constraint::unique('users_email_unique')
            ->on('email'),
    ]);

$sql = $intent->build();
$db->exec($sql);
```

#### CREATE TABLE with Foreign Keys

```php
$intent = $builder->create_table('posts')
    ->add_columns([
        Column::make('id')
            ->type(ColumnType::BIG_INT)
            ->auto_increment()
            ->unsigned()
            ->required(),
        
        Column::make('user_id')
            ->type(ColumnType::BIG_INT)
            ->unsigned()
            ->required(),
        
        Column::make('title')
            ->type(ColumnType::VARCHAR)
            ->size(200)
            ->required(),
        
        Column::make('body')
            ->type(ColumnType::TEXT)
            ->required(),
        
        Column::make('created_at')
            ->type(ColumnType::DATETIME)
            ->default(DefaultColumnValue::expression('CURRENT_TIMESTAMP')),
    ])
    ->add_constraints([
        Constraint::primary('posts_pk')
            ->on('id'),
        
        Constraint::foreign_key('posts_user_fk')
            ->on('user_id')
            ->references('users', 'id')
            ->on_delete('CASCADE')
            ->on_update('CASCADE'),
    ]);

$sql = $intent->build();
$db->exec($sql);
```

### Column Types

```php
use Callismart\DBPrism\Utils\ColumnType;

// Integer types
->type(ColumnType::INT)->size(11)
->type(ColumnType::BIG_INT)->unsigned()
->type(ColumnType::TINY_INT)
->type(ColumnType::SMALL_INT)

// String types
->type(ColumnType::VARCHAR)->size(100)
->type(ColumnType::CHAR)->size(10)
->type(ColumnType::TEXT)

// Numeric types
->type(ColumnType::DECIMAL)->precision(10, 2)
->type(ColumnType::FLOAT)
->type(ColumnType::DOUBLE)

// Date/Time types
->type(ColumnType::DATE)
->type(ColumnType::TIME)
->type(ColumnType::DATETIME)
->type(ColumnType::TIMESTAMP)

// Other types
->type(ColumnType::BOOLEAN)
->type(ColumnType::JSON)
->type(ColumnType::ENUM)
```

### Column Modifiers

```php
Column::make('email')
    ->type(ColumnType::VARCHAR)
    ->size(100)
    ->required()              // NOT NULL
    ->unsigned()              // UNSIGNED (for numeric types)
    ->auto_increment()        // AUTO_INCREMENT
    ->default('active')       // DEFAULT value
    ->default(DefaultColumnValue::expression('CURRENT_TIMESTAMP'))
```

### ALTER TABLE

Modify existing tables using the `AlterTableIntent` class.

```php
$intent = $builder->alter_table('users')
    ->rename_column('old_name', 'new_name');

$sql = $intent->build();
$db->exec($sql);
```

**[SECTION NEEDS CLARIFICATION]** — I need more context on:
- Complete API for `add_column()`, `modify_column()`, `drop_column()`, `drop_constraint()`, `drop_index()` methods on `AlterTableIntent`
- How these are called exactly

### TRUNCATE TABLE

```php
$intent = $builder->truncate_table('logs')
    ->restart_identity(true)
    ->cascade(false);

$sql = $intent->build();
$db->exec($sql);
```

### DROP TABLE

```php
$intent = $builder->drop_table('old_table')
    ->if_exists();

$sql = $intent->build();
$db->exec($sql);
```

---

## Migrations with Fluent Helpers

### TableHelper

The `TableHelper` provides fluent interface for table-level operations.

```php
use Callismart\DBPrism\Migrations\Helpers\TableHelper;

$helper = new TableHelper($db, new SQLBuilder($db->get_driver()), 'users');

// Rename table
$helper->rename('new_table_name');

// Truncate table
$helper->truncate(restart: true, cascade: false);

// Drop table
$helper->drop(exists_check: true);

// Drop index
$helper->drop_index('idx_created_at');

// Access column operations
$helper->column();

// Access constraint operations
$helper->constraint();
```

### ColumnHelper

The `ColumnHelper` provides fluent interface for column-level operations.

```php
use Callismart\DBPrism\Migrations\Helpers\ColumnHelper;

$helper = new ColumnHelper($db, new SQLBuilder($db->get_driver()), 'users');

// Add column
$helper->add(Column::make('phone')
    ->type(ColumnType::VARCHAR)
    ->size(20)
    ->nullable()
);

// Drop column
$helper->drop('deprecated_field');

// Rename column
$helper->rename('old_name', 'new_name');

// Modify column
$helper->modify(Column::make('name')
    ->type(ColumnType::VARCHAR)
    ->size(255)
);

// Change column type
$helper->changeType('status', ColumnType::ENUM);

// Check operations
$helper->exists('email');           // bool
$helper->getType('email');          // string|null
$helper->list();                    // array of column names
```

### ConstraintHelper

The `ConstraintHelper` provides fluent interface for constraint operations.

**[SECTION NEEDS CLARIFICATION]** — I need more context on:
- Complete method signatures and usage for:
  - `add_primary_key()`, `add_unique()`, `add_foreign_key()`
  - `drop_primary_key()`, `drop_unique()`, `drop_foreign_key()`
  - `add_constraint()`, `drop()`, `drop_constraint()`
- SQLite-specific constraint handling details
- How to properly call these methods with parameters

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
// Returns: ['engine' => 'InnoDB', 'charset' => 'utf8mb4', 'collation' => '...', 'row_count' => 1250, 'comment' => '']
```

### Column Operations

```php
// Get all column names
$columns = $inspector->get_columns('users');

// Check if column exists
$inspector->column_exists('users', 'email');

// Get column type (normalized)
$type = $inspector->get_column_type('users', 'id');

// Get detailed column information
$details = $inspector->get_column_details('users');

// Check if column is nullable
$inspector->is_column_nullable('users', 'email');

// Get column default
$inspector->get_column_default('users', 'is_active');
```

### Index Operations

```php
// Get all indexes
$indexes = $inspector->get_indexes('users');

// Check if index exists
$inspector->has_index('users', 'idx_created_at');
```

### Primary Key Operations

```php
// Get primary key
$pk = $inspector->get_primary_key('users');
// Returns: ['id'] or null
```

### Foreign Key Operations

```php
// Get all foreign keys
$fks = $inspector->get_foreign_keys('orders');

// Check if foreign key exists
$inspector->has_foreign_key('orders', 'fk_orders_user');
```

### Constraint Operations

```php
// Get unique constraints
$unique = $inspector->get_unique_constraints('users');

// Get check constraints
$checks = $inspector->get_check_constraints('products');
```

### System Information

```php
// Get database engine type
$engine = $inspector->get_engine_type();  // 'mysql', 'pgsql', 'sqlite'

// Get server version
$version = $inspector->get_server_version();

// Get protocol version
$protocol = $inspector->get_protocol_version();

// Get host info
$host = $inspector->get_host_info();
```

---

## Transactions

Execute multiple queries atomically with automatic rollback on failure.

```php
try {
    $result = $db->transactional(function() use ($db) {
        $order_id = $db->insert('orders', ['total' => 99.99]);
        $db->insert('order_items', ['order_id' => $order_id, 'product_id' => 5]);
        return $order_id;
    });
} catch (\Throwable $e) {
    error_log("Transaction failed: " . $e->getMessage());
}
```

---

## Best Practices

1. **Always Use Parameterized Queries**
   ```php
   // ✓ Good
   $user = $db->get_row('SELECT * FROM users WHERE id = ?', [$id]);
   
   // ✗ Bad - SQL injection vulnerability
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

3. **Leverage the Inspector for Schema-Aware Logic**
   ```php
   $inspector = new Inspector($db);
   if ($inspector->table_exists('users') && 
       $inspector->column_exists('users', 'email')) {
       // Safe to query
   }
   ```

4. **Handle Database Errors Gracefully**
   ```php
   try {
       $result = $db->transactional(function() use ($db) {
           // operations
       });
   } catch (\Throwable $e) {
       error_log($e->getMessage());
   }
   ```

---

## Testing

Run the test suite:

```bash
composer test
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
- 🐛 [Report a bug](https://github.com/CallismartLtd/dbprism/issues)
- 💡 [Request a feature](https://github.com/CallismartLtd/dbprism/issues)
- 📖 [Read the documentation](https://github.com/CallismartLtd/dbprism/wiki)

---

**Made with ❤️ by Callismart**