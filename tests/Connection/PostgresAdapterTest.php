<?php

namespace Callismart\DBPrism\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use Callismart\DBPrism\Adapters\PdoAdapter;
use Callismart\DBPrism\DBConfigDTO;

class PostgresAdapterTest extends TestCase {

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
            $this->markTestSkipped( 'PostgreSQL connection failed. Skipping integration tests.' );
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

    public function test_pgsql_connection_and_driver_info(): void {
        $this->assertTrue( $this->adapter->is_connected() );
        $this->assertSame( 'pgsql', $this->adapter->get_driver() );
        $this->assertInstanceOf( DBConfigDTO::class, $this->adapter->get_config() );
    }

    public function test_pgsql_insert_and_get_insert_id(): void {
        $id = $this->adapter->insert( 'test_users', [
            'name'    => 'Callistus',
            'email'   => 'callistus_pg@example.com',
            'balance' => 250.75,
        ] );

        $this->assertIsInt( $id );
        $this->assertGreaterThan( 0, $id );
        $this->assertSame( $id, $this->adapter->get_insert_id() );
    }

    public function test_pgsql_get_row(): void {
        $this->adapter->insert( 'test_users', [
            'name'  => 'Postgres User',
            'email' => 'pg@example.com',
        ] );

        $row = $this->adapter->get_row( 'SELECT * FROM test_users WHERE email = ?', [ 'pg@example.com' ] );

        $this->assertIsArray( $row );
        $this->assertSame( 'Postgres User', $row['name'] );
    }
}