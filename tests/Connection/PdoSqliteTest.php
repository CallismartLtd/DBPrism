<?php

namespace Callismart\DBPrism\Tests\Connection;

use PHPUnit\Framework\TestCase;
use Callismart\DBPrism\Adapters\PdoAdapter;
use Callismart\DBPrism\DBConfigDTO;

class PdoSqliteTest extends TestCase {

    protected ?PdoAdapter $adapter = null;

    protected function get_env( string $key, mixed $default = null ): mixed {
        if ( isset( $_ENV[ $key ] ) && '' !== $_ENV[ $key ] ) {
            return $_ENV[ $key ];
        }

        $value = getenv( $key );

        return false !== $value && '' !== $value ? $value : $default;
    }

    protected function setUp(): void {
        parent::setUp();

        $config = new DBConfigDTO( [
            'driver' => 'sqlite',
            'dbname' => $this->get_env( 'SQLITE_PATH', ':memory:' ),
        ] );

        $this->adapter = new PdoAdapter( $config );

        if ( ! $this->adapter->is_connected() ) {
            $this->markTestSkipped( 'PDO SQLite connection failed. Skipping test.' );
        }

        $this->adapter->exec( '
            CREATE TABLE test_users (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                email TEXT NOT NULL UNIQUE,
                balance REAL DEFAULT 0.00
            );
        ' );
    }

    protected function tearDown(): void {
        if ( $this->adapter && $this->adapter->is_connected() ) {
            $this->adapter->exec( 'DROP TABLE IF EXISTS test_users;' );
            $this->adapter->close();
        }

        parent::tearDown();
    }

    public function test_pdo_sqlite_connection_and_driver(): void {
        $this->assertTrue( $this->adapter->is_connected() );
        $this->assertSame( 'sqlite', $this->adapter->get_driver() );
    }

    public function test_pdo_sqlite_crud_operations(): void {
        $id = $this->adapter->insert( 'test_users', [
            'name'  => 'PDO SQLite User',
            'email' => 'pdosqlite@example.com',
        ] );

        $this->assertIsInt( $id );
        $this->assertGreaterThan( 0, $id );

        $row = $this->adapter->get_row( 'SELECT * FROM test_users WHERE id = ?', [ $id ] );
        $this->assertSame( 'PDO SQLite User', $row['name'] );
    }
}