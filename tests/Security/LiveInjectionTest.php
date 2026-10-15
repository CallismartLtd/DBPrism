<?php
/**
 * Live injection tests.
 *
 * The other security tests inspect built SQL. These run hostile input
 * against a real database and check the data afterwards: nothing leaked,
 * nothing changed that should not have, every table still there.
 *
 * They use in-memory SQLite through both the native SQLite3 adapter and
 * PDO, so they need no server or credentials. The adapters build their
 * CRUD statements the same way on every engine.
 *
 * Fixture, rebuilt for each test:
 *   users   (1, alice, user), (2, bob, admin)
 *   secrets (1, TOKEN)
 *
 * @package Callismart\DBPrism\Tests\Security
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Security;

use Callismart\DBPrism\Adapters\PdoAdapter;
use Callismart\DBPrism\Adapters\SqliteAdapter;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\DBConfigDTO;
use Callismart\DBPrism\Query\SQLBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use Throwable;

final class LiveInjectionTest extends SecurityTestCase {

	/**
	 * A value only the secrets table holds.
	 */
	private const TOKEN = 'SECRET_9C1E_TOKEN';

	/**
	 * Adapters under test.
	 *
	 * @return array<string, array{string}>
	 */
	public static function adapters() : array {
		return [
			'sqlite3'    => [ 'sqlite3' ],
			'pdo_sqlite' => [ 'pdo_sqlite' ],
		];
	}

	/**
	 * Hostile values.
	 *
	 * @return array<string, string>
	 */
	private static function payloads() : array {
		return [
			'quote break'   => "x' OR '1'='1",
			'stacked'       => "x'; DROP TABLE secrets; --",
			'union'         => "x' UNION SELECT token, token, token FROM secrets --",
			'comment'       => "alice' --",
			'double quote'  => 'x" OR "1"="1',
			'backslash'     => "x\\' OR 1=1 --",
		];
	}

	/**
	 * Connect a fresh in-memory database and load the fixture.
	 *
	 * @param string $adapter Adapter key from adapters().
	 * @return Database
	 */
	private function connect( string $adapter ) : Database {
		$extension = 'sqlite3' === $adapter ? 'sqlite3' : 'pdo_sqlite';

		if ( ! extension_loaded( $extension ) ) {
			$this->markTestSkipped( "The {$extension} extension is not loaded." );
		}

		$config = new DBConfigDTO( [ 'driver' => 'sqlite', 'dbname' => ':memory:' ] );
		$db     = new Database( 'sqlite3' === $adapter ? new SqliteAdapter( $config ) : new PdoAdapter( $config ) );

		$this->assertTrue( $db->connect(), "{$adapter} must connect" );

		$db->exec(
			'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT);'
			. 'CREATE TABLE secrets (id INTEGER PRIMARY KEY, token TEXT);'
			. "INSERT INTO users (name, role) VALUES ('alice', 'user'), ('bob', 'admin');"
			. "INSERT INTO secrets (token) VALUES ('" . self::TOKEN . "');"
		);

		return $db;
	}

	/**
	 * Run a builder query and return its rows.
	 *
	 * @param Database $db     The database.
	 * @param object   $intent The query intent.
	 * @return array
	 */
	private function rows( Database $db, object $intent ) : array {
		return $db->get_results( $intent->build(), $intent->get_bindings() );
	}

	/**
	 * Assert the fixture is untouched.
	 *
	 * @param Database $db      The database.
	 * @param string   $context A label for the failure message.
	 * @return void
	 */
	private function assertFixtureIntact( Database $db, string $context ) : void {
		$this->assertSame(
			[
				[ 'id' => 1, 'name' => 'alice', 'role' => 'user' ],
				[ 'id' => 2, 'name' => 'bob', 'role' => 'admin' ],
			],
			$db->get_results( 'SELECT id, name, role FROM users WHERE id IN (1, 2) ORDER BY id' ),
			"{$context}: users must be unchanged"
		);
		$this->assertSame( self::TOKEN, $db->get_var( 'SELECT token FROM secrets WHERE id = 1' ), "{$context}: secrets must be unchanged" );
	}

	/**
	 * Assert no row carries the secret token.
	 *
	 * @param array  $rows    Result rows.
	 * @param string $context A label for the failure message.
	 * @return void
	 */
	private function assertNoLeak( array $rows, string $context ) : void {
		$this->assertStringNotContainsString( self::TOKEN, (string) json_encode( $rows ), "{$context}: the secret leaked into the result" );
	}

	/*
	|--------------------------
	| Builder queries
	|--------------------------
	*/

	#[DataProvider( 'adapters' )]
	public function test_hostile_where_values_match_nothing( string $adapter ) : void {
		$db = $this->connect( $adapter );

		foreach ( self::payloads() as $label => $payload ) {
			$rows = $this->rows( $db, ( new SQLBuilder( 'sqlite' ) )->select( 'id', 'name', 'role' )->from( 'users' )->where( 'name', '=', $payload ) );

			$this->assertSame( [], $rows, "{$label}: a hostile value must match only itself, {$adapter}" );
			$this->assertFixtureIntact( $db, "{$label}, {$adapter}" );
		}
	}

	#[DataProvider( 'adapters' )]
	public function test_hostile_values_round_trip_verbatim( string $adapter ) : void {
		$db = $this->connect( $adapter );

		foreach ( self::payloads() as $label => $payload ) {
			$insert = ( new SQLBuilder( 'sqlite' ) )->insert( 'users' )->values( [ 'name' => $payload, 'role' => 'user' ] );
			$db->execute( $insert->build(), $insert->get_bindings() );

			$select = ( new SQLBuilder( 'sqlite' ) )->select( 'name' )->from( 'users' )->where( 'name', '=', $payload );

			$this->assertSame( [ [ 'name' => $payload ] ], $this->rows( $db, $select ), "{$label}: stored and found as written, {$adapter}" );
		}

		$this->assertFixtureIntact( $db, $adapter );
	}

	#[DataProvider( 'adapters' )]
	public function test_hostile_update_and_delete_values_touch_nothing( string $adapter ) : void {
		$db = $this->connect( $adapter );

		foreach ( self::payloads() as $label => $payload ) {
			$update = ( new SQLBuilder( 'sqlite' ) )->update( 'users' )->set( [ 'role' => 'admin' ] )->where( 'name', '=', $payload );
			$delete = ( new SQLBuilder( 'sqlite' ) )->delete( 'users' )->where( 'name', '=', $payload );

			$this->assertSame( 0, $db->execute( $update->build(), $update->get_bindings() ), "{$label}: update, {$adapter}" );
			$this->assertSame( 0, $db->execute( $delete->build(), $delete->get_bindings() ), "{$label}: delete, {$adapter}" );
			$this->assertFixtureIntact( $db, "{$label}, {$adapter}" );
		}
	}

	#[DataProvider( 'adapters' )]
	public function test_search_wildcards_match_literally( string $adapter ) : void {
		$db = $this->connect( $adapter );
		$db->insert( 'users', [ 'name' => '100%_off', 'role' => 'user' ] );

		foreach ( [ 'where_contains' => '%', 'where_starts_with' => '1', 'where_ends_with' => '_off' ] as $method => $term ) {
			$rows = $this->rows( $db, ( new SQLBuilder( 'sqlite' ) )->select( 'name' )->from( 'users' )->{$method}( 'name', $term ) );

			$this->assertSame( [ [ 'name' => '100%_off' ] ], $rows, "{$method}( '{$term}' ), {$adapter}" );
		}

		$rows = $this->rows( $db, ( new SQLBuilder( 'sqlite' ) )->select( 'name' )->from( 'users' )->where_contains( 'name', '_' ) );
		$this->assertSame( [ [ 'name' => '100%_off' ] ], $rows, "'_' must not match any one character, {$adapter}" );
	}

	#[DataProvider( 'adapters' )]
	public function test_hostile_identifier_reads_no_other_table( string $adapter ) : void {
		$db = $this->connect( $adapter );

		foreach ( [ 'name" FROM secrets --', 'name", (SELECT token FROM secrets) AS "t', 'users"."name' ] as $column ) {
			try {
				$rows = $this->rows( $db, ( new SQLBuilder( 'sqlite' ) )->select( $column )->from( 'users' ) );
			} catch ( Throwable $e ) {
				$rows = [];
			}

			$this->assertNoLeak( $rows, "column {$column}, {$adapter}" );
		}

		$this->assertFixtureIntact( $db, $adapter );
	}

	#[DataProvider( 'adapters' )]
	public function test_rejected_keywords_run_nothing( string $adapter ) : void {
		$db = $this->connect( $adapter );

		$attempts = [
			'operator'  => fn() => ( new SQLBuilder( 'sqlite' ) )->select( 'name' )->from( 'users' )->where( 'id', '= 0 UNION SELECT token FROM secrets --', 1 ),
			'direction' => fn() => ( new SQLBuilder( 'sqlite' ) )->select( 'name' )->from( 'users' )->order_by( 'id', 'ASC; DROP TABLE secrets' ),
			'connector' => fn() => ( new SQLBuilder( 'sqlite' ) )->select( 'name' )->from( 'users' )->where( 'id', '=', 0 )->where( 'id', '=', 0, 'OR 1 = 1 OR' ),
		];

		foreach ( $attempts as $label => $make ) {
			try {
				$rows = $this->rows( $db, $make() );
			} catch ( Throwable $e ) {
				$rows = [];
			}

			$this->assertNoLeak( $rows, "{$label}, {$adapter}" );
			$this->assertSame( [], $rows, "{$label}: hostile keyword must not return rows, {$adapter}" );
		}

		$this->assertFixtureIntact( $db, $adapter );
	}

	/*
	|---------------------------------------
	| Adapter insert(), update(), delete()
	|---------------------------------------
	|
	| These take column names as array keys. Applications commonly pass
	| request data straight through ($db->update( 'users', $_POST, ... )),
	| so a key is as hostile as a value. A key that is not a plain column
	| name must be rejected or quoted, never written into the SQL as given.
	*/

	#[DataProvider( 'adapters' )]
	public function test_update_key_cannot_set_other_columns( string $adapter ) : void {
		$db = $this->connect( $adapter );

		try {
			$db->update( 'users', [ "name = 'alice', role" => 'admin' ], [ 'id' => 1 ] );
		} catch ( Throwable $e ) {
			// Rejected: a valid defence.
		}

		$this->assertFixtureIntact( $db, "update data key, {$adapter}" );
	}

	#[DataProvider( 'adapters' )]
	public function test_update_where_key_cannot_widen_the_match( string $adapter ) : void {
		$db = $this->connect( $adapter );

		try {
			$db->update( 'users', [ 'role' => 'admin' ], [ 'id = 1 OR 1' => 1 ] );
		} catch ( Throwable $e ) {
			// Rejected: a valid defence.
		}

		$this->assertSame( 'user', $db->get_var( 'SELECT role FROM users WHERE id = 1' ), "alice must not become admin, {$adapter}" );
	}

	#[DataProvider( 'adapters' )]
	public function test_delete_where_key_cannot_widen_the_match( string $adapter ) : void {
		$db = $this->connect( $adapter );

		try {
			$db->delete( 'users', [ 'id = 0 OR 1' => 1 ] );
		} catch ( Throwable $e ) {
			// Rejected: a valid defence.
		}

		$this->assertFixtureIntact( $db, "delete where key, {$adapter}" );
	}

	#[DataProvider( 'adapters' )]
	public function test_insert_key_cannot_write_other_columns( string $adapter ) : void {
		$db = $this->connect( $adapter );

		try {
			$db->insert( 'users', [ 'name, role) VALUES (\'mallory\', \'admin\'); --' => 'x' ] );
		} catch ( Throwable $e ) {
			// Rejected: a valid defence.
		}

		$this->assertSame( 0, (int) $db->get_var( "SELECT COUNT(*) FROM users WHERE role = 'admin' AND name = 'mallory'" ), "no admin row, {$adapter}" );
		$this->assertFixtureIntact( $db, "insert key, {$adapter}" );
	}

	#[DataProvider( 'adapters' )]
	public function test_table_name_cannot_target_other_tables( string $adapter ) : void {
		$db = $this->connect( $adapter );

		try {
			$db->delete( 'secrets; DELETE FROM users', [ 'id' => 1 ] );
		} catch ( Throwable $e ) {
			// Rejected: a valid defence.
		}
		try {
			$db->update( 'users SET role = \'admin\' --', [ 'name' => 'x' ], [ 'id' => 1 ] );
		} catch ( Throwable $e ) {
			// Rejected: a valid defence.
		}

		$this->assertFixtureIntact( $db, "table name, {$adapter}" );
	}
}