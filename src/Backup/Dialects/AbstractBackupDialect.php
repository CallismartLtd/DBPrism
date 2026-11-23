<?php
/**
 * Abstract backup dialect class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup\Dialects
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup\Dialects;

use Callismart\DBPrism\Backup\TableSchema;
use Callismart\DBPrism\Backup\ValueCodec;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Inspection\Inspector;
use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\DefaultColumnValue;
use Callismart\DBPrism\Utils\Identifier;
use Callismart\DBPrism\Utils\ParamType;
use Callismart\DBPrism\Utils\Table;
use Throwable;

/**
 * Everything a backup or restore does differently on one engine.
 *
 * BackupManager runs the same steps on every engine; a dialect supplies the
 * engine's part of each:
 *
 *  - Backup: open a read snapshot, so every table is read at the same moment;
 *    list the tables; read each table's definition into an engine-neutral
 *    TableSchema (native types mapped to ColumnType).
 *  - Restore: adapt a TableSchema to this engine; create tables with the
 *    query builder; load rows; add foreign keys; reset sequences; and keep
 *    the whole restore atomic as far as the engine allows.
 *
 * A dialect collects warnings for what it could not carry over exactly,
 * which end up in the backup or restore report.
 *
 * @since 2.6.0
 */
abstract class AbstractBackupDialect {

	/**
	 * Warnings collected so far.
	 *
	 * @var string[]
	 */
	protected array $warnings = [];

	/**
	 * The inspector, once made.
	 *
	 * @var Inspector|null
	 */
	private ?Inspector $inspector = null;

	/**
	 * Constructor.
	 *
	 * @param Database $db The database to back up or restore into.
	 */
	public function __construct( protected Database $db ) {}

	/**
	 * The dialect for a database's engine.
	 *
	 * @param Database $db
	 * @return static
	 * @throws BackupException When the engine is not supported.
	 */
	public static function for( Database $db ) : static {
		return match ( $db->get_driver() ) {
			'mysql'  => new MysqlBackupDialect( $db ),
			'pgsql'  => new PostgresBackupDialect( $db ),
			'sqlite' => new SqliteBackupDialect( $db ),
			default  => throw new BackupException( sprintf( 'Backups are not supported on the "%s" engine.', $db->get_driver() ) ),
		};
	}

	/**
	 * The engine name: mysql, pgsql or sqlite.
	 *
	 * @return string
	 */
	abstract public function engine() : string;

	/*
	|------------------------------
	| Backup
	|------------------------------
	*/

	/**
	 * Open a read-only snapshot: every query until end_snapshot() sees the
	 * database as it was at this moment, whatever other connections write.
	 *
	 * @return void
	 * @throws BackupException When the snapshot cannot be opened.
	 */
	abstract public function begin_snapshot() : void;

	/**
	 * Close the snapshot opened by begin_snapshot().
	 *
	 * @return void
	 */
	public function end_snapshot() : void {
		$this->run( 'COMMIT' );
	}

	/**
	 * Close the snapshot after a failure, without masking the failure.
	 *
	 * @return void
	 */
	public function abort_snapshot() : void {
		try {
			$this->db->exec( 'ROLLBACK' );
		} catch ( Throwable $e ) {
			// The failure that led here is the one to report.
		}
	}

	/**
	 * The base tables of the database (no views), in name order.
	 *
	 * @return string[]
	 */
	public function list_tables() : array {
		$tables = array_map( 'strval', $this->inspector()->get_all_tables() );
		sort( $tables, SORT_STRING );

		return $tables;
	}

	/**
	 * Read a table's definition.
	 *
	 * @param string $table
	 * @return TableSchema
	 * @throws BackupException When the table cannot be read.
	 */
	abstract public function read_table( string $table ) : TableSchema;

	/**
	 * Warnings about what the snapshot does not cover for these tables.
	 *
	 * @param string[] $tables
	 * @return void
	 */
	public function check_snapshot_coverage( array $tables ) : void {}

	/**
	 * The query that reads a table's rows.
	 *
	 * Ordered by the primary key when there is one, so the same data always
	 * makes the same archive.
	 *
	 * @param TableSchema $schema
	 * @return string
	 */
	public function select_rows_sql( TableSchema $schema ) : string {
		$columns = array_map( fn( Column $column ) => $this->quote( $column->name ), $schema->table->get_columns() );
		$sql     = sprintf( 'SELECT %s FROM %s', implode( ', ', $columns ), $this->quote( $schema->name() ) );
		$key     = $schema->primary_key();

		if ( ! empty( $key ) ) {
			$sql .= ' ORDER BY ' . implode( ', ', array_map( fn( string $column ) => $this->quote( $column ) . ' ASC', $key ) );
		}

		return $sql;
	}

	/*
	|------------------------------
	| Restore
	|------------------------------
	*/

	/**
	 * Whether CREATE and DROP TABLE take part in transactions, so a whole
	 * restore can be rolled back.
	 *
	 * @return bool
	 */
	public function transactional_ddl() : bool {
		return true;
	}

	/**
	 * Whether foreign keys are written in CREATE TABLE (true) or added once
	 * the data is loaded (false).
	 *
	 * @return bool
	 */
	public function inline_foreign_keys() : bool {
		return false;
	}

	/**
	 * Whether new tables are loaded under staging names and swapped in at
	 * the end (for engines without transactional DDL).
	 *
	 * @return bool
	 */
	public function uses_staging() : bool {
		return false;
	}

	/**
	 * Settings for the restore session; called before anything else.
	 *
	 * @return void
	 */
	public function begin_restore_session() : void {}

	/**
	 * Undo begin_restore_session(); called last, also after a failure.
	 *
	 * @return void
	 */
	public function end_restore_session() : void {}

	/**
	 * Called right after the restore transaction begins.
	 *
	 * @return void
	 */
	public function after_begin() : void {}

	/**
	 * Called before the restore commits, with the restored tables in place:
	 * the last chance to refuse the result.
	 *
	 * @param string[] $tables The restored tables.
	 * @return void
	 * @throws BackupException
	 */
	public function before_commit( array $tables ) : void {}

	/**
	 * The table as it is created on this engine.
	 *
	 * Starts from a copy of the portable table: adds the native defaults and
	 * keeps the CHECK constraints only when the table came from this engine,
	 * and lets each engine adjust what it cannot store as declared.
	 *
	 * @param TableSchema $schema
	 * @return TableSchema
	 */
	public function adapt( TableSchema $schema ) : TableSchema {
		$same_engine = $schema->engine === $this->engine();
		$table       = Table::make( $schema->name() );

		foreach ( $schema->table->get_columns() as $column ) {
			$column = clone $column;

			if ( isset( $schema->native_defaults[ $column->name ] ) ) {
				if ( $same_engine ) {
					$column->default = DefaultColumnValue::expression( $schema->native_defaults[ $column->name ] );
				} else {
					$this->warn( sprintf(
						'%s.%s: its default (%s) is specific to %s and was not restored.',
						$schema->name(),
						$column->name,
						$schema->native_defaults[ $column->name ],
						$schema->engine
					) );
				}
			}

			$table->add_column( $this->adapt_column( $schema, $column ) );
		}

		foreach ( $schema->table->get_constraints() as $constraint ) {
			$table->add_constraint( clone $constraint );
		}

		if ( ! $same_engine && ! empty( $schema->checks ) ) {
			$this->warn( sprintf(
				'%s: %d CHECK constraint(s) written for %s were not restored.',
				$schema->name(),
				count( $schema->checks ),
				$schema->engine
			) );
		}

		return new TableSchema( $this->adapt_table( $table ), $this->engine(), [], $same_engine ? $schema->checks : [] );
	}

	/**
	 * Adjust one column for this engine.
	 *
	 * @param TableSchema $schema The table as read from the archive.
	 * @param Column      $column A copy of the column.
	 * @return Column
	 */
	protected function adapt_column( TableSchema $schema, Column $column ) : Column {
		return $column;
	}

	/**
	 * Adjust the whole table for this engine.
	 *
	 * @param Table $table
	 * @return Table
	 */
	protected function adapt_table( Table $table ) : Table {
		return $table;
	}

	/**
	 * Whether a table exists in the target database.
	 *
	 * @param string $table
	 * @return bool
	 */
	public function table_exists( string $table ) : bool {
		return $this->inspector()->table_exists( $table );
	}

	/**
	 * Create a table, with its foreign keys when the engine writes them inline.
	 *
	 * @param Table       $table The table, as adapt() made it.
	 * @param string|null $as    The name to create it under, when not its own.
	 * @return void
	 * @throws BackupException When the database refuses it.
	 */
	public function create_table( Table $table, ?string $as = null ) : void {
		$builder = new SQLBuilder( $this->engine() );
		$intent  = $builder->create_table( $as ?? $table->get_name() );

		$intent->add_columns( $table->get_columns() );

		foreach ( $table->get_constraints() as $constraint ) {
			if ( 'foreign' !== $constraint->type || $this->inline_foreign_keys() ) {
				$intent->add_constraint( $constraint );
			}
		}

		$this->run( $builder->build() );
	}

	/**
	 * Add a foreign key to an existing table.
	 *
	 * @param string     $table
	 * @param Constraint $foreign_key
	 * @return void
	 * @throws BackupException When the database refuses it.
	 */
	public function add_foreign_key( string $table, Constraint $foreign_key ) : void {
		$builder = new SQLBuilder( $this->engine() );
		$builder->alter_table( $table )->add_constraint( $foreign_key );

		$this->run( $builder->build() );
	}

	/**
	 * Add a CHECK constraint to an existing table.
	 *
	 * @param string      $table
	 * @param string|null $name
	 * @param string      $definition The condition, in this engine's SQL.
	 * @return void
	 * @throws BackupException When the database refuses it.
	 */
	public function add_check( string $table, ?string $name, string $definition ) : void {
		$this->run( sprintf(
			'ALTER TABLE %s ADD %sCHECK (%s)',
			$this->quote( $table ),
			null === $name ? '' : 'CONSTRAINT ' . $this->quote( $name ) . ' ',
			$definition
		) );
	}

	/**
	 * Drop tables, whatever references them.
	 *
	 * @param string[] $tables
	 * @return void
	 * @throws BackupException When the database refuses it.
	 */
	public function drop_tables( array $tables ) : void {
		foreach ( $tables as $table ) {
			$this->run( 'DROP TABLE IF EXISTS ' . $this->quote( $table ) );
		}
	}

	/**
	 * Foreign keys of other tables that reference these, which dropping these
	 * tables would remove, by table.
	 *
	 * Restore reads them before replacing tables, and adds them back once the
	 * restored tables are loaded. Engines whose DROP TABLE keeps such keys
	 * (SQLite, which names the referenced table only) return none.
	 *
	 * @param string[] $tables    The tables being replaced.
	 * @param string[] $excluding Tables whose keys the restore recreates itself.
	 * @return array<string, Constraint[]>
	 */
	public function foreign_keys_into( array $tables, array $excluding ) : array {
		return [];
	}

	/**
	 * Called once a table's rows are loaded, e.g. to move its sequences past them.
	 *
	 * @param Table  $table
	 * @param string $name The name the table has now.
	 * @return void
	 */
	public function after_load( Table $table, string $name ) : void {}

	/**
	 * Move staged tables into place, replacing any table of the same name.
	 *
	 * @param array<string, string> $staged Final name => staging name.
	 * @return void
	 * @throws BackupException
	 */
	public function swap_in( array $staged ) : void {
		throw new BackupException( sprintf( '%s does not stage tables.', static::class ) );
	}

	/**
	 * The name a table is loaded under while staged.
	 *
	 * @param string $table
	 * @return string
	 */
	public function staging_name( string $table ) : string {
		return $table;
	}

	/**
	 * Most placeholders one statement may have.
	 *
	 * @return int
	 */
	public function max_placeholders() : int {
		return 65535;
	}

	/**
	 * Insert rows in one statement.
	 *
	 * @param string            $table
	 * @param string[]          $columns
	 * @param array<int, array> $rows Rows of bindable values, in column order.
	 * @return void
	 * @throws BackupException When the database refuses them.
	 */
	public function insert_rows( string $table, array $columns, array $rows ) : void {
		$placeholders = '(' . implode( ', ', array_fill( 0, count( $columns ), '?' ) ) . ')';
		$sql          = sprintf(
			'INSERT INTO %s (%s) VALUES %s',
			$this->quote( $table ),
			implode( ', ', array_map( [ $this, 'quote' ], $columns ) ),
			implode( ', ', array_fill( 0, count( $rows ), $placeholders ) )
		);

		$this->db->execute( $sql, array_merge( ...$rows ) );
		$this->throw_on_error( sprintf( 'Inserting rows into "%s" failed', $table ) );
	}

	/**
	 * Begin the restore transaction.
	 *
	 * @return void
	 * @throws BackupException
	 */
	public function begin() : void {
		$this->db->begin_transaction();
		$this->throw_on_error( 'Starting the restore transaction failed' );
		$this->after_begin();
	}

	/**
	 * Commit the restore transaction.
	 *
	 * @return void
	 * @throws BackupException
	 */
	public function commit() : void {
		$this->db->commit();
		$this->throw_on_error( 'Committing the restore failed' );
	}

	/**
	 * Roll back the restore transaction, without masking the failure that led here.
	 *
	 * @return void
	 */
	public function rollback() : void {
		try {
			$this->db->rollback();
		} catch ( Throwable $e ) {
			// The failure that led here is the one to report.
		}
	}

	/*
	|------------------------------
	| Helpers
	|------------------------------
	*/

	/**
	 * Quote an identifier for this engine.
	 *
	 * @param string $identifier
	 * @return string
	 */
	public function quote( string $identifier ) : string {
		return '"' . str_replace( '"', '""', $identifier ) . '"';
	}

	/**
	 * Throw unless a name can be written by the query builder on every engine.
	 *
	 * Restore creates tables with the query builder, which takes table,
	 * column and constraint names as plain names: letters, digits and
	 * underscores (see Identifier).
	 *
	 * @param string $name
	 * @param string $what What the name names, for the message.
	 * @return void
	 * @throws BackupException
	 */
	public static function assert_plain_name( string $name, string $what ) : void {
		if ( ! preg_match( Identifier::PATTERN, $name ) || in_array( strtoupper( $name ), [ 'NULL', 'TRUE', 'FALSE' ], true ) ) {
			throw new BackupException( sprintf(
				'Cannot restore %s "%s": names may contain letters, digits and underscores only.',
				$what,
				$name
			) );
		}
	}

	/**
	 * Record a warning.
	 *
	 * @param string $message
	 * @return void
	 */
	public function warn( string $message ) : void {
		$this->warnings[] = $message;
	}

	/**
	 * The warnings collected so far.
	 *
	 * @return string[]
	 */
	public function warnings() : array {
		return $this->warnings;
	}

	/**
	 * The schema inspector for this database.
	 *
	 * @return Inspector
	 */
	protected function inspector() : Inspector {
		return $this->inspector ??= new Inspector( $this->db );
	}

	/**
	 * Run a statement that returns no rows.
	 *
	 * @param string $sql
	 * @return void
	 * @throws BackupException When the database refuses it.
	 */
	protected function run( string $sql ) : void {
		if ( ! $this->db->exec( $sql ) ) {
			throw new BackupException( sprintf(
				'The database refused "%s": %s',
				static::shorten( $sql ),
				$this->db->get_last_error() ?? 'unknown error'
			) );
		}
	}

	/**
	 * Every row of a query, throwing when it fails.
	 *
	 * @param string $sql
	 * @param array  $params
	 * @return array<int, array<string, mixed>>
	 * @throws BackupException When the query fails.
	 */
	protected function rows( string $sql, array $params = [] ) : array {
		$rows = $this->db->get_results( $sql, $params );

		$this->throw_on_error( sprintf( 'Reading "%s" failed', static::shorten( $sql ) ) );

		return is_array( $rows ) ? array_map( fn( $row ) => array_change_key_case( (array) $row, CASE_LOWER ), $rows ) : [];
	}

	/**
	 * Throw when the last query recorded an error.
	 *
	 * @param string $context What was being done, for the message.
	 * @return void
	 * @throws BackupException
	 */
	protected function throw_on_error( string $context ) : void {
		$error = $this->db->get_last_error();

		if ( null !== $error && '' !== $error ) {
			throw new BackupException( $context . ': ' . $error );
		}
	}

	/**
	 * A column of a canonical type.
	 *
	 * @param string   $name
	 * @param int      $type      A ColumnType constant.
	 * @param int|null $length    For CHAR, STRING and BINARY.
	 * @param int|null $precision For DECIMAL.
	 * @param int|null $scale     For DECIMAL.
	 * @return Column
	 */
	protected static function column( string $name, int $type, ?int $length = null, ?int $precision = null, ?int $scale = null ) : Column {
		$column = Column::make( $name )->type( $type );

		if ( in_array( $type, [ ColumnType::CHAR, ColumnType::STRING, ColumnType::BINARY ], true ) && null !== $length && $length > 0 ) {
			$column->length = $length;
		}

		if ( ColumnType::DECIMAL === $type ) {
			$column->precision = $precision ?? 65;
			$column->scale     = $scale ?? ( null === $precision ? 30 : 0 );
		}

		return $column;
	}

	/**
	 * Normalise a table just read, the same way on every engine.
	 *
	 * Primary key columns are NOT NULL (SQLite reports an INTEGER PRIMARY KEY
	 * as nullable); indexes and foreign keys are listed by name, so the same
	 * schema always makes the same archive.
	 *
	 * @param Table $table
	 * @return Table
	 */
	protected static function normalized( Table $table ) : Table {
		$primary = [];
		$others  = [];
		$keys    = [];

		foreach ( $table->get_constraints() as $constraint ) {
			if ( 'primary' === $constraint->type ) {
				$primary[] = $constraint;
			} elseif ( 'foreign' === $constraint->type ) {
				$keys[] = $constraint;
			} else {
				$others[] = $constraint;
			}
		}

		$by_name = fn( Constraint $a, Constraint $b ) => strcmp( (string) $a->name, (string) $b->name ) ?: strcmp( implode( ',', $a->columns ), implode( ',', $b->columns ) );
		usort( $others, $by_name );
		usort( $keys, $by_name );

		$key_columns = array_merge( [], ...array_map( fn( Constraint $c ) => $c->columns, $primary ) );

		foreach ( $table->get_columns() as $column ) {
			if ( in_array( $column->name, $key_columns, true ) ) {
				$column->nullable = false;
			}
		}

		return Table::make( $table->get_name() )
			->add_columns( $table->get_columns() )
			->add_constraints( array_merge( $primary, $others, $keys ) );
	}

	/**
	 * Set a column's default from what the engine reported.
	 *
	 * A CURRENT_TIMESTAMP-like expression becomes the portable expression; any
	 * other expression is kept as a native default of this engine.
	 *
	 * @param Column                $column
	 * @param mixed                 $value         The default: a literal, or the expression text.
	 * @param bool                  $is_expression Whether $value is an expression.
	 * @param array<string, string> $native        Native defaults, by column, to add to.
	 * @return void
	 */
	protected function apply_default( Column $column, mixed $value, bool $is_expression, array &$native ) : void {
		if ( null === $value ) {
			return;
		}

		if ( ! $is_expression ) {
			$column->default = DefaultColumnValue::make( ValueCodec::typed_literal( $column, $value ) );
			return;
		}

		$portable = static::portable_expression( (string) $value );

		if ( null !== $portable ) {
			$column->default = DefaultColumnValue::expression( $portable );
			return;
		}

		$native[ $column->name ] = (string) $value;
	}

	/**
	 * The portable form of a date/time DEFAULT expression, or null for any other.
	 *
	 * now(), current_timestamp(), CURRENT_TIMESTAMP(6), LOCALTIMESTAMP and
	 * SQLite's (datetime('now')) are all CURRENT_TIMESTAMP.
	 *
	 * @param string $expression
	 * @return string|null
	 */
	protected static function portable_expression( string $expression ) : ?string {
		$text = strtolower( trim( $expression ) );

		while ( str_starts_with( $text, '(' ) && str_ends_with( $text, ')' ) ) {
			$text = trim( substr( $text, 1, -1 ) );
		}

		$text = preg_replace( '/\s+/', '', $text );

		return match ( true ) {
			(bool) preg_match( '/^(current_timestamp|now|localtimestamp|localtime)(\(\d*\))?$/', $text ),
			"datetime('now')" === $text, "datetime('now','localtime')" === $text  => 'CURRENT_TIMESTAMP',
			(bool) preg_match( '/^(current_date|curdate)(\(\))?$/', $text ),
			"date('now')" === $text                                               => 'CURRENT_DATE',
			(bool) preg_match( '/^(current_time|curtime)(\(\d*\))?$/', $text ),
			"time('now')" === $text                                               => 'CURRENT_TIME',
			default                                                               => null,
		};
	}

	/**
	 * Unquote a single-quoted SQL string literal, or return null when it is not one.
	 *
	 * @param string $text
	 * @return string|null
	 */
	protected static function unquote_literal( string $text ) : ?string {
		$text = trim( $text );

		if ( strlen( $text ) < 2 || "'" !== $text[0] || "'" !== substr( $text, -1 ) ) {
			return null;
		}

		return str_replace( "''", "'", substr( $text, 1, -1 ) );
	}

	/**
	 * A referential action as written in SQL, or null for the default (NO ACTION).
	 *
	 * @param string|null $action
	 * @return string|null
	 */
	protected static function referential_action( ?string $action ) : ?string {
		$action = strtoupper( trim( (string) $action ) );

		return in_array( $action, [ 'CASCADE', 'SET NULL', 'SET DEFAULT', 'RESTRICT' ], true ) ? $action : null;
	}

	/**
	 * The start of a statement, for messages.
	 *
	 * @param string $sql
	 * @return string
	 */
	protected static function shorten( string $sql ) : string {
		$sql = preg_replace( '/\s+/', ' ', trim( $sql ) );

		return strlen( $sql ) > 120 ? substr( $sql, 0, 117 ) . '...' : $sql;
	}

	/**
	 * Whether a value is bindable binary data (to close after an insert).
	 *
	 * @param mixed $value
	 * @return bool
	 */
	public static function is_stream( mixed $value ) : bool {
		return ParamType::is_stream( $value );
	}
}
