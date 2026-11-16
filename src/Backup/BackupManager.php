<?php
/**
 * Backup manager class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup;

use Callismart\DBPrism\Adapters\Contracts\StreamingAdapterInterface;
use Callismart\DBPrism\Backup\Archive\ArchiveReader;
use Callismart\DBPrism\Backup\Archive\ArchiveWriter;
use Callismart\DBPrism\Backup\Dialects\AbstractBackupDialect;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Inspection\Inspector;
use Callismart\DBPrism\Utils\Column;
use Throwable;

/**
 * Back up a database to a portable archive, and restore an archive into any
 * supported database: MySQL, MariaDB, PostgreSQL or SQLite, whichever engine
 * the backup came from.
 *
 *   $backups = new BackupManager( $db );
 *
 *   $report = $backups->backup( '/backups/app.dbprism.gz' );
 *   $report = $backups->restore( '/backups/app.dbprism.gz', [ 'existing' => 'replace' ] );
 *
 * Consistency:
 *
 *  - A backup reads every table from one snapshot, so it holds the database
 *    as it was at a single moment even while other connections write.
 *  - A restore is all or nothing. On PostgreSQL and SQLite it is one
 *    transaction. On MySQL, whose CREATE TABLE commits on its own, tables
 *    are built and loaded under staging names and moved into place with one
 *    atomic RENAME TABLE once every row is in.
 *  - The archive ends with a checksum of its contents. A truncated or altered
 *    archive is detected before the restore commits, and nothing is changed.
 *
 * What is backed up: tables (columns with their types, sizes, nullability,
 * defaults, auto-increment and comments), primary keys, unique constraints,
 * indexes, foreign keys with their actions, and every row. CHECK constraints
 * and engine-specific defaults are restored on the engine they came from.
 * Views, triggers, stored routines, sequences outside SERIAL columns, and
 * users are not backed up. Anything that cannot be carried over exactly is
 * reported as a warning, never dropped silently.
 *
 * @since 2.6.0
 */
final class BackupManager {

	/**
	 * Rows per archive record, by default.
	 */
	public const DEFAULT_BATCH_SIZE = 500;

	/**
	 * Encoded bytes after which a batch of rows is written, whatever its count.
	 */
	private const MAX_RECORD_BYTES = 4 * 1024 * 1024;

	/**
	 * Constructor.
	 *
	 * @param Database $db The database to back up, or to restore into.
	 */
	public function __construct( private Database $db ) {}

	/**
	 * Back up the database.
	 *
	 * Options:
	 *
	 *  - tables      string[]  Only these tables. Default: every table.
	 *  - exclude     string[]  Leave these tables out.
	 *  - data        bool      Include rows. False backs up the schema only. Default true.
	 *  - consistent  bool      Read every table from one snapshot. Default true.
	 *  - compress    bool|null Gzip the file. Default: when the path ends in ".gz".
	 *  - batch_size  int       Rows per archive record. Default 500.
	 *  - on_progress callable  fn( string $table, int $rows_so_far ): void, after each batch.
	 *
	 * @param string|resource      $target  A file path (written atomically: the
	 *                                      file appears only once complete), or an
	 *                                      open writable stream.
	 * @param array<string, mixed> $options
	 * @return BackupReport
	 * @throws BackupException When the backup fails; no file is left at the path.
	 */
	public function backup( mixed $target, array $options = [] ) : BackupReport {
		$this->assert_usable();

		$started    = microtime( true );
		$options    = self::backup_options( $options );
		$dialect    = AbstractBackupDialect::for( $this->db );
		$writer     = null;
		$snapshot   = false;
		$counts     = [];

		try {
			if ( $options['consistent'] ) {
				$dialect->begin_snapshot();
				$snapshot = true;
			}

			$names = self::select_tables( $dialect->list_tables(), $options['tables'], $options['exclude'] );

			if ( $options['consistent'] ) {
				$dialect->check_snapshot_coverage( $names );
			}

			$schemas = [];

			foreach ( $names as $name ) {
				$schemas[ $name ] = $dialect->read_table( $name );
				self::check_restorable_names( $schemas[ $name ], $dialect );
			}

			$schemas = self::dependency_order( $schemas, $dialect );
			$writer  = ArchiveWriter::open( $target, $options['compress'] );

			$writer->write( [
				'kind'       => 'header',
				'format'     => ArchiveWriter::FORMAT,
				'version'    => ArchiveWriter::VERSION,
				'created_at' => gmdate( 'Y-m-d\TH:i:s\Z' ),
				'source'     => $this->describe_source( $dialect ),
				'consistent' => $options['consistent'],
				'data'       => $options['data'],
				'tables'     => array_keys( $schemas ),
			] );

			foreach ( $schemas as $schema ) {
				$writer->write( $schema->to_record() );
			}

			foreach ( $schemas as $name => $schema ) {
				$counts[ $name ] = $options['data']
					? $this->write_table_rows( $writer, $dialect, $schema, $options['batch_size'], $options['on_progress'] )
					: 0;

				$writer->write( [ 'kind' => 'table_end', 'table' => $name, 'rows' => $counts[ $name ] ] );
			}

			if ( $snapshot ) {
				$snapshot = false;
				$dialect->end_snapshot();
			}

			$checksum = $writer->finish( [ 'tables' => count( $counts ), 'rows' => array_sum( $counts ) ] );
		} catch ( Throwable $e ) {
			$writer?->abort();

			if ( $snapshot ) {
				$dialect->abort_snapshot();
			}

			throw $e instanceof BackupException ? $e : new BackupException( 'The backup failed: ' . $e->getMessage(), 0, $e );
		}

		return new BackupReport(
			$dialect->engine(),
			$counts,
			$options['consistent'],
			$options['data'],
			$checksum,
			$writer->bytes_written(),
			round( microtime( true ) - $started, 3 ),
			$dialect->warnings(),
		);
	}

	/**
	 * Restore a backup into the database.
	 *
	 * The backup may come from any supported engine. Tables are created from
	 * their portable definitions, adapted to this engine; see the warnings in
	 * the report for anything that could not be restored exactly.
	 *
	 * Options:
	 *
	 *  - existing    string    What to do when a table already exists:
	 *                          'error'   (default) refuse, before anything is changed;
	 *                          'replace' drop it and restore the backed-up table in its place;
	 *                          'append'  keep it and insert the backed-up rows into it.
	 *  - tables      string[]  Only these tables of the backup. Default: every table.
	 *  - exclude     string[]  Leave these tables out.
	 *  - data        bool      Restore rows. False restores the schema only. Default true.
	 *  - batch_size  int       Rows per INSERT (fewer when the engine's placeholder
	 *                          limit requires). Default 500.
	 *  - on_progress callable  fn( string $table, int $rows_so_far ): void, after each batch.
	 *
	 * @param string|resource      $source  A file path, or an open readable stream.
	 * @param array<string, mixed> $options
	 * @return RestoreReport
	 * @throws BackupException When the restore fails. The database is then as it
	 *                         was before, except where the message says otherwise.
	 */
	public function restore( mixed $source, array $options = [] ) : RestoreReport {
		$this->assert_usable();

		$options = self::restore_options( $options );
		$reader  = ArchiveReader::open( $source );

		try {
			return ( new Restoration( $this->db, AbstractBackupDialect::for( $this->db ), $reader, $options ) )->run();
		} finally {
			$reader->close();
		}
	}

	/**
	 * Read a whole archive and verify it, without touching any database.
	 *
	 * @param string|resource $source A file path, or an open readable stream.
	 * @return array{header: array<string, mixed>, tables: array<string, int>, rows: int, checksum: string}
	 * @throws BackupException When the archive is damaged, truncated or not a DBPrism backup.
	 */
	public static function verify( mixed $source ) : array {
		$reader = ArchiveReader::open( $source );

		try {
			$counts = [];

			foreach ( $reader->records() as $record ) {
				if ( 'rows' === $record['kind'] ) {
					$counts[ (string) $record['table'] ] = ( $counts[ (string) $record['table'] ] ?? 0 ) + count( (array) $record['rows'] );
				} elseif ( 'table_end' === $record['kind'] ) {
					$table = (string) $record['table'];

					if ( (int) $record['rows'] !== ( $counts[ $table ] ?? 0 ) ) {
						throw new BackupException( sprintf( 'The backup is damaged: table "%s" should have %d rows but has %d.', $table, (int) $record['rows'], $counts[ $table ] ?? 0 ) );
					}

					$counts[ $table ] ??= 0;
				}
			}

			return [
				'header'   => $reader->header(),
				'tables'   => $counts,
				'rows'     => array_sum( $counts ),
				'checksum' => (string) ( $reader->footer()['sha256'] ?? '' ),
			];
		} finally {
			$reader->close();
		}
	}

	/*
	|------------------------------
	| Backup steps
	|------------------------------
	*/

	/**
	 * Stream one table's rows into the archive.
	 *
	 * @param ArchiveWriter         $writer
	 * @param AbstractBackupDialect $dialect
	 * @param TableSchema           $schema
	 * @param int                   $batch_size
	 * @param callable|null         $on_progress
	 * @return int Rows written.
	 * @throws BackupException
	 */
	private function write_table_rows( ArchiveWriter $writer, AbstractBackupDialect $dialect, TableSchema $schema, int $batch_size, ?callable $on_progress ) : int {
		$columns = $schema->table->get_columns();
		$sql     = $dialect->select_rows_sql( $schema );
		$adapter = $this->db->get_adapter();
		$name    = $schema->name();
		$batch   = [];
		$bytes   = 0;
		$count   = 0;

		if ( $adapter instanceof StreamingAdapterInterface ) {
			$rows = $adapter->stream( $sql );

			if ( false === $rows ) {
				throw new BackupException( sprintf( 'Reading table "%s" failed: %s', $name, $adapter->get_last_error() ?? 'unknown error' ) );
			}
		} else {
			$rows  = $this->db->get_results( $sql );
			$error = $this->db->get_last_error();

			if ( null !== $error && '' !== $error ) {
				throw new BackupException( sprintf( 'Reading table "%s" failed: %s', $name, $error ) );
			}
		}

		try {
			foreach ( $rows as $row ) {
				$values  = array_values( (array) $row );
				$encoded = ArchiveWriter::encode( array_map(
					fn( Column $column, mixed $value ) => ValueCodec::encode( $column, $value ),
					$columns,
					$values
				) );

				$batch[] = $encoded;
				$bytes  += strlen( $encoded );
				++$count;

				if ( count( $batch ) >= $batch_size || $bytes >= self::MAX_RECORD_BYTES ) {
					$writer->write_rows( $name, $batch );
					$batch = [];
					$bytes = 0;

					if ( null !== $on_progress ) {
						$on_progress( $name, $count );
					}
				}
			}
		} finally {
			if ( is_object( $rows ) && method_exists( $rows, 'close' ) ) {
				$rows->close();
			}
		}

		if ( ! empty( $batch ) ) {
			$writer->write_rows( $name, $batch );

			if ( null !== $on_progress ) {
				$on_progress( $name, $count );
			}
		}

		return $count;
	}

	/**
	 * The source engine as the header records it.
	 *
	 * @param AbstractBackupDialect $dialect
	 * @return array<string, mixed>
	 */
	private function describe_source( AbstractBackupDialect $dialect ) : array {
		$version = null;

		try {
			$version = ( new Inspector( $this->db ) )->get_server_version();
		} catch ( Throwable $e ) {
			// The version is informational only.
		}

		return [
			'engine'   => $dialect->engine(),
			'version'  => $version,
			'database' => basename( (string) $this->db->get_config()->dbname ),
		];
	}

	/**
	 * Warn about names the query builder cannot create on restore.
	 *
	 * @param TableSchema           $schema
	 * @param AbstractBackupDialect $dialect
	 * @return void
	 */
	private static function check_restorable_names( TableSchema $schema, AbstractBackupDialect $dialect ) : void {
		try {
			Restoration::assert_plain_names( $schema );
		} catch ( BackupException $e ) {
			$dialect->warn( $e->getMessage() . ' The table is backed up, but DBPrism cannot restore it.' );
		}
	}

	/*
	|------------------------------
	| Shared helpers
	|------------------------------
	*/

	/**
	 * Order tables so each comes after the tables its foreign keys reference.
	 *
	 * Restores then load parents before children on every engine. Tables in a
	 * cycle of foreign keys keep name order (restore defers or adds foreign
	 * keys after loading, so they still restore).
	 *
	 * @param array<string, TableSchema> $schemas
	 * @param AbstractBackupDialect      $dialect For a warning about cycles.
	 * @return array<string, TableSchema>
	 */
	public static function dependency_order( array $schemas, AbstractBackupDialect $dialect ) : array {
		$pending = [];

		foreach ( $schemas as $name => $schema ) {
			$pending[ $name ] = [];

			foreach ( $schema->foreign_keys() as $foreign ) {
				$parent = (string) $foreign->references_table;

				if ( $parent !== $name && isset( $schemas[ $parent ] ) ) {
					$pending[ $name ][ $parent ] = true;
				}
			}
		}

		ksort( $pending, SORT_STRING );
		$ordered = [];

		while ( ! empty( $pending ) ) {
			$ready = array_keys( array_filter( $pending, fn( array $parents ) => empty( $parents ) ) );

			if ( empty( $ready ) ) {
				$dialect->warn( sprintf( 'Tables %s reference each other in a cycle of foreign keys.', implode( ', ', array_keys( $pending ) ) ) );
				$ready = array_keys( $pending );
			}

			foreach ( $ready as $name ) {
				$ordered[ $name ] = $schemas[ $name ];
				unset( $pending[ $name ] );
			}

			foreach ( $pending as &$parents ) {
				$parents = array_diff_key( $parents, array_flip( $ready ) );
			}
			unset( $parents );
		}

		return $ordered;
	}

	/**
	 * The tables to include, in the order given.
	 *
	 * @param string[]      $available
	 * @param string[]|null $only
	 * @param string[]      $exclude
	 * @return string[]
	 * @throws BackupException When $only names a table that is not available.
	 */
	public static function select_tables( array $available, ?array $only, array $exclude ) : array {
		if ( null !== $only ) {
			$missing = array_diff( $only, $available );

			if ( ! empty( $missing ) ) {
				throw new BackupException( sprintf( 'No such table(s): %s.', implode( ', ', $missing ) ) );
			}

			$available = array_values( array_intersect( $available, $only ) );
		}

		return array_values( array_diff( $available, $exclude ) );
	}

	/**
	 * Validate backup options and fill in defaults.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 * @throws BackupException
	 */
	private static function backup_options( array $options ) : array {
		self::reject_unknown( $options, [ 'tables', 'exclude', 'data', 'consistent', 'compress', 'batch_size', 'on_progress' ] );

		return [
			'tables'      => self::name_list( $options, 'tables' ),
			'exclude'     => self::name_list( $options, 'exclude' ) ?? [],
			'data'        => (bool) ( $options['data'] ?? true ),
			'consistent'  => (bool) ( $options['consistent'] ?? true ),
			'compress'    => isset( $options['compress'] ) ? (bool) $options['compress'] : null,
			'batch_size'  => self::batch_size( $options ),
			'on_progress' => self::callback( $options ),
		];
	}

	/**
	 * Validate restore options and fill in defaults.
	 *
	 * @param array<string, mixed> $options
	 * @return array<string, mixed>
	 * @throws BackupException
	 */
	private static function restore_options( array $options ) : array {
		self::reject_unknown( $options, [ 'existing', 'tables', 'exclude', 'data', 'batch_size', 'on_progress' ] );

		$existing = $options['existing'] ?? 'error';

		if ( ! in_array( $existing, [ 'error', 'replace', 'append' ], true ) ) {
			throw new BackupException( 'The "existing" option must be "error", "replace" or "append".' );
		}

		return [
			'existing'    => $existing,
			'tables'      => self::name_list( $options, 'tables' ),
			'exclude'     => self::name_list( $options, 'exclude' ) ?? [],
			'data'        => (bool) ( $options['data'] ?? true ),
			'batch_size'  => self::batch_size( $options ),
			'on_progress' => self::callback( $options ),
		];
	}

	/**
	 * Throw on an option name that is not known (most likely a typo).
	 *
	 * @param array<string, mixed> $options
	 * @param string[]             $known
	 * @return void
	 * @throws BackupException
	 */
	private static function reject_unknown( array $options, array $known ) : void {
		$unknown = array_diff( array_keys( $options ), $known );

		if ( ! empty( $unknown ) ) {
			throw new BackupException( sprintf( 'Unknown option(s): %s. Known options: %s.', implode( ', ', $unknown ), implode( ', ', $known ) ) );
		}
	}

	/**
	 * A list-of-names option, or null when it is not set.
	 *
	 * @param array<string, mixed> $options
	 * @param string               $key
	 * @return string[]|null
	 * @throws BackupException
	 */
	private static function name_list( array $options, string $key ) : ?array {
		if ( ! isset( $options[ $key ] ) ) {
			return null;
		}

		if ( ! is_array( $options[ $key ] ) ) {
			throw new BackupException( sprintf( 'The "%s" option must be a list of table names.', $key ) );
		}

		return array_values( array_map( 'strval', $options[ $key ] ) );
	}

	/**
	 * The batch_size option.
	 *
	 * @param array<string, mixed> $options
	 * @return int
	 * @throws BackupException
	 */
	private static function batch_size( array $options ) : int {
		$size = $options['batch_size'] ?? self::DEFAULT_BATCH_SIZE;

		if ( ! is_int( $size ) || $size < 1 ) {
			throw new BackupException( 'The "batch_size" option must be a positive integer.' );
		}

		return $size;
	}

	/**
	 * The on_progress option.
	 *
	 * @param array<string, mixed> $options
	 * @return callable|null
	 * @throws BackupException
	 */
	private static function callback( array $options ) : ?callable {
		$callback = $options['on_progress'] ?? null;

		if ( null !== $callback && ! is_callable( $callback ) ) {
			throw new BackupException( 'The "on_progress" option must be callable.' );
		}

		return $callback;
	}

	/**
	 * Throw when the database has no real adapter.
	 *
	 * @return void
	 * @throws BackupException
	 */
	private function assert_usable() : void {
		if ( $this->db->has_null_adapter() ) {
			throw new BackupException( 'No database is configured: the database uses NullDBAdapter.' );
		}
	}
}
