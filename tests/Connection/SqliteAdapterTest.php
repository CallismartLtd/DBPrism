<?php
/**
 * SqliteAdapter Test Suite
 *
 * @package Callismart\DBPrism\Tests
 */

namespace Callismart\DBPrism\Tests\Connection;

use PHPUnit\Framework\TestCase;
use Callismart\DBPrism\Adapters\SqliteAdapter;
use Callismart\DBPrism\DBConfigDTO;

class SqliteAdapterTest extends TestCase {

    private SqliteAdapter $adapter;
    private string $temp_db_dir;

    protected function setUp(): void {
        parent::setUp();

        // Setup temporary directory for disk-based test databases
        $this->temp_db_dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'dbprism_tests_' . uniqid();
        mkdir( $this->temp_db_dir, 0777, true );

        // Default to in-memory database for fast testing
        $config = new DBConfigDTO();
        $config->dbname = ':memory:';

        $this->adapter = new SqliteAdapter( $config );

        // Set up test table
        $this->adapter->exec( "
            CREATE TABLE IF NOT EXISTS users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT UNIQUE,
                balance REAL DEFAULT 0.0,
                active INTEGER DEFAULT 1,
                bio BLOB
            )
        " );
    }

    protected function tearDown(): void {
        $this->adapter->close();

        // Clean up temporary files
        if ( is_dir( $this->temp_db_dir ) ) {
            $files = glob( $this->temp_db_dir . '/*' );
            foreach ( $files as $file ) {
                if ( is_file( $file ) ) {
                    unlink( $file );
                }
            }
            rmdir( $this->temp_db_dir );
        }

        parent::tearDown();
    }

    /*
    |--------------------------------------------------------------------------
    | Connection & Path Building Tests
    |--------------------------------------------------------------------------
    */

    public function test_connects_successfully_to_in_memory_database(): void {
        $this->assertTrue( $this->adapter->is_connected() );
        $this->assertEquals( 'sqlite', $this->adapter->get_driver() );
        $this->assertNull( $this->adapter->get_last_error() );
    }

    public function test_connects_successfully_to_file_based_database(): void {
        $config = new DBConfigDTO();
        $config->path   = $this->temp_db_dir;
        $config->dbname = 'test_db';

        $adapter  = new SqliteAdapter( $config );
        $expected = $this->temp_db_dir . DIRECTORY_SEPARATOR . 'test_db.db';

        $this->assertTrue( $adapter->is_connected() );
        $this->assertFileExists( $expected );

        $adapter->close();
    }

    public function test_path_resolution_preserves_custom_file_extension(): void {
        $config = new DBConfigDTO();
        $config->path   = $this->temp_db_dir;
        $config->dbname = 'app.sqlite3';

        $adapter  = new SqliteAdapter( $config );
        $expected = $this->temp_db_dir . DIRECTORY_SEPARATOR . 'app.sqlite3';

        $this->assertTrue( $adapter->is_connected() );
        $this->assertFileExists( $expected );

        $adapter->close();
    }

    public function test_fails_gracefully_when_dbname_is_missing(): void {
        $config = new DBConfigDTO(); // empty dbname

        $adapter = new SqliteAdapter( $config );

        $this->assertFalse( $adapter->is_connected() );
        $this->assertNotNull( $adapter->get_last_error() );
        $this->assertStringContainsString( 'database name or path was not specified', $adapter->get_last_error() );
    }

    public function test_close_and_reconnect(): void {
        $this->assertTrue( $this->adapter->is_connected() );
        $this->adapter->close();
        $this->assertFalse( $this->adapter->is_connected() );

        // Operations should automatically trigger auto-reconnect
        $var = $this->adapter->get_var( 'SELECT 1' );
        $this->assertEquals( 1, $var );
        $this->assertTrue( $this->adapter->is_connected() );
    }

    /*
    |--------------------------------------------------------------------------
    | CRUD & Query Operations
    |--------------------------------------------------------------------------
    */

    public function test_insert_and_get_insert_id(): void {
        $id = $this->adapter->insert( 'users', [
            'name'    => 'John Doe',
            'email'   => 'john@example.com',
            'balance' => 150.50,
            'active'  => 1,
        ] );

        $this->assertIsInt( $id );
        $this->assertGreaterThan( 0, $id );
        $this->assertEquals( $id, $this->adapter->get_insert_id() );
    }

    public function test_get_row_retrieves_associative_array(): void {
        $id = $this->adapter->insert( 'users', [
            'name'  => 'Jane Doe',
            'email' => 'jane@example.com',
        ] );

        $row = $this->adapter->get_row( 'SELECT * FROM users WHERE id = ?', [ $id ] );

        $this->assertIsArray( $row );
        $this->assertEquals( 'Jane Doe', $row['name'] );
        $this->assertEquals( 'jane@example.com', $row['email'] );
    }

    public function test_get_row_returns_null_when_not_found(): void {
        $row = $this->adapter->get_row( 'SELECT * FROM users WHERE id = ?', [ 9999 ] );
        $this->assertNull( $row );
    }

    public function test_get_results_retrieves_multiple_rows(): void {
        $this->adapter->insert( 'users', [ 'name' => 'User One', 'email' => 'one@example.com' ] );
        $this->adapter->insert( 'users', [ 'name' => 'User Two', 'email' => 'two@example.com' ] );

        $results = $this->adapter->get_results( 'SELECT * FROM users ORDER BY id ASC' );

        $this->assertCount( 2, $results );
        $this->assertEquals( 'User One', $results[0]['name'] );
        $this->assertEquals( 'User Two', $results[1]['name'] );
    }

    public function test_get_var_retrieves_single_scalar_value(): void {
        $this->adapter->insert( 'users', [ 'name' => 'Alice', 'email' => 'alice@example.com' ] );

        $count = $this->adapter->get_var( 'SELECT COUNT(*) FROM users' );
        $name  = $this->adapter->get_var( 'SELECT name FROM users WHERE email = ?', [ 'alice@example.com' ] );

        $this->assertEquals( 1, $count );
        $this->assertEquals( 'Alice', $name );
    }

    public function test_get_col_retrieves_single_column_array(): void {
        $this->adapter->insert( 'users', [ 'name' => 'Alpha', 'email' => 'a@example.com' ] );
        $this->adapter->insert( 'users', [ 'name' => 'Beta', 'email' => 'b@example.com' ] );

        $names = $this->adapter->get_col( 'SELECT name FROM users ORDER BY name ASC' );

        $this->assertEquals( [ 'Alpha', 'Beta' ], $names );
    }

    public function test_update_returns_affected_rows(): void {
        $id = $this->adapter->insert( 'users', [ 'name' => 'Old Name', 'email' => 'update@example.com' ] );

        $affected = $this->adapter->update(
            'users',
            [ 'name' => 'New Name' ],
            [ 'id'   => $id ]
        );

        $this->assertEquals( 1, $affected );
        $this->assertEquals( 'New Name', $this->adapter->get_var( 'SELECT name FROM users WHERE id = ?', [ $id ] ) );
    }

    public function test_delete_returns_affected_rows(): void {
        $id = $this->adapter->insert( 'users', [ 'name' => 'To Delete', 'email' => 'delete@example.com' ] );

        $affected = $this->adapter->delete( 'users', [ 'id' => $id ] );

        $this->assertEquals( 1, $affected );
        $this->assertNull( $this->adapter->get_row( 'SELECT * FROM users WHERE id = ?', [ $id ] ) );
    }

    public function test_execute_returns_affected_rows_for_prepared_statements(): void {
        $this->adapter->insert( 'users', [ 'name' => 'User A', 'email' => 'a@test.com', 'active' => 1 ] );
        $this->adapter->insert( 'users', [ 'name' => 'User B', 'email' => 'b@test.com', 'active' => 1 ] );

        $affected = $this->adapter->execute( 'UPDATE users SET active = ? WHERE active = ?', [ 0, 1 ] );

        $this->assertEquals( 2, $affected );
    }

    /*
    |--------------------------------------------------------------------------
    | Data Types & Binding Tests
    |--------------------------------------------------------------------------
    */

    public function test_handles_null_float_int_and_bool_types(): void {
        $id = $this->adapter->insert( 'users', [
            'name'    => 'Data Types',
            'email'   => null,
            'balance' => 99.99,
            'active'  => true,
        ] );

        $row = $this->adapter->get_row( 'SELECT * FROM users WHERE id = ?', [ $id ] );

        $this->assertNull( $row['email'] );
        $this->assertEquals( 99.99, (float) $row['balance'] );
        $this->assertEquals( 1, (int) $row['active'] );
    }

    /*
    |--------------------------------------------------------------------------
    | Transaction Tests
    |--------------------------------------------------------------------------
    */

    public function test_transaction_commit(): void {
        $this->adapter->begin_transaction();

        $this->adapter->insert( 'users', [ 'name' => 'Tx Commit', 'email' => 'commit@example.com' ] );

        $this->adapter->commit();

        $this->assertNotNull( $this->adapter->get_row( 'SELECT * FROM users WHERE email = ?', [ 'commit@example.com' ] ) );
    }

    public function test_transaction_rollback(): void {
        $this->adapter->begin_transaction();

        $this->adapter->insert( 'users', [ 'name' => 'Tx Rollback', 'email' => 'rollback@example.com' ] );

        $this->adapter->rollback();

        $this->assertNull( $this->adapter->get_row( 'SELECT * FROM users WHERE email = ?', [ 'rollback@example.com' ] ) );
    }

    /*
    |--------------------------------------------------------------------------
    | Error Handling Tests
    |--------------------------------------------------------------------------
    */

    public function test_captures_sql_syntax_errors(): void {
        $result = @$this->adapter->get_row( 'SELECT * FROM non_existent_table' );

        $this->assertNull( $result );
        $this->assertNotNull( $this->adapter->get_last_error() );
        $this->assertStringContainsString( 'no such table', $this->adapter->get_last_error() );
    }

    public function test_captures_constraint_violations(): void {
        $this->adapter->insert( 'users', [ 'name' => 'First', 'email' => 'unique@example.com' ] );

        // Duplicate email trigger
        $result = @$this->adapter->insert( 'users', [ 'name' => 'Second', 'email' => 'unique@example.com' ] );

        $this->assertFalse( $result );
        $this->assertNotNull( $this->adapter->get_last_error() );
        $this->assertStringContainsString( 'UNIQUE constraint failed', $this->adapter->get_last_error() );
    }
}