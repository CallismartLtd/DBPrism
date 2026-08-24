<?php

namespace Callismart\DBPrism\Tests\Connection;

use PHPUnit\Framework\TestCase;
use Callismart\DBPrism\Adapters\PdoAdapter;
use Callismart\DBPrism\DBConfigDTO;

class PdoMysqlTest extends TestCase {

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
            'driver'   => 'mysql',
            'host'     => $this->get_env( 'MYSQL_HOST', '127.0.0.1' ),
            'username' => $this->get_env( 'MYSQL_USER', 'dbprism_test' ),
            'password' => $this->get_env( 'MYSQL_PASS', 'dbprism_test' ),
            'dbname'   => $this->get_env( 'MYSQL_DB', 'dbprism_test' ),
            'port'     => (string) $this->get_env( 'MYSQL_PORT', 3306 ),
            'charset'  => 'utf8mb4',
        ] );

        $this->adapter = new PdoAdapter( $config );

        if ( ! $this->adapter->is_connected() ) {
            $this->markTestSkipped( 'PDO MySQL connection failed. Skipping test.' );
        }

        $this->adapter->exec( 'DROP TABLE IF EXISTS test_users;' );
        $this->adapter->exec( '
            CREATE TABLE test_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                balance DECIMAL(10,2) DEFAULT 0.00
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ' );
    }

    protected function tearDown(): void {
        if ( $this->adapter && $this->adapter->is_connected() ) {
            $this->adapter->exec( 'DROP TABLE IF EXISTS test_users;' );
            $this->adapter->close();
        }

        parent::tearDown();
    }

    public function test_pdo_mysql_connection_and_driver(): void {
        $this->assertTrue( $this->adapter->is_connected() );
        $this->assertSame( 'mysql', $this->adapter->get_driver() );
    }

    public function test_pdo_mysql_crud_operations(): void {
        $id = $this->adapter->insert( 'test_users', [
            'name'  => 'PDO MySQL User',
            'email' => 'pdomysql@example.com',
        ] );

        $this->assertIsInt( $id );
        $this->assertGreaterThan( 0, $id );

        $row = $this->adapter->get_row( 'SELECT * FROM test_users WHERE id = ?', [ $id ] );
        $this->assertSame( 'PDO MySQL User', $row['name'] );
    }
}