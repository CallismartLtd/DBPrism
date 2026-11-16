<?php
/**
 * Restoration class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup;

use Callismart\DBPrism\Backup\Archive\ArchiveReader;
use Callismart\DBPrism\Backup\Dialects\AbstractBackupDialect;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\Table;
use Throwable;

/**
 * One run of BackupManager::restore().
 *
 * Steps, in order:
 *
 *  1. Read every table record (they come first in an archive), adapt each
 *     table to the target engine, and check the target: with 'existing' =>
 *     'error', a table that already exists stops the restore here, before
 *     anything is changed.
 *  2. Open the restore transaction (on engines with transactional DDL it
 *     covers everything; on MySQL it covers the data) and create the tables,
 *     under staging names on MySQL.
 *  3. Load the rows, batch by batch, as they are read.
 *  4. At the footer, the reader verifies the archive's checksum; then the
 *     transaction commits, staged tables are swapped in, foreign keys and
 *     CHECK constraints are added, and sequences are moved past the restored IDs.
 *
 * Any failure rolls the transaction back and drops whatever was staged.
 *
 * @internal Use BackupManager::restore().
 * @since 2.6.0
 */
final class Restoration {

	/**
	 * The archive's tables, by name, as read.
	 *
	 * @var array<string, TableSchema>
	 */
	private array $schemas = [];

	/**
	 * The tables being restored, adapted to the target engine, in archive order.
	 *
	 * @var array<string, TableSchema>
	 */
	private array $targets = [];

	/**
	 * The name each restored table is loaded under (a staging name on MySQL).
	 *
	 * @var array<string, string>
	 */
	private array $load_names = [];

	/**
	 * Tables this restore creates (as opposed to appending into), by name => load name.
	 *
	 * @var array<string, string>
	 */
	private array $created = [];

	/**
	 * Tables that existed in the target before the restore.
	 *
	 * @var array<string, bool>
	 */
	private array $existing = [];

	/**
	 * Rows read from the archive, by table (restored or not).
	 *
	 * @var array<string, int>
	 */
	private array $loaded = [];

	/**
	 * Rows inserted, by restored table.
	 *
	 * @var array<string, int>
	 */
	private array $restored = [];

	/**
	 * Rows waiting to be inserted, by table.
	 *
	 * @var array<string, array<int, array>>
	 */
	private array $pending = [];

	/**
	 * Foreign keys of tables outside the restore that dropping replaced tables removes, by table.
	 *
	 * @var array<string, Constraint[]>
	 */
	private array $outside_keys = [];

	/**
	 * Tables whose table_end has been read.
	 *
	 * @var array<string, bool>
	 */
	private array $ended = [];

	/**
	 * Whether the tables have been created and data may be loaded.
	 *
	 * @var bool
	 */
	private bool $prepared = false;

	/**
	 * Whether the restore session settings are applied.
	 *
	 * @var bool
	 */
	private bool $in_session = false;

	/**
	 * Whether the restore transaction is open.
	 *
	 * @var bool
	 */
	private bool $in_transaction = false;

	/**
	 * Whether staged tables have been moved into place (cannot be undone).
	 *
	 * @var bool
	 */
	private bool $swapped = false;

	/**
	 * Constructor.
	 *
	 * @param Database              $db
	 * @param AbstractBackupDialect $dialect The target engine's dialect.
	 * @param ArchiveReader         $reader  The archive, with its header read.
	 * @param array<string, mixed>  $options Validated restore options.
	 */
	public function __construct(
		private Database $db,
		private AbstractBackupDialect $dialect,
		private ArchiveReader $reader,
		private array $options,
	) {}

	/**
	 * Run the restore.
	 *
	 * @return RestoreReport
	 * @throws BackupException
	 */
	public function run() : RestoreReport {
		$started = microtime( true );
		$header  = $this->reader->header();

		try {
			foreach ( $this->reader->records() as $record ) {
				match ( $record['kind'] ) {
					'table'     => $this->read_table_record( $record ),
					'rows'      => $this->read_rows_record( $record ),
					'table_end' => $this->read_table_end( $record ),
					default     => null, // A later format version may add kinds; they carry nothing this version restores.
				};
			}

			// The footer has been read and the checksum verified.
			if ( ! $this->prepared ) {
				$this->prepare();
			}

			$this->finish();
		} catch ( Throwable $e ) {
			$this->undo();

			throw $this->failure( $e );
		}

		return new RestoreReport(
			(string) ( $header['source']['engine'] ?? '' ),
			$this->dialect->engine(),
			$this->restored,
			array_keys( $this->created ),
			(string) ( $this->reader->footer()['sha256'] ?? '' ),
			round( microtime( true ) - $started, 3 ),
			$this->dialect->warnings(),
		);
	}

	/**
	 * Throw unless every name in a table can be written by the query builder.
	 *
	 * @param TableSchema $schema
	 * @return void
	 * @throws BackupException
	 */
	public static function assert_plain_names( TableSchema $schema ) : void {
		AbstractBackupDialect::assert_plain_name( $schema->name(), 'table' );

		foreach ( $schema->table->get_columns() as $column ) {
			AbstractBackupDialect::assert_plain_name( $column->name, sprintf( 'column of %s', $schema->name() ) );
		}

		foreach ( $schema->table->get_constraints() as $constraint ) {
			if ( null !== $constraint->name ) {
				AbstractBackupDialect::assert_plain_name( $constraint->name, sprintf( '%s constraint of %s', $constraint->type, $schema->name() ) );
			}

			if ( 'foreign' === $constraint->type ) {
				AbstractBackupDialect::assert_plain_name( (string) $constraint->references_table, sprintf( 'table referenced by %s', $schema->name() ) );

				foreach ( $constraint->references_columns as $column ) {
					AbstractBackupDialect::assert_plain_name( $column, sprintf( 'column referenced by %s', $schema->name() ) );
				}
			}
		}
	}

	/*
	|------------------------------
	| Records
	|------------------------------
	*/

	/**
	 * A table record: collected until the first data record.
	 *
	 * @param array<string, mixed> $record
	 * @return void
	 * @throws BackupException
	 */
	private function read_table_record( array $record ) : void {
		if ( $this->prepared ) {
			throw new BackupException( 'The backup is damaged: a table record follows the data.' );
		}

		$schema = TableSchema::from_record( $record );

		if ( isset( $this->schemas[ $schema->name() ] ) ) {
			throw new BackupException( sprintf( 'The backup is damaged: table "%s" is defined twice.', $schema->name() ) );
		}

		$this->schemas[ $schema->name() ] = $schema;
	}

	/**
	 * A rows record: inserted, in batches, into its table.
	 *
	 * @param array<string, mixed> $record
	 * @return void
	 * @throws BackupException
	 */
	private function read_rows_record( array $record ) : void {
		if ( ! $this->prepared ) {
			$this->prepare();
		}

		$table = (string) ( $record['table'] ?? '' );

		if ( ! isset( $this->schemas[ $table ] ) || isset( $this->ended[ $table ] ) || ! is_array( $record['rows'] ?? null ) ) {
			throw new BackupException( sprintf( 'The backup is damaged: rows for table "%s" are out of place.', $table ) );
		}

		$columns = $this->schemas[ $table ]->table->get_columns();
		$width   = count( $columns );

		foreach ( $record['rows'] as $row ) {
			if ( ! is_array( $row ) || count( $row ) !== $width ) {
				throw new BackupException( sprintf( 'The backup is damaged: a row of table "%s" does not have %d values.', $table, $width ) );
			}

			$this->loaded[ $table ] = ( $this->loaded[ $table ] ?? 0 ) + 1;

			if ( ! isset( $this->targets[ $table ] ) || ! $this->options['data'] ) {
				continue;
			}

			$this->pending[ $table ][] = array_map(
				fn( Column $column, mixed $value ) => ValueCodec::decode( $column, $value ),
				$columns,
				array_values( $row )
			);

			if ( count( $this->pending[ $table ] ) >= $this->rows_per_insert( $width ) ) {
				$this->flush( $table );
			}
		}
	}

	/**
	 * A table_end record: the table's row count, checked.
	 *
	 * @param array<string, mixed> $record
	 * @return void
	 * @throws BackupException
	 */
	private function read_table_end( array $record ) : void {
		if ( ! $this->prepared ) {
			$this->prepare();
		}

		$table = (string) ( $record['table'] ?? '' );

		if ( ! isset( $this->schemas[ $table ] ) || isset( $this->ended[ $table ] ) ) {
			throw new BackupException( sprintf( 'The backup is damaged: the end of table "%s" is out of place.', $table ) );
		}

		$expected = (int) ( $record['rows'] ?? -1 );
		$actual   = $this->loaded[ $table ] ?? 0;

		if ( $expected !== $actual ) {
			throw new BackupException( sprintf( 'The backup is damaged: table "%s" should have %d rows but has %d.', $table, $expected, $actual ) );
		}

		$this->loaded[ $table ] = $actual;
		$this->ended[ $table ]  = true;

		if ( isset( $this->targets[ $table ] ) ) {
			$this->flush( $table );
		}
	}

	/*
	|------------------------------
	| Steps
	|------------------------------
	*/

	/**
	 * Step 1 and 2: choose and adapt the tables, check the target, open the
	 * transaction, and create the tables.
	 *
	 * @return void
	 * @throws BackupException
	 */
	private function prepare() : void {
		$this->prepared = true;

		$names = BackupManager::select_tables( array_keys( $this->schemas ), $this->options['tables'], $this->options['exclude'] );

		foreach ( $names as $name ) {
			self::assert_plain_names( $this->schemas[ $name ] );
		}

		$existing_tables = $this->dialect->list_tables();

		foreach ( $names as $name ) {
			$this->existing[ $name ] = in_array( $name, $existing_tables, true ) || $this->dialect->table_exists( $name );
		}

		$conflicts = array_keys( array_filter( $this->existing ) );

		if ( 'error' === $this->options['existing'] && ! empty( $conflicts ) ) {
			throw new BackupException( sprintf(
				'Table(s) %s already exist. Nothing was changed. Restore with \'existing\' => \'replace\' to replace them, or \'append\' to add the rows to them.',
				implode( ', ', $conflicts )
			) );
		}

		// Tables a foreign key may reference: those restored, and those already there.
		$available = array_flip( array_merge( $names, $existing_tables ) );
		$used      = [ 'index' => [], 'foreign' => [] ];

		foreach ( $names as $name ) {
			$target                 = $this->dialect->adapt( $this->schemas[ $name ] );
			$this->targets[ $name ] = $this->with_resolvable_names( $target, $available, $used );
		}

		$appending = 'append' === $this->options['existing'];
		$staging   = $this->dialect->uses_staging() && ! $appending;

		$this->dialect->begin_restore_session();
		$this->in_session = true;

		if ( $this->dialect->transactional_ddl() ) {
			$this->dialect->begin();
			$this->in_transaction = true;

			if ( 'replace' === $this->options['existing'] && ! empty( $conflicts ) ) {
				$this->outside_keys = $this->dialect->foreign_keys_into( $conflicts, $names );
				$this->dialect->drop_tables( $conflicts );
			}
		}

		foreach ( $this->targets as $name => $target ) {
			if ( $appending && $this->existing[ $name ] ) {
				$this->load_names[ $name ] = $name;
				continue;
			}

			$load_name = $staging ? $this->dialect->staging_name( $name ) : $name;

			if ( $staging ) {
				$this->dialect->drop_tables( [ $load_name ] ); // Left by an earlier restore that was interrupted.
			}

			$this->dialect->create_table( $target->table, $load_name );

			$this->load_names[ $name ] = $load_name;
			$this->created[ $name ]    = $load_name;
		}

		$this->restored = array_fill_keys( array_keys( $this->targets ), 0 );

		if ( ! $this->in_transaction ) {
			$this->dialect->begin();
			$this->in_transaction = true;
		}
	}

	/**
	 * Step 4: commit, swap staged tables in, and add what comes after the data.
	 *
	 * @return void
	 * @throws BackupException
	 */
	private function finish() : void {
		foreach ( array_keys( $this->targets ) as $name ) {
			if ( ! isset( $this->ended[ $name ] ) && ( $this->loaded[ $name ] ?? 0 ) > 0 ) {
				throw new BackupException( sprintf( 'The backup is damaged: table "%s" has no end record.', $name ) );
			}

			$this->flush( $name );
		}

		if ( ! $this->dialect->transactional_ddl() ) {
			$this->dialect->commit();
			$this->in_transaction = false;

			$staged = array_filter( $this->created, fn( string $load_name, string $name ) => $load_name !== $name, ARRAY_FILTER_USE_BOTH );

			if ( ! empty( $staged ) ) {
				$this->dialect->swap_in( $staged );
			}

			$this->swapped = true;
		}

		foreach ( $this->targets as $name => $target ) {
			$this->dialect->after_load( $target->table, $name );
		}

		foreach ( array_keys( $this->created ) as $name ) {
			$target = $this->targets[ $name ];

			if ( ! $this->dialect->inline_foreign_keys() ) {
				foreach ( $target->foreign_keys() as $foreign ) {
					$this->dialect->add_foreign_key( $name, $foreign );
				}
			}

			foreach ( $target->checks as $check ) {
				$this->dialect->add_check( $name, $check['name'] ?? null, (string) $check['definition'] );
			}
		}

		foreach ( $this->outside_keys as $table => $keys ) {
			foreach ( $keys as $foreign ) {
				$this->dialect->add_foreign_key( (string) $table, $foreign );
			}
		}

		if ( $this->in_transaction ) {
			$this->dialect->before_commit( array_keys( $this->targets ) );
			$this->dialect->commit();
			$this->in_transaction = false;
		}

		$this->end_session();
	}

	/**
	 * Insert a table's pending rows.
	 *
	 * @param string $table
	 * @return void
	 * @throws BackupException
	 */
	private function flush( string $table ) : void {
		$rows = $this->pending[ $table ] ?? [];

		if ( empty( $rows ) ) {
			return;
		}

		unset( $this->pending[ $table ] );

		$columns = array_map( fn( Column $column ) => $column->name, $this->targets[ $table ]->table->get_columns() );

		try {
			$this->dialect->insert_rows( $this->load_names[ $table ], $columns, $rows );
			$this->restored[ $table ] += count( $rows );
		} finally {
			foreach ( $rows as $row ) {
				foreach ( $row as $value ) {
					if ( AbstractBackupDialect::is_stream( $value ) ) {
						fclose( $value );
					}
				}
			}
		}

		if ( null !== $this->options['on_progress'] ) {
			( $this->options['on_progress'] )( $table, $this->restored[ $table ] );
		}
	}

	/**
	 * Rows per INSERT for a table of a width, within the engine's placeholder limit.
	 *
	 * @param int $width Columns.
	 * @return int
	 */
	private function rows_per_insert( int $width ) : int {
		return max( 1, min( $this->options['batch_size'], intdiv( $this->dialect->max_placeholders(), max( 1, $width ) ) ) );
	}

	/**
	 * Make constraint names safe for the target, and drop foreign keys it cannot hold.
	 *
	 * PostgreSQL and SQLite name indexes per database, and MySQL names
	 * foreign keys per database, while the source may have reused a name
	 * across tables: a name already used is given the table's name as a prefix.
	 * A foreign key to a table that is neither restored nor in the target is
	 * left out.
	 *
	 * @param TableSchema                      $target
	 * @param array<string, int>               $available Table names a foreign key may reference.
	 * @param array<string, array<string, bool>> $used    Lowercase names used so far, by kind.
	 * @return TableSchema
	 */
	private function with_resolvable_names( TableSchema $target, array $available, array &$used ) : TableSchema {
		$table = $target->table;
		$name  = $table->get_name();
		$kept  = [];

		foreach ( $table->get_constraints() as $constraint ) {
			if ( 'foreign' === $constraint->type && ! isset( $available[ (string) $constraint->references_table ] ) ) {
				$this->dialect->warn( sprintf(
					'%s: its foreign key to %s was not restored, because that table is neither in the backup nor in the database.',
					$name,
					$constraint->references_table
				) );
				continue;
			}

			$kind = 'foreign' === $constraint->type ? 'foreign' : 'index';

			if ( 'primary' !== $constraint->type && null !== $constraint->name ) {
				if ( isset( $used[ $kind ][ strtolower( $constraint->name ) ] ) ) {
					$constraint->name( substr( $name . '_' . $constraint->name, 0, 63 ) );
				}

				$used[ $kind ][ strtolower( $constraint->name ) ] = true;
			}

			$kept[] = $constraint;
		}

		$rebuilt = Table::make( $name )->add_columns( $table->get_columns() )->add_constraints( $kept );

		return new TableSchema( $rebuilt, $target->engine, $target->native_defaults, $target->checks );
	}

	/*
	|------------------------------
	| Failure
	|------------------------------
	*/

	/**
	 * Roll back what can be rolled back.
	 *
	 * @return void
	 */
	private function undo() : void {
		foreach ( $this->pending as $rows ) {
			foreach ( $rows as $row ) {
				foreach ( $row as $value ) {
					if ( AbstractBackupDialect::is_stream( $value ) ) {
						fclose( $value );
					}
				}
			}
		}

		$this->pending = [];

		if ( $this->in_transaction ) {
			$this->dialect->rollback();
			$this->in_transaction = false;
		}

		// Without transactional DDL, created tables survive the rollback: drop
		// them, unless they have already been swapped into place.
		if ( ! $this->dialect->transactional_ddl() && ! $this->swapped && ! empty( $this->created ) ) {
			try {
				$this->dialect->drop_tables( array_values( $this->created ) );
			} catch ( Throwable $e ) {
				$this->dialect->warn( 'Dropping the tables of the failed restore failed: ' . $e->getMessage() );
			}
		}

		$this->end_session();
	}

	/**
	 * The exception to throw for a failure, saying what state the database is in.
	 *
	 * @param Throwable $e
	 * @return BackupException
	 */
	private function failure( Throwable $e ) : BackupException {
		$state = $this->swapped
			? 'The restored tables were already in place; foreign keys or constraints added after them may be missing.'
			: ( $this->prepared ? 'The restore was rolled back; the database is as it was.' : 'Nothing was changed.' );

		$message = $e->getMessage();

		if ( ! str_contains( $message, 'Nothing was changed' ) ) {
			$message .= ' ' . $state;
		}

		return new BackupException( 'The restore failed: ' . $message, 0, $e );
	}

	/**
	 * Undo the session settings, once.
	 *
	 * @return void
	 */
	private function end_session() : void {
		if ( $this->in_session ) {
			$this->in_session = false;

			try {
				$this->dialect->end_restore_session();
			} catch ( Throwable $e ) {
				$this->dialect->warn( 'Restoring the session settings failed: ' . $e->getMessage() );
			}
		}
	}
}
