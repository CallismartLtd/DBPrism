<?php
/**
 * Input guard tests.
 *
 * Some input cannot be bound or quoted, so DBPrism checks it against a closed
 * list or a pattern and throws InvalidArgumentException before any SQL runs:
 *
 *   - Comparison operators, sort directions and logical connectors.
 *   - Values the adapters cannot bind (arrays, objects, INF, NAN).
 *   - The migration table prefix, which becomes part of table names.
 *   - DSN settings, which PDO cannot escape.
 *
 * These tests require that exact exception. The injection tests accept any
 * throw as a defence; here a TypeError or a warning would be a failure,
 * because the caller is promised an InvalidArgumentException it can catch.
 *
 * @package Callismart\DBPrism\Tests\Security
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Security;

use Callismart\DBPrism\Adapters\NullDBAdapter;
use Callismart\DBPrism\Adapters\SqliteAdapter;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\DBConfigDTO;
use Callismart\DBPrism\Migrations\Migrator;
use Callismart\DBPrism\Query\Renderers\AbstractQueryRenderer;
use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Utils\DsnBuilder;
use Callismart\DBPrism\Utils\ParamType;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use stdClass;

final class InputGuardTest extends SecurityTestCase {

	/*
	|----------------------------------------
	| Operators, directions and connectors
	|----------------------------------------
	*/

	#[DataProvider( 'engines' )]
	public function test_unsupported_operators_are_rejected( string $engine ) : void {
		foreach ( [ '', ';', '==', '<=>', 'IS', 'IS NOT', 'IN', '= ANY', 'REGEXP', 'LIKE BINARY', '= 1 OR 1 =' ] as $operator ) {
			$this->assertThrows(
				InvalidArgumentException::class,
				fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->where( 'a', $operator, 1 )->build(),
				"operator \"{$operator}\", {$engine}"
			);
		}
	}

	#[DataProvider( 'engines' )]
	public function test_every_listed_operator_is_accepted( string $engine ) : void {
		foreach ( AbstractQueryRenderer::COMPARISON_OPERATORS as $operator ) {
			$sql = ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->where( 'a', strtolower( $operator ), 1 )->build();

			$this->assertStringContainsString( " {$operator} ?", $sql, "operator {$operator}, {$engine}" );
		}
	}

	#[DataProvider( 'engines' )]
	public function test_unsupported_directions_are_rejected( string $engine ) : void {
		foreach ( [ '', 'ASC;', 'ASC, id', 'DESC NULLS FIRST', 'RANDOM()' ] as $direction ) {
			$this->assertThrows(
				InvalidArgumentException::class,
				fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->order_by( 'a', $direction )->build(),
				"direction \"{$direction}\", {$engine}"
			);
		}
	}

	#[DataProvider( 'engines' )]
	public function test_unsupported_connectors_are_rejected( string $engine ) : void {
		foreach ( [ '', 'XOR', 'AND NOT', 'OR 1 = 1 OR' ] as $connector ) {
			$this->assertThrows(
				InvalidArgumentException::class,
				fn() => ( new SQLBuilder( $engine ) )
					->select( 'id' )
					->from( 'users' )
					->where( 'a', '=', 1 )
					->where( 'b', '=', 2, $connector )
					->build(),
				"connector \"{$connector}\", {$engine}"
			);
		}
	}

	/*
	|----------------------
	| Values that cannot bind
	|----------------------
	*/

	public function test_param_type_rejects_unbindable_values() : void {
		$unbindable = [
			'array'     => [ 1 ],
			'object'    => new stdClass(),
			'closure'   => fn() => 1,
			'INF'       => INF,
			'-INF'      => -INF,
			'NAN'       => NAN,
		];

		foreach ( $unbindable as $label => $value ) {
			$this->assertThrows( InvalidArgumentException::class, fn() => ParamType::of( $value ), "ParamType::of( {$label} )" );
		}
	}

	public function test_param_type_accepts_bindable_values() : void {
		$stream = fopen( 'php://memory', 'r+' );

		$this->assertSame( ParamType::NULL, ParamType::of( null ) );
		$this->assertSame( ParamType::BOOL, ParamType::of( false ) );
		$this->assertSame( ParamType::INT, ParamType::of( 0 ) );
		$this->assertSame( ParamType::FLOAT, ParamType::of( 1.5 ) );
		$this->assertSame( ParamType::STRING, ParamType::of( "x' OR '1'='1" ) );
		$this->assertSame( ParamType::BINARY, ParamType::of( $stream ) );

		fclose( $stream );
	}

	public function test_adapter_rejects_unbindable_value_before_running_sql() : void {
		if ( ! class_exists( \SQLite3::class ) ) {
			$this->markTestSkipped( 'The sqlite3 extension is not loaded.' );
		}

		$adapter = new SqliteAdapter( new DBConfigDTO( [ 'driver' => 'sqlite', 'dbname' => ':memory:' ] ) );
		$adapter->connect();
		$adapter->exec( 'CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT)' );

		// The builder records an array value as given; the adapter must refuse
		// it before the statement reaches the database.
		$intent = ( new SQLBuilder( 'sqlite' ) )->insert( 'users' )->values( [ 'name' => [ 'x' ] ] );

		$this->assertThrows(
			InvalidArgumentException::class,
			fn() => $adapter->execute( $intent->build(), $intent->get_bindings() ),
			'array value through the adapter'
		);
		$this->assertSame( 0, (int) $adapter->get_var( 'SELECT COUNT(*) FROM users' ), 'nothing must have been written' );
	}

	/*
	|----------------------
	| Migration table prefix
	|----------------------
	*/

	public function test_hostile_migration_prefixes_are_rejected() : void {
		$db = new Database( new NullDBAdapter() );

		foreach ( [ "x'; DROP TABLE users; --", 'a b', 'a-b', 'a.b', 'a`b', 'a"b', 'a;b', "a\0b", 'é' ] as $prefix ) {
			$this->assertThrows(
				InvalidArgumentException::class,
				fn() => new Migrator( $db, [], $prefix ),
				'prefix ' . json_encode( $prefix )
			);
		}
	}

	public function test_valid_migration_prefixes_are_accepted() : void {
		$db = new Database( new NullDBAdapter() );

		foreach ( [ '', 'dbprism_', 'App2_', 'smliser_' ] as $prefix ) {
			$this->assertInstanceOf( Migrator::class, new Migrator( $db, [], $prefix ), "prefix \"{$prefix}\"" );
		}
	}

	/*
	|--------------
	| DSN settings
	|--------------
	*/

	/**
	 * Settings that reach the DSN, per driver.
	 *
	 * @return array<string, array{string, string}>
	 */
	public static function dsn_settings() : array {
		return [
			'mysql host'    => [ 'mysql', 'host' ],
			'mysql dbname'  => [ 'mysql', 'dbname' ],
			'mysql charset' => [ 'mysql', 'charset' ],
			'mysql socket'  => [ 'mysql', 'socket' ],
			'pgsql host'    => [ 'pgsql', 'host' ],
			'pgsql dbname'  => [ 'pgsql', 'dbname' ],
			'pgsql charset' => [ 'pgsql', 'charset' ],
			'pgsql sslmode' => [ 'pgsql', 'sslmode' ],
		];
	}

	#[DataProvider( 'dsn_settings' )]
	public function test_dsn_setting_cannot_add_parameters( string $driver, string $setting ) : void {
		// PDO cannot escape a DSN value, so ";" would start a new parameter.
		$this->assertThrows(
			InvalidArgumentException::class,
			fn() => DsnBuilder::build( new DBConfigDTO( [ 'driver' => $driver, 'dbname' => 'app', $setting => 'x;host=attacker.example' ] ) ),
			"{$driver} {$setting}"
		);
	}

	public function test_dsn_port_must_be_a_number() : void {
		// Rejected by DBConfigDTO itself, or at the latest by DsnBuilder.
		foreach ( [ 'mysql', 'pgsql' ] as $driver ) {
			$this->assertThrows(
				InvalidArgumentException::class,
				fn() => DsnBuilder::build( new DBConfigDTO( [ 'driver' => $driver, 'dbname' => 'app', 'port' => '3306;host=attacker.example' ] ) ),
				"{$driver} port"
			);
		}
	}

	public function test_dsn_driver_comes_from_a_closed_list() : void {
		$this->assertThrows(
			InvalidArgumentException::class,
			fn() => DsnBuilder::build( new DBConfigDTO( [ 'driver' => 'mysql;host=attacker.example', 'dbname' => 'app' ] ) ),
			'driver'
		);
	}
}