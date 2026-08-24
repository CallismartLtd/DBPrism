<?php

namespace Callismart\DBPrism\Tests\Connection;

use PHPUnit\Framework\TestCase;
use Callismart\DBPrism\Adapters\PdoAdapter;
use Callismart\DBPrism\DBConfigDTO;

class PdoPostgresTest extends TestCase {

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
            'driver'   => 'pgsql',
            'host'     => $this->get_env( 'PGSQL_HOST', '127.0.0.1' ),
            'username' => $this->get_env( 'PGSQL_USER', 'dbprism_test' ),
            'password' => $this->get_env( 'PGSQL_PASS', 'dbprism_test' ),
            'dbname'   => $this->get_env( 'PGSQL_DB', 'dbprism_test' ),
            'port'     => (string) $this->get_env( 'PGSQL_PORT', 5432 ),
            'charset'  => 'utf8',
        ] );

        $this->adapter = new PdoAdapter( $config );

        if ( ! $this->adapter->is_connected() ) {
            $this->markTestSkipped( 'PDO PostgreSQL connection failed. Skipping test.' );
        }

        $this->adapter->exec( 'DROP TABLE IF EXISTS test_users;' );
        $this->adapter->exec( '
            CREATE TABLE test_users (
                id SERIAL PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                balance NUMERIC(10,2) DEFAULT 0.00
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

    public function test_pdo_pgsql_connection_and_driver(): void {
        $this->assertTrue( $this->adapter->is_connected() );
        $this->assertSame( 'pgsql', $this->adapter->get_driver() );
    }

    public function test_pdo_pgsql_crud_operations(): void {
        $id = $this->adapter->insert( 'test_users', [
            'name'  => 'PDO Postgres User',
            'email' => 'pdopgsql@example.com',
        ] );

        $this->assertIsInt( $id );
        $this->assertGreaterThan( 0, $id );

        $row = $this->adapter->get_row( 'SELECT * FROM test_users WHERE id = ?', [ $id ] );
        $this->assertSame( 'PDO Postgres User', $row['name'] );
    }
}