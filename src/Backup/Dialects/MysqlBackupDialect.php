<?php
/**
 * MySQL backup dialect class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup\Dialects
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup\Dialects;

use Callismart\DBPrism\Backup\TableSchema;
use Callismart\DBPrism\Backup\ValueCodec;
use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\DefaultColumnValue;
use Callismart\DBPrism\Utils\Table;

/**
 * Backup and restore on MySQL and MariaDB.
 *
 * Backup reads inside START TRANSACTION WITH CONSISTENT SNAPSHOT, which
 * covers InnoDB tables (a warning names any table on another storage engine).
 *
 * MySQL commits implicitly on CREATE, DROP and RENAME TABLE, so a restore
 * cannot be one transaction. Instead each table is created under a staging
 * name and loaded in one transaction; only when every row is in are the
 * tables moved into place with a single RENAME TABLE, which MySQL performs
 * atomically. Until then the database's own tables are untouched, and a
 * failed restore drops what it staged.
 *
 * @since 2.6.0
 */
class MysqlBackupDialect extends AbstractBackupDialect {

	/**
	 * Prefix of staging tables.
	 */
	public const STAGING_PREFIX = 'dbprism_rs_';

	/**
	 * Prefix tables being replaced are renamed to before they are dropped.
	 */
	public const RETIRED_PREFIX = 'dbprism_rt_';

	/**
	 * The DATA_TYPEs read_column() maps to a canonical type; any other is stored as text.
	 */
	private const KNOWN_TYPES = [
		'tinyint', 'bool', 'boolean', 'smallint', 'year', 'mediumint', 'int', 'integer', 'bigint',
		'decimal', 'numeric', 'float', 'double', 'real',
		'char', 'varchar', 'tinytext', 'text', 'mediumtext', 'longtext',
		'binary', 'varbinary', 'tinyblob', 'blob', 'mediumblob', 'longblob',
		'date', 'time', 'datetime', 'timestamp', 'json', 'uuid', 'enum', 'set',
	];

	/**
	 * MySQL's limit on identifier length.
	 */
	private const MAX_NAME = 64;

	/**
	 * Session settings to put back after a restore.
	 *
	 * @var array{foreign_key_checks: string, sql_mode: string}|null
	 */
	private ?array $saved_session = null;

	/**
	 * Whether the server is MariaDB, once known.
	 *
	 * @var bool|null
	 */
	private ?bool $mariadb = null;

	/**
	 * {@inheritdoc}
	 */
	public function engine() : string {
		return 'mysql';
	}

	/**
	 * {@inheritdoc}
	 */
	public function quote( string $identifier ) : string {
		return '`' . str_replace( '`', '``', $identifier ) . '`';
	}

	/*
	|------------------------------
	| Backup
	|------------------------------
	*/

	/**
	 * {@inheritdoc}
	 */
	public function begin_snapshot() : void {
		$this->run( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ' );
		$this->run( 'START TRANSACTION WITH CONSISTENT SNAPSHOT, READ ONLY' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Only InnoDB (and other transactional engines) honour the snapshot.
	 */
	public function check_snapshot_coverage( array $tables ) : void {
		$rows = $this->rows(
			"SELECT TABLE_NAME AS name, ENGINE AS engine FROM INFORMATION_SCHEMA.TABLES
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
		);

		foreach ( $rows as $row ) {
			$engine = strtolower( (string) $row['engine'] );

			if ( in_array( $row['name'], $tables, true ) && ! in_array( $engine, [ 'innodb', 'xtradb', 'ndbcluster', 'rocksdb' ], true ) ) {
				$this->warn( sprintf(
					'%s uses the %s storage engine, which has no transactions: it was read as it was at the time, not as part of the snapshot.',
					$row['name'],
					$row['engine']
				) );
			}
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function read_table( string $table ) : TableSchema {
		$rows = $this->rows(
			'SELECT COLUMN_NAME AS name, DATA_TYPE AS data_type, COLUMN_TYPE AS column_type,
				IS_NULLABLE AS is_nullable, COLUMN_DEFAULT AS column_default, EXTRA AS extra,
				CHARACTER_MAXIMUM_LENGTH AS char_length, NUMERIC_PRECISION AS num_precision,
				NUMERIC_SCALE AS num_scale, COLUMN_COMMENT AS comment
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
			ORDER BY ORDINAL_POSITION',
			[ $table ]
		);

		if ( empty( $rows ) ) {
			throw new BackupException( sprintf( 'Cannot read table "%s": it has no columns or does not exist.', $table ) );
		}

		$definition = Table::make( $table );
		$native     = [];

		foreach ( $rows as $row ) {
			$definition->add_column( $this->read_column( $table, $row, $native ) );
		}

		$this->add_keys( $definition );

		$checks = [];

		foreach ( $this->inspector()->get_check_constraints( $table ) as $name => $check ) {
			$checks[] = [ 'name' => (string) $name, 'definition' => (string) $check['definition'] ];
		}

		return new TableSchema( self::normalized( $definition ), $this->engine(), $native, $checks );
	}

	/**
	 * One column from INFORMATION_SCHEMA.COLUMNS.
	 *
	 * @param string                $table
	 * @param array<string, mixed>  $row
	 * @param array<string, string> $native Native defaults, to add to.
	 * @return Column
	 */
	protected function read_column( string $table, array $row, array &$native ) : Column {
		$name        = (string) $row['name'];
		$data_type   = strtolower( (string) $row['data_type'] );
		$column_type = strtolower( (string) $row['column_type'] );
		$extra       = strtolower( (string) $row['extra'] );
		$length      = null === $row['char_length'] ? null : (int) $row['char_length'];
		$precision   = null === $row['num_precision'] ? null : (int) $row['num_precision'];
		$scale       = null === $row['num_scale'] ? null : (int) $row['num_scale'];

		$column = match ( $data_type ) {
			'tinyint'                         => str_starts_with( $column_type, 'tinyint(1)' )
				? self::column( $name, ColumnType::BOOLEAN )
				: self::column( $name, ColumnType::SMALL_INT ),
			'bool', 'boolean'                 => self::column( $name, ColumnType::BOOLEAN ),
			'smallint', 'year'                => self::column( $name, ColumnType::SMALL_INT ),
			'mediumint', 'int', 'integer'     => self::column( $name, ColumnType::INTEGER ),
			'bigint'                          => self::column( $name, ColumnType::BIG_INT ),
			'decimal', 'numeric'              => self::column( $name, ColumnType::DECIMAL, null, $precision, $scale ),
			'float'                           => self::column( $name, ColumnType::FLOAT ),
			'double', 'real'                  => self::column( $name, ColumnType::DOUBLE ),
			'char'                            => self::column( $name, ColumnType::CHAR, $length ),
			'varchar'                         => self::column( $name, ColumnType::STRING, $length ),
			'tinytext', 'text'                => self::column( $name, ColumnType::TEXT ),
			'mediumtext', 'longtext'          => self::column( $name, ColumnType::LONG_TEXT ),
			'binary'                          => self::column( $name, ColumnType::BINARY, $length ),
			'varbinary', 'tinyblob', 'blob'   => self::column( $name, ColumnType::BLOB ),
			'mediumblob', 'longblob'          => self::column( $name, ColumnType::LONG_BLOB ),
			'date'                            => self::column( $name, ColumnType::DATE ),
			'time'                            => self::column( $name, ColumnType::TIME ),
			'datetime'                        => self::column( $name, ColumnType::DATETIME ),
			'timestamp'                       => self::column( $name, ColumnType::TIMESTAMP ),
			'json'                            => self::column( $name, ColumnType::JSON ),
			'uuid'                            => self::column( $name, ColumnType::UUID ),
			'enum', 'set'                     => self::column( $name, ColumnType::STRING, max( 255, (int) $length ) ),
			default                           => self::column( $name, ColumnType::LONG_TEXT ),
		};

		if ( in_array( $data_type, [ 'enum', 'set' ], true ) ) {
			$this->warn( sprintf( '%s.%s: %s is stored as a string; its list of allowed values is not kept.', $table, $name, $column_type ) );
		} elseif ( ! in_array( $data_type, self::KNOWN_TYPES, true ) ) {
			$this->warn( sprintf( '%s.%s: type %s has no portable equivalent and is stored as text.', $table, $name, $column_type ) );
		}

		$column->unsigned       = str_contains( $column_type, 'unsigned' ) && ( ValueCodec::is_integer( $column ) || ColumnType::DECIMAL === $column->type );
		$column->nullable       = 'YES' === strtoupper( (string) $row['is_nullable'] );
		$column->auto_increment = str_contains( $extra, 'auto_increment' );
		$column->comment        = (string) ( $row['comment'] ?? '' );

		if ( str_contains( $extra, 'on update' ) ) {
			$this->warn( sprintf( '%s.%s: ON UPDATE %s is not kept.', $table, $name, strtoupper( trim( substr( $extra, strpos( $extra, 'on update' ) + 9 ) ) ) ) );
		}

		if ( str_contains( $extra, 'generated' ) && ! str_contains( $extra, 'default_generated' ) ) {
			$this->warn( sprintf( '%s.%s: a generated column; its values are kept, as a plain column.', $table, $name ) );
		}

		[ $default, $is_expression ] = $this->read_default( $row['column_default'], $extra, $data_type );

		if ( ! $column->auto_increment ) {
			$this->apply_default( $column, $default, $is_expression, $native );
		}

		return $column;
	}

	/**
	 * A column default as [value, is_expression], the same on MySQL and MariaDB.
	 *
	 * MariaDB 10.2.7+ reports a literal quoted ('x'), no default as the text
	 * NULL, and an expression bare. MySQL reports a literal bare and marks an
	 * expression with DEFAULT_GENERATED in EXTRA (8.0); earlier versions
	 * report CURRENT_TIMESTAMP bare.
	 *
	 * @param mixed  $default   COLUMN_DEFAULT.
	 * @param string $extra     EXTRA, lowercase.
	 * @param string $data_type DATA_TYPE, lowercase.
	 * @return array{0: mixed, 1: bool}
	 */
	protected function read_default( mixed $default, string $extra, string $data_type ) : array {
		if ( null === $default ) {
			return [ null, false ];
		}

		$default = (string) $default;

		if ( $this->is_mariadb() ) {
			if ( 'NULL' === $default ) {
				return [ null, false ];
			}

			$literal = self::unquote_literal( $default );

			if ( null !== $literal ) {
				return [ str_replace( '\\\\', '\\', $literal ), false ];
			}

			return is_numeric( $default ) ? [ $default, false ] : [ $default, true ];
		}

		if ( str_contains( $extra, 'default_generated' ) ) {
			return [ $default, true ];
		}

		if ( in_array( $data_type, [ 'timestamp', 'datetime' ], true ) && null !== self::portable_expression( $default ) ) {
			return [ $default, true ];
		}

		return [ $default, false ];
	}

	/**
	 * Add the primary key, indexes and foreign keys.
	 *
	 * @param Table $definition
	 * @return void
	 */
	protected function add_keys( Table $definition ) : void {
		$table   = $definition->get_name();
		$primary = $this->inspector()->get_primary_key( $table );

		if ( ! empty( $primary ) ) {
			$definition->add_constraint( Constraint::primary()->on( ...$primary ) );
		}

		$keys = $this->foreign_key_rows( 'k.TABLE_NAME = ?', [ $table ] )[ $table ] ?? [];

		// InnoDB creates an index for each foreign key that has none, named
		// after it; it is made again when the key is, so it is not listed.
		foreach ( $this->inspector()->get_indexes( $table ) as $name => $index ) {
			if ( ! $index['unique'] && isset( $keys[ $name ] ) && $keys[ $name ]['columns'] === $index['columns'] ) {
				continue;
			}

			$definition->add_constraint(
				( $index['unique'] ? Constraint::unique( (string) $name ) : Constraint::index( (string) $name ) )->on( ...$index['columns'] )
			);
		}

		foreach ( $keys as $name => $key ) {
			$definition->add_constraint( self::foreign_key_of( (string) $name, $key ) );
		}
	}

	/**
	 * Foreign keys of the current database matching a condition, by table and name.
	 *
	 * @param string $where  Condition on KEY_COLUMN_USAGE (aliased k).
	 * @param array  $params Its bound values.
	 * @return array<string, array<string, array{columns: string[], references: string[], table: string, on_update: string, on_delete: string}>>
	 */
	protected function foreign_key_rows( string $where, array $params ) : array {
		$rows = $this->rows(
			"SELECT k.TABLE_NAME AS table_name, k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS column_name,
				k.REFERENCED_TABLE_NAME AS referenced_table, k.REFERENCED_COLUMN_NAME AS referenced_column,
				r.UPDATE_RULE AS on_update, r.DELETE_RULE AS on_delete
			FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE k
			JOIN INFORMATION_SCHEMA.REFERENTIAL_CONSTRAINTS r
				ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME AND r.TABLE_NAME = k.TABLE_NAME
			WHERE k.TABLE_SCHEMA = DATABASE() AND k.REFERENCED_TABLE_NAME IS NOT NULL AND ( {$where} )
			ORDER BY k.TABLE_NAME, k.CONSTRAINT_NAME, k.ORDINAL_POSITION",
			$params
		);

		$keys = [];

		foreach ( $rows as $row ) {
			$key = &$keys[ $row['table_name'] ][ $row['name'] ];

			$key['columns'][]    = $row['column_name'];
			$key['references'][] = $row['referenced_column'];
			$key['table']        = $row['referenced_table'];
			$key['on_update']    = $row['on_update'];
			$key['on_delete']    = $row['on_delete'];

			unset( $key );
		}

		return $keys;
	}

	/**
	 * A foreign key constraint from foreign_key_rows().
	 *
	 * @param string $name
	 * @param array  $key
	 * @return Constraint
	 */
	protected static function foreign_key_of( string $name, array $key ) : Constraint {
		$foreign = Constraint::foreign_key( $name )
			->on( ...$key['columns'] )
			->references( (string) $key['table'], ...$key['references'] );

		self::set_actions( $foreign, $key['on_delete'], $key['on_update'] );

		return $foreign;
	}

	/*
	|------------------------------
	| Restore
	|------------------------------
	*/

	/**
	 * {@inheritdoc}
	 */
	public function transactional_ddl() : bool {
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function uses_staging() : bool {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Foreign key checks are off while loading, so tables load in any order;
	 * the data comes from a consistent snapshot. NO_AUTO_VALUE_ON_ZERO keeps
	 * a stored 0 in an AUTO_INCREMENT column instead of generating a new ID.
	 */
	public function begin_restore_session() : void {
		$row = $this->db->get_row( 'SELECT @@SESSION.foreign_key_checks AS fk, @@SESSION.sql_mode AS sql_mode' );
		$this->throw_on_error( 'Reading the session settings failed' );

		$this->saved_session = [
			'foreign_key_checks' => (string) ( $row['fk'] ?? '1' ),
			'sql_mode'           => (string) ( $row['sql_mode'] ?? '' ),
		];

		$this->run( 'SET SESSION foreign_key_checks = 0' );
		$this->run( "SET SESSION sql_mode = CONCAT_WS(',', NULLIF(@@SESSION.sql_mode, ''), 'NO_AUTO_VALUE_ON_ZERO')" );
	}

	/**
	 * {@inheritdoc}
	 */
	public function end_restore_session() : void {
		if ( null === $this->saved_session ) {
			return;
		}

		$saved               = $this->saved_session;
		$this->saved_session = null;

		$checks   = '0' === $saved['foreign_key_checks'] ? '0' : '1';
		$sql_mode = preg_match( '/^[A-Za-z_,]*$/', $saved['sql_mode'] ) ? $saved['sql_mode'] : '';

		$this->db->exec( "SET SESSION foreign_key_checks = {$checks}" );
		$this->db->exec( "SET SESSION sql_mode = '{$sql_mode}'" );
	}

	/**
	 * {@inheritdoc}
	 *
	 * MySQL has no unbounded text type that can be indexed without a prefix
	 * length, so a TEXT column in a key becomes a VARCHAR. Strict mode (the
	 * default) then refuses a longer value rather than truncating it.
	 */
	protected function adapt_table( Table $table ) : Table {
		$keyed = [];

		foreach ( $table->get_constraints() as $constraint ) {
			if ( 'foreign' === $constraint->type ) {
				continue;
			}

			foreach ( $constraint->columns as $column ) {
				$keyed[ $column ] = max( $keyed[ $column ] ?? 0, count( $constraint->columns ) );
			}
		}

		foreach ( $table->get_columns() as $column ) {
			if ( isset( $keyed[ $column->name ] ) && ValueCodec::is_long_text( $column ) ) {
				$length         = max( 191, intdiv( 768, $keyed[ $column->name ] ) );
				$column->type   = ColumnType::STRING;
				$column->length = $length;

				$this->warn( sprintf( '%s.%s: an indexed text column, created as VARCHAR(%d) because MySQL cannot index unbounded text.', $table->get_name(), $column->name, $length ) );
			}
		}

		return $table;
	}

	/**
	 * {@inheritdoc}
	 *
	 * MySQL (not MariaDB) accepts a DEFAULT on TEXT, BLOB and JSON columns only
	 * when it is written as an expression, in parentheses (MySQL 8.0.13+):
	 * DEFAULT ('new'), DEFAULT (CURRENT_TIMESTAMP).
	 */
	protected function adapt_column( TableSchema $schema, Column $column ) : Column {
		$expression_only = in_array( $column->type, [ ColumnType::TEXT, ColumnType::LONG_TEXT, ColumnType::BLOB, ColumnType::LONG_BLOB, ColumnType::JSON ], true );

		if ( ! $expression_only || ! $column->default instanceof DefaultColumnValue || $this->is_mariadb() ) {
			return $column;
		}

		$default = $column->default;

		if ( $default->is_expression() ) {
			$column->default = DefaultColumnValue::expression( '(' . $default . ')' );
		} elseif ( ValueCodec::is_binary( $column ) ) {
			$this->warn( sprintf( '%s.%s: MySQL does not allow a literal default on a binary column; the default was not restored.', $schema->name(), $column->name ) );
			$column->default = null;
		} else {
			$value = $default->value();
			$sql   = match ( true ) {
				is_bool( $value )                   => $value ? '1' : '0',
				is_int( $value ), is_float( $value ) => (string) $value,
				default                             => "'" . str_replace( [ '\\', "'" ], [ '\\\\', "''" ], (string) $value ) . "'",
			};

			$column->default = DefaultColumnValue::expression( '(' . $sql . ')' );
		}

		return $column;
	}

	/**
	 * {@inheritdoc}
	 */
	public function staging_name( string $table ) : string {
		return self::prefixed( self::STAGING_PREFIX, $table );
	}

	/**
	 * {@inheritdoc}
	 *
	 * One RENAME TABLE moves every staged table into place, and the tables
	 * they replace out of the way, atomically. The replaced tables are dropped.
	 *
	 * MySQL points a foreign key at a table's new name when the table is
	 * renamed, so a table outside the restore whose foreign key references a
	 * replaced table would follow it out of the way. Such keys are pointed
	 * back at the restored table, under their own name and actions.
	 */
	public function swap_in( array $staged ) : void {
		$renames  = [];
		$retired  = [];
		$replaced = [];

		foreach ( $staged as $final => $staging ) {
			if ( $this->table_exists( $final ) ) {
				$replaced[] = $final;
				$retired[]  = self::prefixed( self::RETIRED_PREFIX, $final );
				$renames[]  = $this->quote( $final ) . ' TO ' . $this->quote( end( $retired ) );
			}

			$renames[] = $this->quote( $staging ) . ' TO ' . $this->quote( $final );
		}

		if ( empty( $renames ) ) {
			return;
		}

		$outside = [];

		if ( ! empty( $replaced ) ) {
			$finals  = array_keys( $staged );
			$outside = $this->foreign_key_rows(
				sprintf(
					'k.REFERENCED_TABLE_NAME IN (%s) AND k.TABLE_NAME NOT IN (%s)',
					implode( ', ', array_fill( 0, count( $replaced ), '?' ) ),
					implode( ', ', array_fill( 0, count( $finals ), '?' ) )
				),
				array_merge( $replaced, $finals )
			);
		}

		$this->drop_tables( array_map( fn( string $final ) => self::prefixed( self::RETIRED_PREFIX, $final ), array_keys( $staged ) ) );
		$this->run( 'RENAME TABLE ' . implode( ', ', $renames ) );

		foreach ( $outside as $table => $keys ) {
			foreach ( $keys as $name => $key ) {
				$this->run( sprintf( 'ALTER TABLE %s DROP FOREIGN KEY %s', $this->quote( (string) $table ), $this->quote( (string) $name ) ) );
				$this->add_foreign_key( (string) $table, self::foreign_key_of( (string) $name, $key ) );
			}
		}

		$this->drop_tables( $retired );
	}

	/**
	 * {@inheritdoc}
	 */
	public function drop_tables( array $tables ) : void {
		if ( empty( $tables ) ) {
			return;
		}

		$this->run( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( [ $this, 'quote' ], $tables ) ) );
	}

	/*
	|------------------------------
	| Helpers
	|------------------------------
	*/

	/**
	 * Set a foreign key's actions from INFORMATION_SCHEMA rules.
	 *
	 * @param Constraint  $foreign
	 * @param string|null $on_delete
	 * @param string|null $on_update
	 * @return void
	 */
	protected static function set_actions( Constraint $foreign, ?string $on_delete, ?string $on_update ) : void {
		// InnoDB reports a key declared without actions as RESTRICT, which it
		// treats exactly as NO ACTION: both are left as the default.
		$on_delete = 'RESTRICT' === strtoupper( (string) $on_delete ) ? null : self::referential_action( $on_delete );
		$on_update = 'RESTRICT' === strtoupper( (string) $on_update ) ? null : self::referential_action( $on_update );

		if ( null !== $on_delete ) {
			$foreign->on_delete( $on_delete );
		}

		if ( null !== $on_update ) {
			$foreign->on_update( $on_update );
		}
	}

	/**
	 * Whether the server is MariaDB.
	 *
	 * @return bool
	 */
	protected function is_mariadb() : bool {
		return $this->mariadb ??= false !== stripos( (string) $this->db->get_var( 'SELECT VERSION()' ), 'mariadb' );
	}

	/**
	 * A prefixed table name within MySQL's length limit.
	 *
	 * @param string $prefix
	 * @param string $table
	 * @return string
	 */
	private static function prefixed( string $prefix, string $table ) : string {
		$name = $prefix . $table;

		if ( strlen( $name ) <= self::MAX_NAME ) {
			return $name;
		}

		return $prefix . substr( md5( $table ), 0, self::MAX_NAME - strlen( $prefix ) );
	}
}
