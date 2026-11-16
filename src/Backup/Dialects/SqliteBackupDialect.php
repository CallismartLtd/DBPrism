<?php
/**
 * SQLite backup dialect class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup\Dialects
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup\Dialects;

use Callismart\DBPrism\Backup\TableSchema;
use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\Table;

/**
 * Backup and restore on SQLite.
 *
 * Backup reads inside one read transaction, which SQLite keeps on a single
 * snapshot of the file (in WAL mode, writers carry on meanwhile; in rollback
 * journal mode they wait). Restore is one transaction, schema included, with
 * foreign keys checked once everything is loaded, so tables load in any order.
 *
 * SQLite stores what a column was declared as, not a type: the declared type
 * is mapped to a canonical type by name, falling back to SQLite's own
 * affinity rules for names it does not know.
 *
 * @since 2.6.0
 */
class SqliteBackupDialect extends AbstractBackupDialect {

	/**
	 * Whether foreign keys were enforced before the restore session.
	 *
	 * @var bool
	 */
	private bool $foreign_keys_were_on = false;

	/**
	 * {@inheritdoc}
	 */
	public function engine() : string {
		return 'sqlite';
	}

	/*
	|------------------------------
	| Backup
	|------------------------------
	*/

	/**
	 * {@inheritdoc}
	 *
	 * A deferred transaction takes its snapshot at its first read, so one is
	 * made straight away.
	 */
	public function begin_snapshot() : void {
		$this->run( 'BEGIN DEFERRED TRANSACTION' );

		$this->db->get_var( 'SELECT COUNT(*) FROM sqlite_master' );
		$this->throw_on_error( 'Opening the backup snapshot failed' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function read_table( string $table ) : TableSchema {
		$rows = $this->rows( 'SELECT * FROM pragma_table_info( ? )', [ $table ] );

		if ( empty( $rows ) ) {
			throw new BackupException( sprintf( 'Cannot read table "%s": it has no columns or does not exist.', $table ) );
		}

		$details    = $this->inspector()->get_column_details( $table );
		$definition = Table::make( $table );
		$native     = [];
		$primary    = [];

		foreach ( $rows as $row ) {
			if ( (int) $row['pk'] > 0 ) {
				$primary[ (int) $row['pk'] ] = (string) $row['name'];
			}
		}

		ksort( $primary );
		$primary = array_values( $primary );

		foreach ( $rows as $row ) {
			$name   = (string) $row['name'];
			$column = $this->column_of_declared_type( $table, $name, (string) $row['type'] );

			$column->nullable = 0 === (int) $row['notnull'];

			// AUTOINCREMENT, or a single INTEGER PRIMARY KEY, which is the rowid:
			// either way the database assigns its value when none is given.
			$column->auto_increment = ! empty( $details[ $name ]['auto_increment'] )
				|| ( [ $name ] === $primary && 'INTEGER' === strtoupper( trim( (string) $row['type'] ) ) );

			if ( $column->auto_increment && ColumnType::BIG_INT !== $column->type ) {
				$column->type = ColumnType::BIG_INT;
			}

			if ( null !== $row['dflt_value'] && ! $column->auto_increment ) {
				[ $value, $is_expression ] = self::parse_default( (string) $row['dflt_value'] );
				$this->apply_default( $column, $value, $is_expression, $native );
			}

			$definition->add_column( $column );
		}

		if ( ! empty( $primary ) ) {
			$definition->add_constraint( Constraint::primary()->on( ...$primary ) );
		}

		$this->add_indexes( $definition );
		$this->add_foreign_keys( $definition );

		return new TableSchema( self::normalized( $definition ), $this->engine(), $native );
	}

	/**
	 * A column of a declared SQLite type.
	 *
	 * Known names map to their canonical type, with their size. INTEGER maps
	 * to BIG_INT, since an SQLite INTEGER holds 64 bits (and is what the query
	 * builder writes for every integer type). Unknown names follow SQLite's
	 * affinity rules (https://www.sqlite.org/datatype3.html#affinity_name_examples).
	 *
	 * @param string $table
	 * @param string $name
	 * @param string $declared
	 * @return Column
	 */
	protected function column_of_declared_type( string $table, string $name, string $declared ) : Column {
		$declared = strtoupper( trim( $declared ) );
		$base     = trim( (string) preg_replace( '/\(.*$/', '', $declared ) );
		$args     = preg_match( '/\(\s*(\d+)\s*(?:,\s*(\d+)\s*)?\)/', $declared, $match )
			? [ (int) $match[1], isset( $match[2] ) ? (int) $match[2] : null ]
			: [ null, null ];

		$column = match ( $base ) {
			'BOOLEAN', 'BOOL'                         => self::column( $name, ColumnType::BOOLEAN ),
			'TINYINT'                                 => 1 === $args[0] ? self::column( $name, ColumnType::BOOLEAN ) : self::column( $name, ColumnType::SMALL_INT ),
			'SMALLINT', 'INT2'                        => self::column( $name, ColumnType::SMALL_INT ),
			'INT', 'MEDIUMINT', 'INT4'                => self::column( $name, ColumnType::INTEGER ),
			'INTEGER', 'BIGINT', 'INT8',
			'UNSIGNED BIG INT'                        => self::column( $name, ColumnType::BIG_INT ),
			'DECIMAL', 'NUMERIC'                      => self::column( $name, ColumnType::DECIMAL, null, $args[0], $args[1] ),
			'FLOAT'                                   => self::column( $name, ColumnType::FLOAT ),
			'REAL', 'DOUBLE', 'DOUBLE PRECISION'      => self::column( $name, ColumnType::DOUBLE ),
			'CHAR', 'CHARACTER', 'NCHAR'              => null === $args[0] ? self::column( $name, ColumnType::LONG_TEXT ) : self::column( $name, ColumnType::CHAR, $args[0] ),
			'VARCHAR', 'VARYING CHARACTER', 'NVARCHAR',
			'NATIVE CHARACTER', 'CHARACTER VARYING'   => null === $args[0] ? self::column( $name, ColumnType::LONG_TEXT ) : self::column( $name, ColumnType::STRING, $args[0] ),
			// The query builder writes STRING and CHAR as TEXT(length).
			'TEXT'                                    => null === $args[0] ? self::column( $name, ColumnType::LONG_TEXT ) : self::column( $name, ColumnType::STRING, $args[0] ),
			'CLOB', 'LONGTEXT', 'MEDIUMTEXT'          => self::column( $name, ColumnType::LONG_TEXT ),
			'TINYTEXT'                                => self::column( $name, ColumnType::TEXT ),
			'BINARY'                                  => self::column( $name, ColumnType::BINARY, $args[0] ),
			'BLOB', 'LONGBLOB', 'MEDIUMBLOB', 'BYTEA' => self::column( $name, ColumnType::LONG_BLOB ),
			'DATE'                                    => self::column( $name, ColumnType::DATE ),
			'TIME'                                    => self::column( $name, ColumnType::TIME ),
			'DATETIME'                                => self::column( $name, ColumnType::DATETIME ),
			'TIMESTAMP'                               => self::column( $name, ColumnType::TIMESTAMP ),
			'JSON', 'JSONB'                           => self::column( $name, ColumnType::JSON ),
			'UUID'                                    => self::column( $name, ColumnType::UUID ),
			default                                   => null,
		};

		if ( null !== $column ) {
			return $column;
		}

		// SQLite's affinity rules, in its order.
		$affinity = match ( true ) {
			str_contains( $base, 'INT' )                                   => ColumnType::BIG_INT,
			str_contains( $base, 'CHAR' ), str_contains( $base, 'CLOB' ),
			str_contains( $base, 'TEXT' )                                  => ColumnType::LONG_TEXT,
			'' === $base, str_contains( $base, 'BLOB' )                    => ColumnType::LONG_BLOB,
			str_contains( $base, 'REAL' ), str_contains( $base, 'FLOA' ),
			str_contains( $base, 'DOUB' )                                  => ColumnType::DOUBLE,
			default                                                        => ColumnType::DECIMAL,
		};

		if ( '' === $base ) {
			$this->warn( sprintf( '%s.%s: declared without a type; restored as binary data, as SQLite treats it.', $table, $name ) );
		} else {
			$this->warn( sprintf( '%s.%s: type %s mapped by SQLite affinity to %s.', $table, $name, $declared, TableSchema::TYPE_NAMES[ $affinity ] ) );
		}

		return self::column( $name, $affinity );
	}

	/**
	 * A column default as SQLite stores it (the SQL text), as [value, is_expression].
	 *
	 * @param string $default
	 * @return array{0: mixed, 1: bool}
	 */
	protected static function parse_default( string $default ) : array {
		$text    = trim( $default );
		$literal = self::unquote_literal( $text );

		return match ( true ) {
			null !== $literal                                   => [ $literal, false ],
			0 === strcasecmp( $text, 'NULL' )                   => [ null, false ],
			is_numeric( $text )                                 => [ $text, false ],
			in_array( strtolower( $text ), [ 'true', 'false' ], true ) => [ strtolower( $text ), false ],
			default                                             => [ $text, true ],
		};
	}

	/**
	 * Add the indexes and UNIQUE constraints.
	 *
	 * The automatic names SQLite gives unnamed UNIQUE constraints
	 * (sqlite_autoindex_*) cannot be created by name, so they are left unnamed.
	 *
	 * @param Table $definition
	 * @return void
	 */
	protected function add_indexes( Table $definition ) : void {
		foreach ( $this->inspector()->get_indexes( $definition->get_name() ) as $name => $index ) {
			$name       = str_starts_with( (string) $name, 'sqlite_' ) ? null : (string) $name;
			$constraint = $index['unique'] ? Constraint::unique( $name ) : Constraint::index( $name );

			$definition->add_constraint( $constraint->on( ...$index['columns'] ) );
		}
	}

	/**
	 * Add the foreign keys, with their actions.
	 *
	 * Names come from Inspector (the CONSTRAINT name in the stored CREATE
	 * TABLE); a key declared without one is left unnamed rather than given
	 * Inspector's fk_<id>, which would collide across tables on engines that
	 * name foreign keys per database.
	 *
	 * @param Table $definition
	 * @return void
	 */
	protected function add_foreign_keys( Table $definition ) : void {
		$table   = $definition->get_name();
		$grouped = [];

		foreach ( $this->rows( 'SELECT * FROM pragma_foreign_key_list( ? ) ORDER BY id, seq', [ $table ] ) as $row ) {
			$grouped[ (int) $row['id'] ]['columns'][] = (string) $row['from'];
			$grouped[ (int) $row['id'] ]['table']     = (string) $row['table'];
			$grouped[ (int) $row['id'] ]['actions']   = [ $row['on_delete'], $row['on_update'] ];
		}

		$actions = [];

		foreach ( $grouped as $key ) {
			$actions[ self::key_signature( $key['columns'], $key['table'] ) ] = $key['actions'];
		}

		foreach ( $this->inspector()->get_foreign_keys( $table ) as $name => $key ) {
			$name    = preg_match( '/^fk_\d+$/', (string) $name ) ? null : (string) $name;
			$foreign = Constraint::foreign_key( $name )
				->on( ...$key['columns'] )
				->references( (string) $key['referenced_table'], ...$key['referenced_columns'] );

			[ $on_delete, $on_update ] = $actions[ self::key_signature( $key['columns'], (string) $key['referenced_table'] ) ] ?? [ null, null ];

			$on_delete = self::referential_action( $on_delete );
			$on_update = self::referential_action( $on_update );

			if ( null !== $on_delete ) {
				$foreign->on_delete( $on_delete );
			}

			if ( null !== $on_update ) {
				$foreign->on_update( $on_update );
			}

			$definition->add_constraint( $foreign );
		}
	}

	/**
	 * Identify a foreign key by its columns and referenced table, ignoring case as SQLite does.
	 *
	 * @param string[] $columns
	 * @param string   $references
	 * @return string
	 */
	private static function key_signature( array $columns, string $references ) : string {
		return strtolower( implode( ',', $columns ) . '>' . $references );
	}

	/*
	|------------------------------
	| Restore
	|------------------------------
	*/

	/**
	 * {@inheritdoc}
	 *
	 * SQLite cannot add a foreign key to an existing table.
	 */
	public function inline_foreign_keys() : bool {
		return true;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Foreign key enforcement is switched off for the restore (it can only be
	 * switched outside a transaction): with it on, replacing a table would run
	 * the ON DELETE actions of other tables' keys, deleting their rows. The
	 * restored rows are checked against every foreign key before the restore
	 * commits instead; see before_commit().
	 */
	public function begin_restore_session() : void {
		$this->foreign_keys_were_on = 1 === (int) $this->db->get_var( 'PRAGMA foreign_keys' );
		$this->throw_on_error( 'Reading the foreign key setting failed' );

		$this->run( 'PRAGMA foreign_keys = OFF' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function end_restore_session() : void {
		if ( $this->foreign_keys_were_on ) {
			$this->db->exec( 'PRAGMA foreign_keys = ON' );
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * Every restored row must satisfy the foreign keys of its table.
	 */
	public function before_commit( array $tables ) : void {
		foreach ( $tables as $table ) {
			$violations = $this->rows( 'PRAGMA foreign_key_check( ' . $this->quote( $table ) . ' )' );

			if ( ! empty( $violations ) ) {
				throw new BackupException( sprintf(
					'Table "%s" has %d row(s) whose foreign key to "%s" matches no row.',
					$table,
					count( $violations ),
					$violations[0]['parent'] ?? '?'
				) );
			}
		}
	}

	/**
	 * {@inheritdoc}
	 *
	 * An auto-increment column is SQLite's INTEGER PRIMARY KEY, so it must be
	 * the whole primary key.
	 */
	protected function adapt_table( Table $table ) : Table {
		$primary = [];

		foreach ( $table->get_constraints() as $constraint ) {
			if ( 'primary' === $constraint->type ) {
				$primary = $constraint->columns;
			}
		}

		foreach ( $table->get_columns() as $column ) {
			if ( $column->auto_increment && ! empty( $primary ) && [ $column->name ] !== $primary ) {
				$column->auto_increment = false;
				$this->warn( sprintf( '%s.%s: SQLite cannot auto-increment a column that is not the whole primary key; it was created as a plain column.', $table->get_name(), $column->name ) );
			}
		}

		return $table;
	}

	/**
	 * {@inheritdoc}
	 *
	 * SQLite's default limit (SQLITE_MAX_VARIABLE_NUMBER) before 3.32.
	 */
	public function max_placeholders() : int {
		return 999;
	}
}
