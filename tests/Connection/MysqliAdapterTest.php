<?php

namespace Callismart\DBPrism\Tests\Adapters;

use PHPUnit\Framework\TestCase;
use Callismart\DBPrism\Adapters\MysqliAdapter;
use Callismart\DBPrism\DBConfigDTO;

class MysqliAdapterTest extends TestCase {

    protected ?MysqliAdapter $adapter = null;

    /**
     * Retrieve environment variable prioritizing $_ENV before getenv().
     *
     * @param string $key
     * @param mixed  $default
     * @return mixed
     */
    protected function get_env( string $key, mixed $default = null ): mixed {
        if ( isset( $_ENV[ $key ] ) && $_ENV[ $key ] !== '' ) {
            return $_ENV[ $key ];
        }

        $value = getenv( $key );

        return false !== $value && '' !== $value ? $value : $default;
    }

    protected function setUp(): void {
        parent::setUp();

        $config = new DBConfigDTO([
            'driver'   => 'mysql',
            'host'     => $this->get_env( 'MYSQL_HOST', '127.0.0.1' ),
            'username' => $this->get_env( 'MYSQL_USER', 'root' ),
            'password' => $this->get_env( 'MYSQL_PASS', '' ),
            'dbname'   => $this->get_env( 'MYSQL_NAME', 'dbprism_test' ),
            'port'     => (int) $this->get_env( 'MYSQL_PORT', 3306 ),
            'charset'  => 'utf8mb4',
        ]);

        $this->adapter = new MysqliAdapter( $config);

        if (!$this->adapter->is_connected()) {
            $this->markTestSkipped('MySQL connection failed. Skipping integration tests.');
        }

        // Clean table setup
        $this->adapter->exec('DROP TABLE IF EXISTS test_users');
        $this->adapter->exec('
            CREATE TABLE test_users (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                email VARCHAR(100) NOT NULL UNIQUE,
                balance DECIMAL(10,2) DEFAULT 0.00
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    protected function tearDown(): void {
        if ($this->adapter && $this->adapter->is_connected()) {
            $this->adapter->exec('DROP TABLE IF EXISTS test_users');
            $this->adapter->close();
        }

        parent::tearDown();
    }

    public function test_connection_and_driver_info(): void {
        $this->assertTrue($this->adapter->is_connected());
        $this->assertSame('mysql', $this->adapter->get_driver());
        $this->assertInstanceOf(DBConfigDTO::class, $this->adapter->get_config());
    }

    public function test_failed_connection_handles_error_gracefully(): void {
        $bad_config = new DBConfigDTO([
            'driver'   => 'mysql',
            'host'     => '127.0.0.1',
            'username' => 'invalid_user_xyz',
            'password' => 'wrong_password',
            'dbname'   => 'invalid_db',
            'port'     => 3306,
        ]);

        $bad_adapter = new MysqliAdapter($bad_config);

        $this->assertFalse($bad_adapter->is_connected());
        $this->assertNotNull($bad_adapter->get_last_error());
        $this->assertStringContainsString('Access denied', $bad_adapter->get_last_error());
    }

    public function test_insert_and_get_insert_id(): void {
        $id = $this->adapter->insert('test_users', [
            'name'    => 'Callistus',
            'email'   => 'callistus@example.com',
            'balance' => 150.50
        ]);

        $this->assertIsInt($id);
        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, $this->adapter->get_insert_id());
    }

    public function test_get_row(): void {
        $this->adapter->insert('test_users', [
            'name'  => 'John Doe',
            'email' => 'john@example.com'
        ]);

        $row = $this->adapter->get_row('SELECT * FROM test_users WHERE email = ?', ['john@example.com']);

        $this->assertIsArray($row);
        $this->assertSame('John Doe', $row['name']);
        $this->assertSame('john@example.com', $row['email']);
    }

    public function test_get_row_returns_null_when_not_found(): void {
        $row = $this->adapter->get_row('SELECT * FROM test_users WHERE email = ?', ['nonexistent@example.com']);

        $this->assertNull($row);
        $this->assertNull($this->adapter->get_last_error());
    }

    public function test_get_results(): void {
        $this->adapter->insert('test_users', ['name' => 'User One', 'email' => 'user1@example.com']);
        $this->adapter->insert('test_users', ['name' => 'User Two', 'email' => 'user2@example.com']);

        $results = $this->adapter->get_results('SELECT * FROM test_users ORDER BY id ASC');

        $this->assertCount(2, $results);
        $this->assertSame('User One', $results[0]['name']);
        $this->assertSame('User Two', $results[1]['name']);
    }

    public function test_get_var(): void {
        $this->adapter->insert('test_users', ['name' => 'Alice', 'email' => 'alice@example.com']);

        $var = $this->adapter->get_var('SELECT name FROM test_users WHERE email = ?', ['alice@example.com']);

        $this->assertSame('Alice', $var);
    }

    public function test_get_col(): void {
        $this->adapter->insert('test_users', ['name' => 'User A', 'email' => 'a@example.com']);
        $this->adapter->insert('test_users', ['name' => 'User B', 'email' => 'b@example.com']);

        $emails = $this->adapter->get_col('SELECT email FROM test_users ORDER BY id ASC');

        $this->assertSame(['a@example.com', 'b@example.com'], $emails);
    }

    public function test_update(): void {
        $id = $this->adapter->insert('test_users', ['name' => 'Original Name', 'email' => 'update@example.com']);

        $affected = $this->adapter->update(
            'test_users',
            ['name' => 'Updated Name'],
            ['id'   => $id]
        );

        $this->assertSame(1, $affected);

        $updated_name = $this->adapter->get_var('SELECT name FROM test_users WHERE id = ?', [$id]);
        $this->assertSame('Updated Name', $updated_name);
    }

    public function test_delete(): void {
        $id = $this->adapter->insert('test_users', ['name' => 'To Delete', 'email' => 'delete@example.com']);

        $affected = $this->adapter->delete('test_users', ['id' => $id]);

        $this->assertSame(1, $affected);

        $count = $this->adapter->get_var('SELECT COUNT(*) FROM test_users WHERE id = ?', [$id]);
        $this->assertEquals(0, $count);
    }

    public function test_query_error_captures_last_error_without_throwing(): void {
        $result = $this->adapter->get_row('SELECT * FROM non_existent_table');

        $this->assertNull($result);
        $this->assertNotNull($this->adapter->get_last_error());
        $this->assertStringContainsString("Table '", $this->adapter->get_last_error());
    }

    public function test_execute_invalid_sql(): void {
        $affected = $this->adapter->execute('INVALID SQL QUERY');

        $this->assertSame(0, $affected);
        $this->assertNotNull($this->adapter->get_last_error());
    }

    public function test_transaction_commit(): void {
        $this->adapter->begin_transaction();
        $this->adapter->insert('test_users', ['name' => 'Tx User', 'email' => 'tx@example.com']);
        $this->adapter->commit();

        $count = $this->adapter->get_var('SELECT COUNT(*) FROM test_users WHERE email = ?', ['tx@example.com']);
        $this->assertEquals(1, $count);
    }

    public function test_transaction_rollback(): void {
        $this->adapter->begin_transaction();
        $this->adapter->insert('test_users', ['name' => 'Rollback User', 'email' => 'rollback@example.com']);
        $this->adapter->rollback();

        $count = $this->adapter->get_var('SELECT COUNT(*) FROM test_users WHERE email = ?', ['rollback@example.com']);
        $this->assertEquals(0, $count);
    }

    public function test_exec_multi_query(): void {
        $sql = "
            INSERT INTO test_users (name, email) VALUES ('Multi 1', 'm1@example.com');
            INSERT INTO test_users (name, email) VALUES ('Multi 2', 'm2@example.com');
        ";

        $success = $this->adapter->exec($sql);

        $this->assertTrue($success);
        $count = $this->adapter->get_var('SELECT COUNT(*) FROM test_users');
        $this->assertEquals(2, $count);
    }
}