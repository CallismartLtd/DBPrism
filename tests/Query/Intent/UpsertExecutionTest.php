<?php
/**
 * Upsert execution tests.
 *
 * Runs SQLBuilder::upsert() against an in-memory SQLite database, through
 * the native SQLite3 adapter and PDO, and checks the rows afterwards:
 * - A new key is inserted; an existing key updates its row.
 * - update_columns() leaves the other columns as they were.
 * - A conflict on the key alone leaves the row unchanged.
 * - One multi-row upsert inserts and updates in the same statement.
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Query\Intent;

use Callismart\DBPrism\Adapters\PdoAdapter;
use Callismart\DBPrism\Adapters\SqliteAdapter;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\DBConfigDTO;
use Callismart\DBPrism\Query\QueryIntents\PersistenceIntent;
use Callismart\DBPrism\Query\SQLBuilder;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class UpsertExecutionTest extends TestCase {

	/**
	 * Adapters under test.
	 *
	 * @return array<string, array{string}>
	 */
	public static function adapters(): array {
		return [
			'sqlite3'    => [ 'sqlite3' ],
			'pdo_sqlite' => [ 'pdo_sqlite' ],
		];
	}

	/**
	 * Connect a fresh in-memory database with a licenses table and one row.
	 *
	 * @param string $adapter Adapter key from adapters().
	 * @return Database
	 */
	private function connect( string $adapter ): Database {
		if ( ! extension_loaded( $adapter ) ) {
			$this->markTestSkipped( "The {$adapter} extension is not loaded." );
		}

		$config = new DBConfigDTO( [ 'driver' => 'sqlite', 'dbname' => ':memory:' ] );
		$db     = new Database( 'sqlite3' === $adapter ? new SqliteAdapter( $config ) : new PdoAdapter( $config ) );

		$this->assertTrue( $db->connect() );

		$db->exec(
			'CREATE TABLE licenses (id INTEGER PRIMARY KEY, license_key TEXT NOT NULL UNIQUE, status TEXT, seats INTEGER);'
			. "INSERT INTO licenses (license_key, status, seats) VALUES ('KEY-1', 'active', 1);"
		);

		return $db;
	}

	/**
	 * Run an upsert.
	 *
	 * @param Database          $db     The database.
	 * @param PersistenceIntent $intent The upsert.
	 * @return void
	 */
	private function run_upsert( Database $db, PersistenceIntent $intent ): void {
		$db->execute( $intent->build(), $intent->get_bindings() );

		$this->assertNull( $db->get_last_error() );
	}

	/**
	 * Every row, ordered by key.
	 *
	 * @param Database $db The database.
	 * @return array
	 */
	private function licenses( Database $db ): array {
		return $db->get_results( 'SELECT id, license_key, status, seats FROM licenses ORDER BY license_key' );
	}

	#[DataProvider( 'adapters' )]
	public function test_new_key_is_inserted( string $adapter ): void {
		$db = $this->connect( $adapter );

		$this->run_upsert( $db, ( new SQLBuilder( 'sqlite' ) )->upsert( 'licenses' )->values( [ 'license_key' => 'KEY-2', 'status' => 'trial', 'seats' => 2 ] )->on_conflict( 'license_key' ) );

		$this->assertSame(
			[
				[ 'id' => 1, 'license_key' => 'KEY-1', 'status' => 'active', 'seats' => 1 ],
				[ 'id' => 2, 'license_key' => 'KEY-2', 'status' => 'trial', 'seats' => 2 ],
			],
			$this->licenses( $db )
		);
	}

	#[DataProvider( 'adapters' )]
	public function test_existing_key_updates_its_row( string $adapter ): void {
		$db = $this->connect( $adapter );

		$this->run_upsert( $db, ( new SQLBuilder( 'sqlite' ) )->upsert( 'licenses' )->values( [ 'license_key' => 'KEY-1', 'status' => 'expired', 'seats' => 5 ] )->on_conflict( 'license_key' ) );

		$this->assertSame(
			[ [ 'id' => 1, 'license_key' => 'KEY-1', 'status' => 'expired', 'seats' => 5 ] ],
			$this->licenses( $db ),
			'the row keeps its id: it was updated, not replaced'
		);
	}

	#[DataProvider( 'adapters' )]
	public function test_update_columns_leaves_other_columns( string $adapter ): void {
		$db = $this->connect( $adapter );

		$this->run_upsert(
			$db,
			( new SQLBuilder( 'sqlite' ) )->upsert( 'licenses' )->values( [ 'license_key' => 'KEY-1', 'status' => 'expired', 'seats' => 5 ] )->on_conflict( 'license_key' )->update_columns( 'seats' )
		);

		$this->assertSame( [ [ 'id' => 1, 'license_key' => 'KEY-1', 'status' => 'active', 'seats' => 5 ] ], $this->licenses( $db ) );
	}

	#[DataProvider( 'adapters' )]
	public function test_conflict_on_key_only_leaves_the_row( string $adapter ): void {
		$db = $this->connect( $adapter );

		$this->run_upsert( $db, ( new SQLBuilder( 'sqlite' ) )->upsert( 'licenses' )->values( [ 'license_key' => 'KEY-1' ] )->on_conflict( 'license_key' ) );

		$this->assertSame( [ [ 'id' => 1, 'license_key' => 'KEY-1', 'status' => 'active', 'seats' => 1 ] ], $this->licenses( $db ) );
	}

	#[DataProvider( 'adapters' )]
	public function test_multi_row_upsert_inserts_and_updates( string $adapter ): void {
		$db = $this->connect( $adapter );

		$this->run_upsert(
			$db,
			( new SQLBuilder( 'sqlite' ) )
				->upsert( 'licenses' )
				->multi_values( [
					[ 'license_key' => 'KEY-1', 'status' => 'expired', 'seats' => 3 ],
					[ 'seats' => 4, 'status' => 'trial', 'license_key' => 'KEY-2' ],
				] )
				->on_conflict( 'license_key' )
		);

		$this->assertSame(
			[
				[ 'id' => 1, 'license_key' => 'KEY-1', 'status' => 'expired', 'seats' => 3 ],
				[ 'id' => 2, 'license_key' => 'KEY-2', 'status' => 'trial', 'seats' => 4 ],
			],
			$this->licenses( $db )
		);
	}
}