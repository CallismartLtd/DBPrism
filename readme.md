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
  - [Running an Intent](#running-an-intent)
  - [SELECT Queries](#select-queries)
  - [INSERT Queries](#insert-queries)
  - [UPDATE Queries](#update-queries)
  - [DELETE Queries](#delete-queries)
- [Schema Operations](#schema-operations)
  - [CREATE TABLE](#create-table)
  - [Column Types](#column-types)
  - [Column Modifiers](#column-modifiers)
  - [Default Values](#default-values)
  - [ALTER TABLE](#alter-table)
  - [TRUNCATE TABLE](#truncate-table)
  - [DROP TABLE](#drop-table)
- [Schema Inspection](#schema-inspection)
- [Migrations](#migrations)
- [Streaming Large Results](#streaming-large-results)
- [Transactions](#transactions)
- [Best Practices](#best-practices)
- [Testing](#testing)

---

## What is DBPrism?

DBPrism lets one PHP codebase run on MySQL, MariaDB, PostgreSQL and SQLite. You describe a query, a table or a schema change, and DBPrism writes the SQL for the engine you are on.

It is made of parts that work on their own. Use one, some or all of them:

- **Query builder.** Turns what you describe into SQL and its bound values for a given engine. It never opens or touches a connection, so its output runs through DBPrism, plain PDO, or whatever client your application already uses.
- **Database layer.** One API over the native drivers and PDO. It is optional, and it never asks to own your connection: an adapter can wrap the one your application already opened (see [Your Own Adapter](#your-own-adapter)).
- **Schema inspection.** Reads tables, columns, indexes and constraints the same way on every engine.
- **Migrations.** Ordered schema changes, recorded by each database, where every step is safe to run again. On SQLite, the changes its `ALTER TABLE` cannot make are done by rebuilding the table.

Inspection and migrations run on the database layer; the query builder needs nothing else.

Inside, each layer has one job:

- An **intent** records what you want (a SELECT, a new table, a column change).
- A **renderer** writes that intent in the SQL of one engine.
- An **adapter** runs the SQL on one connection.

---

## Installation

```bash
composer require callismart/dbprism
```

**Requirements:**
- PHP 8.1+
- [`callismart/dto`](https://packagist.org/packages/callismart/dto) (installed automatically)
- The PHP extension for each engine you use:

| Engine | Native adapter | PDO adapter |
|---|---|---|
| MySQL / MariaDB | `mysqli` | `pdo_mysql` |
| PostgreSQL | None | `pdo_pgsql` |
| SQLite | `sqlite3` | `pdo_sqlite` |

---

## Quick Start

### 1. Initialize the Database

```php
use Callismart\DBPrism\Database;
use Callismart\DBPrism\Adapters\MysqliAdapter;
use Callismart\DBPrism\DBConfigDTO;

$config = new DBConfigDTO( [
    'host'     => 'localhost',
    'username' => 'root',
    'password' => 'secret',
    'dbname'   => 'myapp',
    'driver'   => 'mysql',
] );

$adapter = new MysqliAdapter( $config );
$db      = new Database( $adapter );
```

### 2. Execute Simple Queries

```php
// Insert: returns the new row's ID, or false on failure.
// With no generated ID it returns 0 (on SQLite, the row's rowid).
$user_id = $db->insert( 'users', [
    'name'  => 'John Doe',
    'email' => 'john@example.com',
] );

// Fetch
$user = $db->get_row( 'SELECT * FROM users WHERE id = ?', [ $user_id ] );

// Update: returns the number of rows changed, or false on failure.
$db->update( 'users',
    [ 'status' => 'active' ],
    [ 'id' => $user_id ]
);

// Delete: returns the number of rows removed, or false on failure.
$db->delete( 'users', [ 'id' => $user_id ] );
```

A failed query returns `false` (or an empty result) and records the reason, which `$db->get_last_error()` returns. Each query clears the previous error.

### 3. Build Complex Queries with Intents

```php
use Callismart\DBPrism\Query\SQLBuilder;

$builder = new SQLBuilder( $db->get_driver() );

// Build a SELECT query
$intent = $builder->select( 'id', 'name', 'email' )
    ->from( 'users' )
    ->where( 'status', '=', 'active' )
    ->where( 'created_at', '>', '2024-01-01' )
    ->order_by( 'created_at', 'DESC' )
    ->limit( 10 );

$sql      = $intent->build();
$bindings = $intent->get_bindings();

$users = $db->get_results( $sql, $bindings );
```

---

## Supported Databases

Tested with MySQL 8.0, MariaDB 10.11, PostgreSQL 16, and SQLite 3.45, each through its native driver and through PDO.

The configuration's `driver` is always the engine: `mysql` (also for MariaDB), `pgsql`, or `sqlite`. `driver` and `dbname` are required.

### MySQL / MariaDB
```php
use Callismart\DBPrism\Adapters\MysqliAdapter;

$config = new DBConfigDTO( [
    'host'     => 'localhost',
    'username' => 'root',
    'password' => 'secret',
    'dbname'   => 'myapp',
    'driver'   => 'mysql',
] );

$adapter = new MysqliAdapter( $config );
$db      = new Database( $adapter );
```

### PostgreSQL
```php
use Callismart\DBPrism\Adapters\PostgresAdapter;

$config = new DBConfigDTO( [
    'host'     => 'localhost',
    'username' => 'postgres',
    'password' => 'secret',
    'dbname'   => 'myapp',
    'driver'   => 'pgsql',
] );

$adapter = new PostgresAdapter( $config );
$db      = new Database( $adapter );
```

### SQLite
```php
use Callismart\DBPrism\Adapters\SqliteAdapter;

$config = new DBConfigDTO( [
    'dbname' => '/path/to/database.sqlite',
    'driver' => 'sqlite',
] );

$adapter = new SqliteAdapter( $config );
$db      = new Database( $adapter );
```

### PDO (Universal)

`PdoAdapter` connects to any of the three engines through PDO. The DSN is built from the configuration; set `dsn` to supply your own.

```php
use Callismart\DBPrism\Adapters\PdoAdapter;

$config = new DBConfigDTO( [
    'host'     => 'localhost',
    'username' => 'root',
    'password' => 'secret',
    'dbname'   => 'myapp',
    'driver'   => 'mysql',
] );

$adapter = new PdoAdapter( $config );
$db      = new Database( $adapter );
```

### Your Own Adapter

Any connection can be used by implementing `DatabaseAdapterInterface` (and `StreamingAdapterInterface` for `stream()`). Map bound values with `ParamType::of()`, as the built-in adapters do, so they are sent the same way on every connection (see [Bound Values](#bound-values)).

### Before a Database Is Configured

During installation, before credentials exist, construct the `Database` with `NullDBAdapter` so services that depend on it can still be built. It never connects: every query returns its "nothing happened" value and records an error. Swap in the real adapter once its connection is verified.

```php
use Callismart\DBPrism\Adapters\NullDBAdapter;

$db = new Database( new NullDBAdapter() );

$db->has_null_adapter(); // true: an empty result means "no database", not "no rows"

// Later, once the credentials work:
$db->set_adapter( new MysqliAdapter( $config ) );
```

---

## Query Building API

### Running an Intent

`SQLBuilder` creates an intent for the engine you name. An intent's `build()` returns the SQL with `?` placeholders, and `get_bindings()` returns the values for them, in order. Run them with the `Database` method that fits the result you want:

| Method | Returns |
|---|---|
| `get_results( $sql, $bindings )` | Every row, as associative arrays |
| `get_row( $sql, $bindings )` | The first row, or `null` |
| `get_col( $sql, $bindings )` | The first column of every row |
| `get_var( $sql, $bindings )` | The first column of the first row, or `null` |
| `execute( $sql, $bindings )` | The number of rows affected |
| `exec( $sql )` | `true` on success; for statements without values, such as schema changes |

The SQL is ordinary SQL, so the database layer is not required. With a connection you already have:

```php
$intent = ( new SQLBuilder( 'pgsql' ) )
    ->select( 'id', 'email' )
    ->from( 'users' )
    ->where( 'status', '=', 'active' );

$stmt = $pdo->prepare( $intent->build() );
$stmt->execute( $intent->get_bindings() );
```

Run that way, the values are bound by your client, not by the rules below.

#### Bound Values

A value's PHP type decides how it is sent; its contents are never inspected, so `'123'` stays a string.

| PHP value | Sent as |
|---|---|
| `null` | NULL |
| `bool` | Boolean (`1` / `0` on MySQL and SQLite) |
| `int` | Integer |
| `float` | Number, exactly: `0.1 + 0.2` arrives as `0.30000000000000004` |
| `string` | Text |
| Open stream resource | Binary data (`BLOB`, `BYTEA`) |

Anything else (an array, an object, `INF` or `NAN`) throws `InvalidArgumentException` before the query is sent.

Binary data is marked by passing it as a stream, which is read from its current position to the end:

```php
$stream = fopen( 'php://memory', 'r+' );
fwrite( $stream, $bytes );
rewind( $stream );

$db->insert( 'files', [ 'name' => 'logo.png', 'content' => $stream ] );
```

Binary columns are read back as strings on every engine.

### SELECT Queries

Select queries are built using the `SelectionIntent` class, accessed via `SQLBuilder::select()`.

#### Basic SELECT

```php
$builder = new SQLBuilder( 'mysql' );

$intent = $builder->select( 'id', 'name', 'email' )
    ->from( 'users' );

$sql = $intent->build();
// SELECT `id`, `name`, `email` FROM `users`;
```

#### SELECT with WHERE Conditions

```php
$intent = $builder->select( '*' )
    ->from( 'orders' )
    ->where( 'status', '=', 'completed' )
    ->where( 'total', '>', 100 );

$sql      = $intent->build();
$bindings = $intent->get_bindings(); // [ 'completed', 100 ]
```

#### WHERE Operators & Conditions

```php
// Basic comparison
->where( 'age', '>=', 18 )
->where( 'name', '!=', 'Admin' )

// IS NULL / IS NOT NULL
->where_null( 'deleted_at' )
->where_not_null( 'verified_at' )

// IN / NOT IN
->where_in( 'status', [ 'active', 'pending' ] )
->where_not_in( 'role', [ 'banned', 'suspended' ] )

// BETWEEN / NOT BETWEEN
->where_between( 'age', 18, 65 )
->where_not_between( 'created_at', '2023-01-01', '2023-12-31' )

// Compare two columns
->where_column( 'updated_at', '>', 'created_at' )

// OR conditions
->where( 'status', '=', 'active' )
->or_where( 'status', '=', 'pending' )

// Grouped conditions: ( status = 'active' OR status = 'pending' )
->where_group( function ( $q ) {
    $q->where( 'status', '=', 'active' )
      ->or_where( 'status', '=', 'pending' );
} )

// Raw SQL: written as given, so it must suit the engine it runs on
->where_raw( 'total > discount * ?', [ 2 ] )
```

#### Text Search

The search helpers escape `%` and `_` in the term, so they always match literally.

```php
->where_contains( 'title', '50% off' )      // LIKE '%50=% off%'
->where_starts_with( 'sku', 'AB_' )         // LIKE 'AB=_%'
->where_ends_with( 'email', '@example.com' )

// Your own pattern: wildcards at the ends are kept, any inside are escaped.
->where_like( 'email', '%@example.com' )
```

Each has `or_` and `not_` forms (`or_where_contains()`, `where_not_starts_with()` and so on).

#### Subqueries

```php
// WHERE user_id IN ( SELECT id FROM users WHERE banned = ? )
->where_in_subquery( 'user_id', function ( $q ) {
    $q->select( 'id' )->from( 'users' )->where( 'banned', '=', 1 );
} )

// WHERE EXISTS ( ... )
->where_exists( function ( $q ) {
    $q->select( 'id' )->from( 'orders' )->where_column( 'orders.user_id', '=', 'users.id' );
} )

// A subquery as a column
$builder->select( 'id', 'name' )
    ->select_subquery( function ( $q ) {
        $q->select( 'COUNT(*)' )->from( 'orders' )->where_column( 'orders.user_id', '=', 'users.id' );
    }, 'order_count' )
    ->from( 'users' );
```

#### JOINs

```php
// INNER JOIN
->join( 'orders', 'users.id', '=', 'orders.user_id' )

// LEFT JOIN
->left_join( 'profiles', 'users.id', '=', 'profiles.user_id' )

// RIGHT JOIN
->right_join( 'departments', 'employees.dept_id', '=', 'departments.id' )

// CROSS JOIN
->cross_join( 'statuses' )

// Several conditions: pass a callback that receives a JoinCriteria
->left_join( 'orders', function ( $on ) {
    $on->on_column( 'orders.user_id', '=', 'users.id' )
       ->on_value( 'orders.status', '=', 'paid' )
       ->on_null( 'orders.deleted_at' );
} )
```

#### GROUP BY, HAVING, ORDER BY, LIMIT/OFFSET

```php
$intent = $builder->select( 'category', 'COUNT(*) as total' )
    ->from( 'products' )
    ->group_by( 'category' )
    ->having( 'COUNT(*)', '>', 5 )
    ->order_by( 'total', 'DESC' )
    ->limit( 10 )
    ->offset( 0 );

$sql = $intent->build();
```

#### CASE Expressions

```php
$intent = $builder->select( 'id', 'total' )
    ->select_case( function ( $case ) {
        $case->when( fn( $c ) => $c->where( 'total', '>=', 1000 ), 'gold' )
             ->when( fn( $c ) => $c->where( 'total', '>=', 100 ), 'silver' )
             ->else( 'bronze' )
             ->as( 'tier' );
    } )
    ->from( 'orders' )
    ->order_by_case( function ( $case ) {
        $case->when( fn( $c ) => $c->where( 'status', '=', 'urgent' ), 0 )
             ->else( 1 );
    } );
```

`group_by_case()` and `having_case()` take the same callback.

#### DISTINCT and Raw Columns

```php
$builder->select( 'country' )->distinct()->from( 'users' );

// Written as given, without quoting
$builder->select_raw( 'COUNT(*) AS total' )->from( 'users' );
```

#### UNION

`union()` and `union_all()` combine queries into a `CompoundQueryIntent`, which can be ordered and limited as a whole.

```php
$active   = $builder->select( 'id', 'email' )->from( 'users' );
$archived = ( new SQLBuilder( $db->get_driver() ) )->select( 'id', 'email' )->from( 'archived_users' );

$intent = $active->union( $archived )
    ->order_by( 'email' )
    ->limit( 50 );

$rows = $db->get_results( $intent->build(), $intent->get_bindings() );
```

Each query needs its own `SQLBuilder`. The compound query works on a copy of the first query's builder, so `$active->build()` still returns `$active` alone.

#### Row Locks

```php
->lock_for_update()   // FOR UPDATE
->shared_lock()       // FOR SHARE
->lock_no_wait()      // FOR UPDATE NOWAIT
->lock_mode_skip()    // FOR UPDATE SKIP LOCKED
```

Locks take effect inside a transaction. SQLite has no row locks; they are left out of its SQL.

### INSERT Queries

Insert queries are built using the `PersistenceIntent` class, accessed via `SQLBuilder::insert()`.

#### Single Row Insert

```php
$intent = $builder->insert( 'users' )
    ->values( [
        'name'     => 'John Doe',
        'email'    => 'john@example.com',
        'password' => password_hash( 'secret', PASSWORD_DEFAULT ),
    ] );

$db->execute( $intent->build(), $intent->get_bindings() );
```

#### Multi-Row Insert (Bulk)

```php
$intent = $builder->insert( 'users' )
    ->multi_values( [
        [ 'name' => 'John Doe', 'email' => 'john@example.com' ],
        [ 'name' => 'Jane Smith', 'email' => 'jane@example.com' ],
        [ 'name' => 'Bob Wilson', 'email' => 'bob@example.com' ],
    ] );

$sql = $intent->build();
```

#### Insert with SET Alias

```php
$intent = $builder->insert( 'users' )
    ->set( [ 'name' => 'John Doe', 'email' => 'john@example.com' ] );
```

### UPDATE Queries

Update queries are built using the `PersistenceIntent` class, accessed via `SQLBuilder::update()`.

#### Basic UPDATE

```php
$intent = $builder->update( 'users' )
    ->set( [
        'status'     => 'inactive',
        'updated_at' => date( 'Y-m-d H:i:s' ),
    ] )
    ->where( 'id', '=', 1 );

$sql = $intent->build();
```

#### UPDATE with Multiple WHERE Conditions

```php
$intent = $builder->update( 'users' )
    ->set( [
        'verified'    => true,
        'verified_at' => date( 'Y-m-d H:i:s' ),
    ] )
    ->where( 'email_confirmed', '=', true )
    ->where_null( 'deleted_at' )
    ->where_in( 'status', [ 'pending', 'new' ] );
```

#### UPDATE with CASE

```php
$intent = $builder->update( 'products' )
    ->set_case( 'stock_status', function ( $case ) {
        $case->when( fn( $c ) => $c->where( 'stock', '=', 0 ), 'out' )
             ->when( fn( $c ) => $c->where( 'stock', '<', 10 ), 'low' )
             ->else( 'in' );
    } );
```

`set_case()` is for UPDATE only.

### DELETE Queries

Delete queries are built using the `DeleteIntent` class, accessed via `SQLBuilder::delete()`.

#### Basic DELETE

```php
$intent = $builder->delete( 'users' )
    ->where( 'id', '=', 1 );

$sql = $intent->build();
```

#### DELETE with Multiple Conditions

```php
$intent = $builder->delete( 'sessions' )
    ->where( 'user_id', '=', 5 )
    ->where( 'expires_at', '<', date( 'Y-m-d H:i:s' ) );
```

---

## Schema Operations

### CREATE TABLE

`create_table()` returns a `CreateTableIntent`, built from `Column` and `Constraint` objects.

#### Basic CREATE TABLE

```php
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\SQLExpression;

$builder = new SQLBuilder( $db->get_driver() );

$intent = $builder->create_table( 'users' )
    ->add_columns( [
        Column::make( 'id' )
            ->type( ColumnType::BIG_INT )
            ->auto_increment()
            ->unsigned()
            ->required(),

        Column::make( 'name' )
            ->type( ColumnType::VARCHAR )
            ->size( 100 )
            ->required(),

        Column::make( 'email' )
            ->type( ColumnType::VARCHAR )
            ->size( 100 )
            ->required(),

        Column::make( 'password' )
            ->type( ColumnType::VARCHAR )
            ->size( 255 )
            ->required(),

        Column::make( 'created_at' )
            ->type( ColumnType::DATETIME )
            ->default( SQLExpression::currentTimestamp() ),
    ] )
    ->add_constraints( [
        Constraint::primary( 'users_pk' )
            ->on( 'id' ),

        Constraint::unique( 'users_email_unique' )
            ->on( 'email' ),
    ] );

$sql = $intent->build();
$db->exec( $sql );
```

An auto-increment column is the primary key when the table declares none.

Constraint names are kept on every engine, with two exceptions:

- MySQL and MariaDB always call the primary key `PRIMARY`.
- On SQLite, an auto-increment column is written as `INTEGER PRIMARY KEY AUTOINCREMENT`, which cannot carry a name, so a primary key on it has none.

#### CREATE TABLE with Foreign Keys

```php
$intent = $builder->create_table( 'posts' )
    ->add_columns( [
        Column::make( 'id' )
            ->type( ColumnType::BIG_INT )
            ->auto_increment()
            ->unsigned()
            ->required(),

        Column::make( 'user_id' )
            ->type( ColumnType::BIG_INT )
            ->unsigned()
            ->required(),

        Column::make( 'title' )
            ->type( ColumnType::VARCHAR )
            ->size( 200 )
            ->required(),

        Column::make( 'body' )
            ->type( ColumnType::TEXT )
            ->required(),

        Column::make( 'created_at' )
            ->type( ColumnType::DATETIME )
            ->default( SQLExpression::currentTimestamp() ),
    ] )
    ->add_constraints( [
        Constraint::primary( 'posts_pk' )
            ->on( 'id' ),

        Constraint::foreign_key( 'posts_user_fk' )
            ->on( 'user_id' )
            ->references( 'users', 'id' )
            ->on_delete( 'CASCADE' )
            ->on_update( 'CASCADE' ),
    ] );

$sql = $intent->build();
$db->exec( $sql );
```

### Column Types

```php
use Callismart\DBPrism\Utils\ColumnType;

// Integer types
->type( ColumnType::SMALL_INT )
->type( ColumnType::INTEGER )
->type( ColumnType::BIG_INT )->unsigned()

// String types
->type( ColumnType::VARCHAR )->size( 100 )   // ColumnType::STRING is the same type
->type( ColumnType::CHAR )->size( 10 )
->type( ColumnType::TEXT )
->type( ColumnType::LONG_TEXT )

// Numeric types
->type( ColumnType::DECIMAL )->size( 10, 2 )  // precision, scale
->type( ColumnType::FLOAT )
->type( ColumnType::DOUBLE )

// Date/Time types
->type( ColumnType::DATE )
->type( ColumnType::TIME )
->type( ColumnType::DATETIME )
->type( ColumnType::TIMESTAMP )

// Other types
->type( ColumnType::BOOLEAN )
->type( ColumnType::JSON )
->type( ColumnType::UUID )
->type( ColumnType::BINARY )->size( 16 )  // fixed length
->type( ColumnType::BLOB )
```

Every column needs a type. Each type maps to the closest native type of the engine; SQLite stores them under its own type names (INTEGER, REAL, NUMERIC, TEXT, BLOB).

Sizes apply to `CHAR` (default 1), `VARCHAR` (default 255), `DECIMAL` (default 10, 2) and `BINARY` (default 1); other types ignore `size()`. The `BINARY` length is used on MySQL only: PostgreSQL stores it as `BYTEA` and SQLite as `BLOB`, neither of which takes a length.

### Column Modifiers

```php
Column::make( 'email' )
    ->type( ColumnType::VARCHAR )
    ->size( 100 )             // length; size( $precision, $scale ) for DECIMAL
    ->required()              // NOT NULL (columns are nullable otherwise)
    ->unsigned()              // UNSIGNED (numeric types, MySQL only; ignored elsewhere)
    ->auto_increment()        // AUTO_INCREMENT
    ->default( 'active' )     // DEFAULT value
    ->comment( 'Login email' ); // ignored on SQLite, which has no column comments
```

### Default Values

A default is either a literal value or an SQL expression. Say which it is with `DefaultColumnValue`:

```php
use Callismart\DBPrism\Utils\DefaultColumnValue;
use Callismart\DBPrism\Utils\SQLExpression;

->default( DefaultColumnValue::make( 'Pending (review)' ) )         // always a literal string
->default( DefaultColumnValue::expression( 'CURRENT_TIMESTAMP' ) )  // always written as SQL
->default( SQLExpression::currentTimestamp() )                      // the same, ready-made
->default( 0 )                                                      // numbers and booleans are literals
```

A plain string passed to `default()` is a literal unless it looks like a function call (`word(...)`), in which case it is written as SQL. Use `DefaultColumnValue::make()` for text that contains parentheses.

Expression defaults are written the way each engine accepts them. `CURRENT_TIMESTAMP`, `CURRENT_DATE` and `CURRENT_TIME` work everywhere. Functions are written in parentheses on MySQL (8.0.13+ or MariaDB 10.2+) and SQLite, so they need a function that engine has. SQLite checks the function only when a row uses the default, not when the table is created.

### ALTER TABLE

Modify existing tables using the `AlterTableIntent` class.

```php
$intent = $builder->alter_table( 'users' )
    ->add_column( Column::make( 'phone' )->type( ColumnType::VARCHAR )->size( 20 ) )
    ->modify_column( Column::make( 'name' )->type( ColumnType::VARCHAR )->size( 255 )->required() )
    ->drop_column( 'deprecated_field' );

$db->exec( $intent->build() );

$intent = ( new SQLBuilder( $db->get_driver() ) )->alter_table( 'users' )
    ->rename_column( 'old_name', 'new_name' );

$db->exec( $intent->build() );
```

How many changes one intent may hold depends on the engine:

- **MySQL / MariaDB:** any number.
- **PostgreSQL:** any number, except that a rename (of the table or a column) must be the only change in its intent.
- **SQLite:** one table or column change per intent (more throw), plus any number of index changes.

| Method | Change |
|---|---|
| `rename( $new_name )` | Rename the table |
| `add_column( Column $column )` | Add a column |
| `modify_column( Column $column )` | Set a column's whole definition |
| `rename_column( $from, $to )` | Rename a column |
| `drop_column( $name )` | Drop a column |
| `add_index( Constraint $index )` | Add an index: `Constraint::index( 'name' )` or `Constraint::unique( 'name' )`, with `->on( ...$columns )` |
| `drop_index( $name )` | Drop an index added with `add_index()` |
| `add_constraint( Constraint $constraint )` | Add a primary key, unique constraint or foreign key |
| `drop_constraint( $name )` | Drop a constraint added with `add_constraint()` |

On SQLite, `modify_column()`, `add_constraint()` and `drop_constraint()` throw, because SQLite's ALTER TABLE cannot make those changes. The other methods work there. The [migration operators](#migrations) make all of them on SQLite, by rebuilding the table.

### TRUNCATE TABLE

```php
$intent = $builder->truncate_table( 'logs' )
    ->restart_identity( true )
    ->cascade( false );

$sql = $intent->build();
$db->exec( $sql );
```

Pass both flags explicitly: `restart_identity()` defaults to `true`, and `cascade()` defaults to `false`.

What the flags do differs by engine:

| | `restart_identity( true )` | `cascade( true )` |
|---|---|---|
| PostgreSQL | `RESTART IDENTITY` (`false`: `CONTINUE IDENTITY`) | `CASCADE`: also empties the tables whose foreign keys reference this one (`false`: `RESTRICT`) |
| MySQL / MariaDB | Always restarts; the flag is ignored | Turns foreign key checks off for the statement and back on after it. Referencing rows are **not** removed; they are left pointing at rows that no longer exist |
| SQLite | Resets the AUTOINCREMENT counter (SQLite has no `TRUNCATE`; the rows are deleted) | The same as MySQL, with `PRAGMA foreign_keys`. Enforcement is switched on afterwards even if it was off before |

### DROP TABLE

```php
$intent = $builder->drop_table( 'old_table' )
    ->if_exists();

$sql = $intent->build();
$db->exec( $sql );
```

---

## Schema Inspection

The `Inspector` reads the schema of the database it is given.

```php
use Callismart\DBPrism\Inspection\Inspector;

$inspector = new Inspector( $db );
```

### Table Operations

```php
// List all tables
$tables = $inspector->get_all_tables();

// Check if table exists
if ( $inspector->table_exists( 'users' ) ) {
    // ...
}

// Get table metadata
$meta = $inspector->get_table_metadata( 'users' );
// MySQL: [ 'engine' => 'InnoDB', 'charset' => 'utf8mb4', 'collation' => '...', 'row_count' => 1250, 'comment' => '' ]
// Keys an engine has no value for are null (PostgreSQL has no engine or table collation).
```

### Column Operations

```php
// Get all column names
$columns = $inspector->get_columns( 'users' );

// Check if column exists
$inspector->column_exists( 'users', 'email' );

// Get column type, as the engine names it
$type = $inspector->get_column_type( 'users', 'id' );
// A BIG_INT column: 'bigint unsigned' on MySQL, 'int8' on PostgreSQL

// Get detailed column information
$details = $inspector->get_column_details( 'users' );
// [ 'email' => [ 'type' => 'varchar', 'nullable' => false, 'default' => null, 'auto_increment' => false ], ... ]

// Check if column is nullable
$inspector->is_column_nullable( 'users', 'email' );

// Get column default
$inspector->get_column_default( 'users', 'status' );
```

Types and defaults are reported as each engine stores them, so they are not the same across engines. A `VARCHAR` default of `active` comes back as `active` on MySQL, `'active'::character varying` on PostgreSQL, and `'active'` on SQLite. `CURRENT_TIMESTAMP` comes back as `CURRENT_TIMESTAMP` on all three.

### Index Operations

```php
// Get all indexes, except the primary key's
$indexes = $inspector->get_indexes( 'users' );
// [ 'idx_created_at' => [ 'columns' => [ 'created_at' ], 'unique' => false ], ... ]

// Check if index exists
$inspector->has_index( 'users', 'idx_created_at' );
```

### Primary Key Operations

```php
// Get primary key columns, in key order
$pk = $inspector->get_primary_key( 'users' );
// Returns: [ 'id' ] or null

// Get the primary key's constraint name
$name = $inspector->get_primary_key_name( 'users' );
// MySQL: 'PRIMARY'; PostgreSQL: e.g. 'users_pkey'; SQLite: the declared name, or null
```

### Foreign Key Operations

```php
// Get all foreign keys
$fks = $inspector->get_foreign_keys( 'orders' );
// [ 'fk_orders_user' => [ 'columns' => [ 'user_id' ], 'referenced_table' => 'users', 'referenced_columns' => [ 'id' ] ] ]

// Check if foreign key exists
$inspector->has_foreign_key( 'orders', 'fk_orders_user' );
```

### Constraint Operations

```php
// Get unique constraints
$unique = $inspector->get_unique_constraints( 'users' );
// [ 'users_email_unique' => [ 'email' ] ]

// Get check constraints
$checks = $inspector->get_check_constraints( 'products' );
// [ 'products_price_check' => [ 'definition' => '(price > 0)' ] ]
```

MySQL and MariaDB do not distinguish a unique index from a unique constraint, so `get_unique_constraints()` lists unique indexes there too. `get_check_constraints()` returns an empty array on SQLite.

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

// Everything at once, as a DatabaseInfoDTO
$info = $inspector->get_database_info();
```

---

## Migrations

A migration is a one-time change to a database. It is a class, and its fully qualified class name is its name. Migrations run in the order your application lists them, and each database records the ones applied to it in a ledger table, so none runs twice.

### Writing a Migration

Extend `AbstractMigration` and describe the change in `up()`. To make a migration reversible, implement `ReversibleMigrationInterface` and undo the change in `down()`; a migration without it cannot be reverted.

```php
use Callismart\DBPrism\Migrations\AbstractMigration;
use Callismart\DBPrism\Migrations\Contracts\ReversibleMigrationInterface;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;

final class CreateLicensesTable extends AbstractMigration implements ReversibleMigrationInterface {

    public function up() : void {
        $this->table( 'licenses' )->create( function ( $table ) {
            $table
                ->add_column( Column::make( 'id' )->type( ColumnType::BIG_INT )->auto_increment() )
                ->add_column( Column::make( 'license_key' )->type( ColumnType::VARCHAR )->size( 64 )->required() )
                ->add_column( Column::make( 'status' )->type( ColumnType::VARCHAR )->size( 20 ) );
        } );

        $this->index( 'licenses' )->create_unique( 'uq_licenses_key', 'license_key' );
    }

    public function down() : void {
        $this->table( 'licenses' )->drop();
    }

    public static function get_description() : string {
        return 'Create the licenses table.';
    }
}
```

Inside a migration:

| Helper | Gives |
|---|---|
| `$this->table( $table )` | Table operations |
| `$this->column( $table )` | Column operations |
| `$this->index( $table )` | Index operations |
| `$this->constraint( $table )` | Constraint operations |
| `$this->inspect()` | The `Inspector`, to decide what a data step must do |
| `$this->query()` | A `SQLBuilder` for the database being migrated, for data steps |
| `$this->exec( $sql )` | Raw SQL, when nothing else can express the change; it must suit every engine the migration runs on |
| `$this->database()` | The `Database` |

### Operators

Every operation follows three rules:

- **Check first:** it inspects the schema, and does nothing when the change is already there, so a migration can safely run again.
- **Throw on failure:** a failure throws `MigrationException`, so the migration stops and is not recorded as applied.
- **Intent only:** the SQL comes from the query builder, in the dialect of the database being migrated.

#### Tables

| Method | Behaviour |
|---|---|
| `create( fn( CreateTableIntent $table ) )` | Creates the table; does nothing when it exists (its definition is not compared) |
| `rename( $new_name )` | Does nothing when already renamed; throws when both names exist, or neither |
| `truncate( $restart = true, $cascade = false )` | Removes every row |
| `drop()` | Does nothing when the table does not exist |

#### Columns

| Method | Behaviour |
|---|---|
| `exists( $name )` | Whether the column exists |
| `add( Column $column )` | Does nothing when the column exists |
| `rename( $from, $to )` | Does nothing when already renamed; throws when both names exist, or neither |
| `modify( Column $column )` | Sets the column's whole definition; throws when the column does not exist |
| `drop( $name )` | Does nothing when the column does not exist |

`modify()` applies everything the `Column` describes. What it leaves unset takes the `Column` default: without `required()` the column becomes nullable, without `default()` it loses its default, and without `comment()` it loses its comment. An auto-increment column stays auto-increment, so its type can be widened (`INTEGER` to `BIG_INT`); a column cannot be made auto-increment by `modify()`.

#### Indexes

| Method | Behaviour |
|---|---|
| `exists( $name )` | Whether the index exists |
| `create( $name, $column, ...$more )` | Creates an index (several columns, in order, for a composite index); does nothing when the name exists |
| `create_unique( $name, $column, ...$more )` | The same, unique |
| `rename( $from, $to )` | Rebuilds the index under the new name from its columns and uniqueness |
| `drop( $name )` | Does nothing when the index does not exist |

#### Constraints

| Method | Behaviour |
|---|---|
| `add_primary_key( $column, ...$more )` | Does nothing when the primary key is already on these columns; throws when it is on others |
| `drop_primary_key()` | Does nothing when there is none |
| `add_unique( $name, $column, ...$more )` | Does nothing when the name exists |
| `drop_unique( $name )` | Does nothing when the name does not exist |
| `add_foreign_key( Constraint $foreign_key )` | Takes a named `Constraint::foreign_key( ... )`; does nothing when the name exists |
| `drop_foreign_key( $name )` | Does nothing when the name does not exist |

A unique constraint or foreign key is found by its name, so it needs one. Foreign key actions are written only when given: without `on_delete()` or `on_update()`, the engine's default applies.

```php
use Callismart\DBPrism\Utils\Constraint;

$this->constraint( 'licenses' )->add_foreign_key(
    Constraint::foreign_key( 'fk_licenses_owner' )
        ->on( 'owner_id' )
        ->references( 'users', 'id' )
        ->on_delete( 'cascade' )
);
```

### No Data Is Lost

A change never loses data on its way through. When a stored value cannot be carried into a changed column unchanged, the change is refused and the table is left as it was. That covers a string too long for the new length, text in a numeric column, a number out of range, and `NULL` in a `NOT NULL` column. MySQL runs the change in strict mode for this, whatever the session's own `sql_mode`.

To lose data on purpose, change the data first (for example, shorten the strings with an UPDATE), then the column. The one conversion that is not refused is rounding to a declared scale, such as `DECIMAL(10,4)` to `DECIMAL(10,2)`.

A change that would break existing rows is refused in the same way: a unique constraint over duplicate values, or a foreign key that existing rows break.

### SQLite

SQLite's ALTER TABLE cannot add or drop constraints or change a column. The operators make those changes by rebuilding the table, in one transaction: a new table is created from an edited copy of the old table's own definition, the rows are copied across, and the old table's indexes, triggers and AUTOINCREMENT counter are restored. Everything the change does not touch is kept as written, and a failure leaves the table as it was.

- A rebuild cannot run inside an open transaction while foreign keys are enforced (`PRAGMA foreign_keys = ON`).
- A column used by an index, a table constraint, a view or a trigger cannot be dropped; drop those first.
- Only constraints with a name can be dropped by name. Tables created before constraint names were kept on SQLite have unnamed constraints.

### Running Migrations

Register your migrations with a `Migrator`, as one ordered list of class names. The list is the whole definition: nothing is discovered by scanning directories or reading file names.

```php
use Callismart\DBPrism\Migrations\Migrator;
use Callismart\DBPrism\Exceptions\MigrationException;
use Callismart\DBPrism\Exceptions\MigrationLockedException;

$migrator = new Migrator( $db, [
    CreateLicensesTable::class,
    AddLicenseOwner::class,
] );

try {
    $applied = $migrator->migrate();   // class names applied by this run, in order
} catch ( MigrationLockedException $e ) {
    // Another run is in progress; nothing was changed.
} catch ( MigrationException $e ) {
    // A migration failed; the ones before it stay applied.
}

$migrator->pending();   // registered migrations not applied yet
$migrator->status();    // [ class => [ 'registered', 'applied', 'run', 'applied_at' ] ]
$migrator->revert();    // undo the most recent run; returns the class names reverted
```

| Rule | Meaning |
|---|---|
| New migrations go at the end of the list | A pending migration listed before an applied one is refused, and nothing runs. |
| A migration is recorded when its `up()` succeeds | The first failure stops the run. Running again resumes at the migration that failed, whose finished steps the operators skip. |
| A migration is not run inside a transaction | On MySQL every schema change commits on its own, and SQLite cannot rebuild a table inside one while foreign keys are enforced. Each operator step is safe to repeat; write raw `exec()` and data steps to be too. |
| Each `migrate()` or `revert()` is one run | `revert()` undoes the most recent run, newest migration first, and only when every migration in it is reversible and still registered. |
| One run at a time | A run holds a lock; a second run started meanwhile throws `MigrationLockedException` without changing anything. A lock older than the lock timeout is taken to be abandoned (its process died) and taken over. |
| `status()` and `pending()` change nothing | They throw `MigrationException` when the database cannot be read, rather than reporting nothing applied. |

The `Migrator` accepts three more arguments:

```php
$migrator = new Migrator(
    $db,
    $migrations,
    'myapp_',                                        // ledger tables: myapp_migrations, myapp_migrations_lock
    fn( string $class, Database $db ) => $container->make( $class ),  // builds migrations that do not extend AbstractMigration
    3600                                             // seconds after which a run's lock is taken to be abandoned
);
```

The prefix defaults to `dbprism_` and may contain letters, digits and underscores. Migration class names may be up to 191 characters long. Times in the ledger are UTC.

---

## Streaming Large Results

`stream()` reads a result one row at a time, so a large export never holds the whole result in memory. Every built-in adapter supports it.

```php
$stream = $db->stream( 'SELECT * FROM licenses WHERE status = ?', [ 'active' ] );

if ( false === $stream ) {
    throw new RuntimeException( $db->get_last_error() );
}

foreach ( $stream as $row ) {
    fputcsv( $handle, $row );
}

$stream->rows_read();   // rows read so far
```

The stream closes when it is read to the end, when `close()` is called, or when it is destroyed. It can also be read with `fetch()`, which returns the next row or `null`. A stream reads forward only: it cannot be rewound once it has ended. On MySQL, finish or close a stream before running another query on the same connection.

---

## Transactions

`transactional()` runs a callback inside a transaction. It commits when the callback returns, and rolls back and rethrows when it throws.

```php
try {
    $result = $db->transactional( function () use ( $db ) {
        $order_id = $db->insert( 'orders', [ 'total' => 99.99 ] );
        $db->insert( 'order_items', [ 'order_id' => $order_id, 'product_id' => 5 ] );
        return $order_id;
    } );
} catch ( \Throwable $e ) {
    error_log( "Transaction failed: " . $e->getMessage() );
}
```

`transactional()` calls may be nested: only the outermost one commits, and an exception at any depth rolls the whole transaction back. On MySQL, a schema change commits any open transaction.

---

## Best Practices

1. **Always Use Parameterized Queries**
   ```php
   // Good
   $user = $db->get_row( 'SELECT * FROM users WHERE id = ?', [ $id ] );

   // Bad: open to SQL injection
   $user = $db->get_row( "SELECT * FROM users WHERE id = {$id}" );
   ```

2. **Use Transactions for Related Operations**
   ```php
   $db->transactional( function () use ( $db, $order_data, $items ) {
       $order_id = $db->insert( 'orders', $order_data );
       foreach ( $items as $item ) {
           $db->insert( 'order_items', [ ...$item, 'order_id' => $order_id ] );
       }
   } );
   ```

3. **Change Schemas Through Migrations**

   Migrations record what each database has applied and make every step safe to repeat. A schema change made by hand is invisible to them.

4. **Check the Schema with the Inspector**
   ```php
   $inspector = new Inspector( $db );
   if ( $inspector->table_exists( 'users' ) &&
       $inspector->column_exists( 'users', 'email' ) ) {
       // Safe to query
   }
   ```

5. **Check Every Result**

   Query methods return `false`, `null` or an empty result on failure rather than throwing. Check the result, and read `$db->get_last_error()` for the reason. The exception is a value that cannot be bound (see [Bound Values](#bound-values)), which throws: it is a mistake in the code, not a database failure.

---

## Testing

Run the test suite:

```bash
composer test
```

Or one part of it:

```bash
composer test:intent       # query intents
composer test:renderer     # SQL rendering
composer test:utils        # utilities
composer test:connection   # live connection tests

composer test:dbconn:mysqli
composer test:dbconn:pdo-mysql
composer test:dbconn:pdo-pgsql
composer test:dbconn:pdo-sqlite
```

---

## Contributing

Contributions are welcome. Fork the repository, make your change on a branch, run `composer test`, and open a pull request.

---

## License

MIT. See [LICENSE](LICENSE).

---

## Author

**Callistus Nwachukwu**
- Email: admin@callismart.com.ng
- Website: https://callismart.com.ng

---

## Support

Report bugs and request features on the [issue tracker](https://github.com/CallismartLtd/dbprism/issues).