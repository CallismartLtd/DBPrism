<?php
/**
 * Upsert rendering tests.
 *
 * Covers SQLBuilder::upsert() on every engine, without a database:
 * - The SQL each engine gets (ON CONFLICT or ON DUPLICATE KEY UPDATE).
 * - Bindings: the inserted values only, in placeholder order.
 * - update_columns(), the default update set, and "do nothing" on conflict.
 * - Multi-row upserts, and DefaultColumnValue written into the SQL.
 * - The exception each invalid upsert throws.
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Query\Renderer;

use Callismart\DBPrism\Exceptions\PersistenceDataException;
use Callismart\DBPrism\Exceptions\QueryException;
use Callismart\DBPrism\Exceptions\UpsertException;
use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Utils\DefaultColumnValue;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Throwable;

final class UpsertRendererTest extends TestCase {

	/**
	 * Engines.
	 *
	 * @return array<string, array{string}>
	 */
	public static function engines(): array {
		return [
			'mysql'  => [ 'mysql' ],
			'pgsql'  => [ 'pgsql' ],
			'sqlite' => [ 'sqlite' ],
		];
	}

	/**
	 * Engines, with the conflict clause each renders for the key "k" and update column "s".
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function engine_clauses(): array {
		return [
			'mysql'  => [ 'mysql', ' ON DUPLICATE KEY UPDATE `s` = VALUES(`s`);' ],
			'pgsql'  => [ 'pgsql', ' ON CONFLICT ("k") DO UPDATE SET "s" = EXCLUDED."s";' ],
			'sqlite' => [ 'sqlite', ' ON CONFLICT ("k") DO UPDATE SET "s" = EXCLUDED."s";' ],
		];
	}

	/**
	 * Quote a name for an engine.
	 *
	 * @param string $engine Engine name.
	 * @param string $name   Identifier.
	 * @return string
	 */
	private static function q( string $engine, string $name ): string {
		return 'mysql' === $engine ? "`{$name}`" : "\"{$name}\"";
	}

	/**
	 * Assert a callable throws the given exception class.
	 *
	 * @param string   $class  Expected exception class.
	 * @param callable $action Code that must throw.
	 * @param string   $label  Failure label.
	 * @return void
	 */
	private function assertThrowsException( string $class, callable $action, string $label ): void {
		try {
			$action();
		} catch ( Throwable $e ) {
			$this->assertInstanceOf( $class, $e, "{$label}: threw " . get_class( $e ) . ': ' . $e->getMessage() );
			return;
		}

		$this->fail( "{$label}: expected {$class}, nothing was thrown" );
	}

	/*
	|-----------
	| Rendering
	|-----------
	*/

	#[DataProvider( 'engine_clauses' )]
	public function test_single_row_upsert_rendering( string $engine, string $clause ): void {
		$sql = ( new SQLBuilder( $engine ) )
			->upsert( 'licenses' )
			->values( [ 'k' => 'KEY-1', 's' => 'active' ] )
			->on_conflict( 'k' )
			->build();

		$expected = sprintf(
			'INSERT INTO %s (%s, %s) VALUES (?, ?)',
			self::q( $engine, 'licenses' ),
			self::q( $engine, 'k' ),
			self::q( $engine, 's' )
		) . $clause;

		$this->assertSame( $expected, $sql );
	}

	#[DataProvider( 'engines' )]
	public function test_bindings_are_the_inserted_values_only( string $engine ): void {
		$intent = ( new SQLBuilder( $engine ) )
			->upsert( 'licenses' )
			->values( [ 'k' => 'KEY-1', 's' => 'active', 'seats' => 5 ] )
			->on_conflict( 'k' );

		$this->assertSame( [ 'KEY-1', 'active', 5 ], $intent->get_bindings() );
		$this->assertSame( 3, substr_count( $intent->build(), '?' ) );
	}

	#[DataProvider( 'engines' )]
	public function test_default_update_set_is_every_column_outside_the_key( string $engine ): void {
		$sql = ( new SQLBuilder( $engine ) )
			->upsert( 'licenses' )
			->values( [ 'k' => 'KEY-1', 's' => 'active', 'seats' => 5 ] )
			->on_conflict( 'k' )
			->build();

		foreach ( [ 's', 'seats' ] as $column ) {
			$this->assertStringContainsString( self::q( $engine, $column ) . ' = ', $sql );
		}

		$this->assertStringNotContainsString( self::q( $engine, 'k' ) . ' = ', $sql );
	}

	#[DataProvider( 'engines' )]
	public function test_update_columns_limits_the_update( string $engine ): void {
		$sql = ( new SQLBuilder( $engine ) )
			->upsert( 'licenses' )
			->values( [ 'k' => 'KEY-1', 's' => 'active', 'seats' => 5 ] )
			->on_conflict( 'k' )
			->update_columns( 'seats' )
			->build();

		$this->assertStringContainsString( self::q( $engine, 'seats' ) . ' = ', $sql );
		$this->assertStringNotContainsString( self::q( $engine, 's' ) . ' = ', $sql );
	}

	#[DataProvider( 'engines' )]
	public function test_conflict_on_key_only_leaves_the_row( string $engine ): void {
		$sql = ( new SQLBuilder( $engine ) )
			->upsert( 'licenses' )
			->values( [ 'k' => 'KEY-1' ] )
			->on_conflict( 'k' )
			->build();

		$expected = 'mysql' === $engine
			? ' ON DUPLICATE KEY UPDATE `k` = `k`;'
			: ' ON CONFLICT ("k") DO NOTHING;';

		$this->assertStringEndsWith( $expected, $sql );
	}

	#[DataProvider( 'engines' )]
	public function test_composite_conflict_key( string $engine ): void {
		$sql = ( new SQLBuilder( $engine ) )
			->upsert( 'activations' )
			->values( [ 'license_id' => 1, 'domain' => 'example.com', 'seen' => 3 ] )
			->on_conflict( 'license_id', 'domain' )
			->build();

		if ( 'mysql' === $engine ) {
			$this->assertStringEndsWith( ' ON DUPLICATE KEY UPDATE `seen` = VALUES(`seen`);', $sql );
			return;
		}

		$this->assertStringEndsWith( ' ON CONFLICT ("license_id", "domain") DO UPDATE SET "seen" = EXCLUDED."seen";', $sql );
	}

	#[DataProvider( 'engine_clauses' )]
	public function test_multi_row_upsert( string $engine, string $clause ): void {
		$intent = ( new SQLBuilder( $engine ) )
			->upsert( 'licenses' )
			->multi_values( [
				[ 'k' => 'KEY-1', 's' => 'active' ],
				[ 's' => 'expired', 'k' => 'KEY-2' ],
			] )
			->on_conflict( 'k' );

		$this->assertStringEndsWith( 'VALUES (?, ?), (?, ?)' . $clause, $intent->build() );
		$this->assertSame( [ 'KEY-1', 'active', 'KEY-2', 'expired' ], $intent->get_bindings(), 'the second row is put in the first row\'s column order' );
	}

	#[DataProvider( 'engines' )]
	public function test_default_column_value_is_written_not_bound( string $engine ): void {
		$intent = ( new SQLBuilder( $engine ) )
			->upsert( 'licenses' )
			->values( [
				'k'          => 'KEY-1',
				's'          => DefaultColumnValue::make( 'active' ),
				'updated_at' => DefaultColumnValue::expression( 'CURRENT_TIMESTAMP' ),
			] )
			->on_conflict( 'k' );

		$this->assertStringContainsString( "VALUES (?, 'active', CURRENT_TIMESTAMP)", $intent->build() );
		$this->assertSame( [ 'KEY-1' ], $intent->get_bindings() );
	}

	/*
	|------------------------------------
	| Fixes in code shared with upsert
	|------------------------------------
	*/

	#[DataProvider( 'engines' )]
	public function test_default_column_value_in_update_keeps_bindings_aligned( string $engine ): void {
		$intent = ( new SQLBuilder( $engine ) )
			->update( 'licenses' )
			->set( [ 's' => 'active', 'updated_at' => DefaultColumnValue::expression( 'CURRENT_TIMESTAMP' ) ] )
			->where( 'id', '=', 7 );

		$this->assertSame(
			sprintf( 'UPDATE %s SET %s = ?, %s = CURRENT_TIMESTAMP WHERE %s = ?;', self::q( $engine, 'licenses' ), self::q( $engine, 's' ), self::q( $engine, 'updated_at' ), self::q( $engine, 'id' ) ),
			$intent->build()
		);
		$this->assertSame( [ 'active', 7 ], $intent->get_bindings() );
	}

	#[DataProvider( 'engines' )]
	public function test_multi_row_insert_aligns_columns( string $engine ): void {
		$intent = ( new SQLBuilder( $engine ) )
			->insert( 'users' )
			->multi_values( [
				[ 'name' => 'alice', 'role' => 'user' ],
				[ 'role' => 'admin', 'name' => 'bob' ],
			] );

		$this->assertSame( [ 'alice', 'user', 'bob', 'admin' ], $intent->get_bindings() );
	}

	/*
	|------------
	| Exceptions
	|------------
	*/

	#[DataProvider( 'engines' )]
	public function test_invalid_upserts_throw_upsert_exception( string $engine ): void {
		$upsert = fn() => ( new SQLBuilder( $engine ) )->upsert( 'licenses' )->values( [ 'k' => 'KEY-1', 's' => 'active' ] );

		$cases = [
			'no conflict key'          => fn() => $upsert()->build(),
			'conflict key not inserted' => fn() => $upsert()->on_conflict( 'id' )->build(),
			'update column not inserted' => fn() => $upsert()->on_conflict( 'k' )->update_columns( 'seats' )->build(),
			'WHERE condition'          => fn() => $upsert()->on_conflict( 'k' )->where( 'id', '=', 1 )->build(),
			'empty on_conflict()'      => fn() => $upsert()->on_conflict(),
			'empty update_columns()'   => fn() => $upsert()->update_columns(),
			'set_case()'               => fn() => $upsert()->set_case( 's', fn( $case ) => $case ),
		];

		foreach ( $cases as $label => $case ) {
			$this->assertThrowsException( UpsertException::class, $case, "{$label}, {$engine}" );
		}
	}

	#[DataProvider( 'engines' )]
	public function test_unwritable_data_throws_persistence_data_exception( string $engine ): void {
		$cases = [
			'rows with different columns' => fn() => ( new SQLBuilder( $engine ) )->upsert( 'licenses' )->multi_values( [ [ 'k' => 'A' ], [ 'k' => 'B', 's' => 'x' ] ] ),
			'no rows'                     => fn() => ( new SQLBuilder( $engine ) )->upsert( 'licenses' )->multi_values( [] ),
			'list instead of row'         => fn() => ( new SQLBuilder( $engine ) )->upsert( 'licenses' )->multi_values( [ [ 'A', 'B' ] ] ),
			'set_case() on insert'        => fn() => ( new SQLBuilder( $engine ) )
				->insert( 'licenses' )
				->values( [ 'k' => 'A' ] )
				->set_case( 's', fn( $case ) => $case->when( fn( $c ) => $c->where( 'k', '=', 'A' ), 'x' ) )
				->build(),
		];

		foreach ( $cases as $label => $case ) {
			$this->assertThrowsException( PersistenceDataException::class, $case, "{$label}, {$engine}" );
		}
	}

	public function test_exceptions_share_query_exception_and_invalid_argument_exception(): void {
		foreach ( [ UpsertException::class, PersistenceDataException::class ] as $class ) {
			$exception = new $class( 'test' );

			$this->assertInstanceOf( QueryException::class, $exception );
			$this->assertInstanceOf( InvalidArgumentException::class, $exception, 'existing catch blocks must keep working' );
		}
	}
}