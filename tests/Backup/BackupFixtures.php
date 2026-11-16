<?php
/**
 * Shared fixtures for the backup and restore tests.
 */

namespace Callismart\DBPrism\Tests\Backup;

use Callismart\DBPrism\Adapters\MysqliAdapter;
use Callismart\DBPrism\Adapters\PdoAdapter;
use Callismart\DBPrism\Adapters\PostgresAdapter;
use Callismart\DBPrism\Adapters\SqliteAdapter;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\DBConfigDTO;
use Callismart\DBPrism\Inspection\Inspector;
use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\DefaultColumnValue;
use RuntimeException;

/**
 * A small shop schema (users, orders, tags) built with the query builder, so
 * it is the same on every engine, and the helpers to compare databases.
 *
 * users  1 -< orders 1 -< tags
 *
 * It covers: auto-increment keys, a composite primary key, a named unique
 * constraint, a plain index, foreign keys with and without ON DELETE, NOT
 * NULL, literal and CURRENT_TIMESTAMP defaults, booleans, exact decimals,
 * doubles, long text, binary data, Unicode, quotes and NULLs.
 */
trait BackupFixtures {

	/**
	 * The tables the fixture creates, parents first.
	 */
	protected static array $fixture_tables = [ 'users', 'orders', 'tags' ];

	/**
	 * Read an environment variable.
	 */
	protected static function env( string $key, mixed $default = null ) : mixed {
		if ( isset( $_ENV[ $key ] ) && '' !== $_ENV[ $key ] ) {
			return $_ENV[ $key ];
		}

		$value = getenv( $key );

		return false !== $value && '' !== $value ? $value : $default;
	}

	/**
	 * Connect to an engine through an adapter kind, or return null when it is unavailable.
	 *
	 * @param string      $engine      mysql, pgsql or sqlite.
	 * @param string      $kind        native or pdo.
	 * @param string|null $sqlite_path The SQLite file (sqlite only).
	 */
	protected static function connect_engine( string $engine, string $kind = 'native', ?string $sqlite_path = null ) : ?Database {
		$config = match ( $engine ) {
			'mysql'  => [
				'driver'   => 'mysql',
				'host'     => self::env( 'MYSQL_HOST', '127.0.0.1' ),
				'port'     => (int) self::env( 'MYSQL_PORT', 3306 ),
				'username' => self::env( 'MYSQL_USER', 'dbprism_test' ),
				'password' => self::env( 'MYSQL_PASS', 'dbprism_test' ),
				'dbname'   => self::env( 'MYSQL_DB', 'dbprism_test' ),
			],
			'pgsql'  => [
				'driver'   => 'pgsql',
				'host'     => self::env( 'PGSQL_HOST', '127.0.0.1' ),
				'port'     => (int) self::env( 'PGSQL_PORT', 5432 ),
				'username' => self::env( 'PGSQL_USER', 'dbprism_test' ),
				'password' => self::env( 'PGSQL_PASS', 'dbprism_test' ),
				'dbname'   => self::env( 'PGSQL_DB', 'dbprism_test' ),
			],
			'sqlite' => [
				'driver' => 'sqlite',
				'dbname' => $sqlite_path ?? ':memory:',
			],
		};

		$extension = match ( true ) {
			'pdo' === $kind && 'mysql' === $engine  => 'pdo_mysql',
			'pdo' === $kind && 'sqlite' === $engine => 'pdo_sqlite',
			'pgsql' === $engine                     => 'pdo_pgsql',
			'mysql' === $engine                     => 'mysqli',
			default                                 => 'sqlite3',
		};

		if ( ! extension_loaded( $extension ) ) {
			return null;
		}

		$dto     = new DBConfigDTO( $config );
		$adapter = match ( true ) {
			'pgsql' === $engine  => new PostgresAdapter( $dto ),
			'pdo' === $kind      => new PdoAdapter( $dto ),
			'mysql' === $engine  => new MysqliAdapter( $dto ),
			default              => new SqliteAdapter( $dto ),
		};

		try {
			if ( ! @$adapter->connect() || ! $adapter->is_connected() ) {
				return null;
			}
		} catch ( \Throwable $e ) {
			return null;
		}

		return new Database( $adapter );
	}

	/**
	 * Drop every table of the database.
	 */
	protected static function wipe( Database $db ) : void {
		$engine = $db->get_driver();
		$tables = ( new Inspector( $db ) )->get_all_tables();

		if ( 'mysql' === $engine ) {
			$db->exec( 'SET FOREIGN_KEY_CHECKS = 0' );
		}

		foreach ( $tables as $table ) {
			$db->exec( match ( $engine ) {
				'mysql' => "DROP TABLE IF EXISTS `{$table}`",
				'pgsql' => "DROP TABLE IF EXISTS \"{$table}\" CASCADE",
				default => "DROP TABLE IF EXISTS \"{$table}\"",
			} );
		}

		if ( 'mysql' === $engine ) {
			$db->exec( 'SET FOREIGN_KEY_CHECKS = 1' );
		}
	}

	/**
	 * Create the fixture schema and fill it.
	 *
	 * @param int $orders Number of orders.
	 * @return string The bytes stored in users.avatar for user 1.
	 */
	protected static function seed( Database $db, int $orders = 250 ) : string {
		$engine = $db->get_driver();

		$statements = [
			( new SQLBuilder( $engine ) )->create_table( 'users' )->add_columns( [
				Column::make( 'id' )->type( ColumnType::BIG_INT )->auto_increment()->required(),
				Column::make( 'email' )->type( ColumnType::STRING )->size( 190 )->required(),
				Column::make( 'name' )->type( ColumnType::STRING )->size( 100 ),
				Column::make( 'active' )->type( ColumnType::BOOLEAN )->required()->default( true ),
				Column::make( 'balance' )->type( ColumnType::DECIMAL )->size( 12, 4 )->default( 0 ),
				Column::make( 'score' )->type( ColumnType::DOUBLE ),
				Column::make( 'bio' )->type( ColumnType::LONG_TEXT ),
				Column::make( 'avatar' )->type( ColumnType::LONG_BLOB ),
				Column::make( 'created_at' )->type( ColumnType::DATETIME )->default( DefaultColumnValue::expression( 'CURRENT_TIMESTAMP' ) ),
			] )->add_constraints( [
				Constraint::primary()->on( 'id' ),
				Constraint::unique( 'users_email_uq' )->on( 'email' ),
			] )->query()->build(),

			( new SQLBuilder( $engine ) )->create_table( 'orders' )->add_columns( [
				Column::make( 'id' )->type( ColumnType::BIG_INT )->auto_increment()->required(),
				Column::make( 'user_id' )->type( ColumnType::BIG_INT )->required(),
				Column::make( 'total' )->type( ColumnType::DECIMAL )->size( 10, 2 )->required(),
				Column::make( 'status' )->type( ColumnType::STRING )->size( 20 )->default( 'new' ),
				Column::make( 'placed_on' )->type( ColumnType::DATE ),
			] )->add_constraints( [
				Constraint::primary()->on( 'id' ),
				Constraint::index( 'orders_status_idx' )->on( 'status' ),
				Constraint::foreign_key( 'orders_user_fk' )->on( 'user_id' )->references( 'users', 'id' )->on_delete( 'CASCADE' ),
			] )->query()->build(),

			( new SQLBuilder( $engine ) )->create_table( 'tags' )->add_columns( [
				Column::make( 'order_id' )->type( ColumnType::BIG_INT )->required(),
				Column::make( 'tag' )->type( ColumnType::STRING )->size( 50 )->required(),
			] )->add_constraints( [
				Constraint::primary()->on( 'order_id', 'tag' ),
				Constraint::foreign_key( 'tags_order_fk' )->on( 'order_id' )->references( 'orders', 'id' ),
			] )->query()->build(),
		];

		foreach ( $statements as $sql ) {
			if ( ! $db->exec( $sql ) ) {
				throw new RuntimeException( $db->get_last_error() . "\n" . $sql );
			}
		}

		$avatar = random_bytes( 256 ) . "\0\xff\x00end";
		$stream = fopen( 'php://memory', 'r+b' );
		fwrite( $stream, $avatar );
		rewind( $stream );

		self::must( $db, $db->insert( 'users', [
			'email'   => "o'neil@example.com",
			'name'    => 'Zoë Ünïcode ✓ "quoted"',
			'active'  => true,
			'balance' => '12345678.1234',
			'score'   => 0.1 + 0.2,
			'bio'     => str_repeat( 'Long text, ', 3000 ),
			'avatar'  => $stream,
		] ) );

		self::must( $db, $db->insert( 'users', [
			'email'   => 'nulls@example.com',
			'name'    => null,
			'active'  => false,
			'balance' => '-1.5000',
			'score'   => null,
			'bio'     => null,
			'avatar'  => null,
		] ) );

		$rows = [];

		for ( $i = 0; $i < $orders; $i++ ) {
			$rows[] = [
				'user_id'   => 1 + ( $i % 2 ),
				'total'     => sprintf( '%d.%02d', $i, $i % 100 ),
				'status'    => 0 === $i % 3 ? 'new' : 'paid',
				'placed_on' => sprintf( '2024-01-%02d', 1 + $i % 28 ),
			];
		}

		foreach ( array_chunk( $rows, 50 ) as $chunk ) {
			$intent = ( new SQLBuilder( $engine ) )->insert( 'orders' )->multi_values( $chunk );
			$db->execute( $intent->build(), $intent->get_bindings() );
			self::must( $db, null === $db->get_last_error() );
		}

		self::must( $db, false !== $db->insert( 'tags', [ 'order_id' => 1, 'tag' => 'gift' ] ) );
		self::must( $db, false !== $db->insert( 'tags', [ 'order_id' => 1, 'tag' => 'rush' ] ) );

		return $avatar;
	}

	/**
	 * Every fixture row, normalised so the same data compares equal on every engine.
	 *
	 * created_at is the time the source row was written: compared as present,
	 * since engines keep different fractions of a second.
	 *
	 * @return array<string, array<int, array<string, string|null>>>
	 */
	protected static function dump( Database $db ) : array {
		$quote = 'mysql' === $db->get_driver() ? '`' : '"';
		$dump  = [];

		foreach ( self::$fixture_tables as $table ) {
			$rows = $db->get_results( "SELECT * FROM {$quote}{$table}{$quote} ORDER BY 1, 2" );

			foreach ( $rows as $i => $row ) {
				foreach ( $row as $column => $value ) {
					$rows[ $i ][ $column ] = match ( true ) {
						null === $value         => null,
						'created_at' === $column => 'set',
						'active' === $column    => in_array( $value, [ true, 1, '1', 't', 'true' ], true ) ? '1' : '0',
						'score' === $column     => (string) (float) $value,
						'balance' === $column, 'total' === $column => self::decimal( (string) $value ),
						default                 => (string) $value,
					};
				}
			}

			$dump[ $table ] = $rows;
		}

		return $dump;
	}

	/**
	 * A decimal without trailing fractional zeros: 1.50, 1.5 and 1.5000 compare equal.
	 */
	protected static function decimal( string $value ) : string {
		return str_contains( $value, '.' ) ? rtrim( rtrim( $value, '0' ), '.' ) : $value;
	}

	/**
	 * The table records of an archive, without the engine they were read on.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	protected static function table_records( string $path ) : array {
		$records = [];

		foreach ( self::archive_lines( $path ) as $line ) {
			$record = json_decode( $line, true );

			if ( 'table' === $record['kind'] ) {
				unset( $record['engine'] );
				$records[ $record['name'] ] = $record;
			}
		}

		return $records;
	}

	/**
	 * The lines of an archive, decompressed.
	 *
	 * @return string[]
	 */
	protected static function archive_lines( string $path ) : array {
		return explode( "\n", rtrim( (string) file_get_contents( 'compress.zlib://' . $path ), "\n" ) );
	}

	/**
	 * Throw with the database's error when a fixture step fails.
	 */
	private static function must( Database $db, mixed $ok ) : void {
		if ( false === $ok ) {
			throw new RuntimeException( 'Seeding failed: ' . $db->get_last_error() );
		}
	}
}
