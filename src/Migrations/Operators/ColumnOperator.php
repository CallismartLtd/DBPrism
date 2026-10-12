<?php
/**
 * Column operator class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations\Operators
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\Operators;

use Callismart\DBPrism\Exceptions\MigrationException;
use Callismart\DBPrism\Migrations\SQLite\TableDefinition;
use Callismart\DBPrism\Migrations\SQLite\TableRebuilder;
use Callismart\DBPrism\Utils\Column;
use InvalidArgumentException;

/**
 * Column operations on one table.
 *
 *   $this->column( 'licenses' )->add( Column::make( 'notes' )->type( ColumnType::TEXT ) );
 *   $this->column( 'licenses' )->rename( 'notes', 'admin_notes' );
 *   $this->column( 'licenses' )->modify( Column::make( 'id' )->type( ColumnType::BIG_INT )->required() );
 *   $this->column( 'licenses' )->drop( 'admin_notes' );
 *
 * A column is known by its name. add(), rename() and drop() check the
 * schema first and do nothing when their change is already there.
 * modify() needs no check: it sets the column's whole definition, so
 * running it again gives the same column.
 *
 * No change loses data on its way through. When a stored value cannot be
 * carried into a modified column unchanged (a string too long for the new
 * length, text in a numeric column, a number out of range, NULL in a NOT
 * NULL column), the change is refused and the table is left as it was. To
 * lose data on purpose, change the data first (e.g. shorten the strings
 * with an UPDATE), then the column. The one conversion that is not refused
 * is rounding to a declared scale (DECIMAL(10,4) to DECIMAL(10,2)), which
 * every engine performs the same way, and which the new definition states.
 *
 * Constraints and indexes are managed with the constraint and index
 * operators; a column's definition here is its type, nullability, default,
 * comment and auto-increment.
 *
 * On SQLite, whose ALTER TABLE cannot change a column, a column is
 * modified by rebuilding the table (see TableRebuilder). A column is added
 * or dropped in place when SQLite's ALTER TABLE allows it, and by
 * rebuilding the table otherwise.
 *
 * @since 0.2.0
 */
class ColumnOperator extends AbstractOperator {

	/**
	 * Whether this table has a column with the given name.
	 *
	 * @param string $name The column name.
	 * @return bool
	 */
	public function exists( string $name ) : bool {
		return $this->inspect()->table_exists( $this->table ) && $this->inspect()->column_exists( $this->table, $name );
	}

	/**
	 * Add a column.
	 *
	 * Does nothing when a column with this name exists; its definition is
	 * not compared or changed.
	 *
	 * Rows already in the table get the column's default, or NULL. A NOT
	 * NULL column without a default is refused by PostgreSQL and SQLite when
	 * the table has rows; MySQL fills it with its type's implicit value
	 * (0 or '').
	 *
	 * @param Column $column The column.
	 * @return static
	 * @throws MigrationException When the table does not exist, or the column cannot be added.
	 */
	public function add( Column $column ) : static {
		if ( $this->exists( $column->name ) ) {
			return $this;
		}

		$this->require_table( sprintf( 'add column "%s"', $column->name ) );

		$action = sprintf( 'Adding column "%s" to table "%s"', $column->name, $this->table );

		if ( $this->is_sqlite() ) {
			$definition = $this->sqlite_column( $column );

			if ( ! TableDefinition::can_add_column_in_place( $definition ) ) {
				$this->rebuild( fn( TableDefinition $table ) => $table->add_column( $definition ) );
				return $this;
			}
		}

		$this->run( $this->query()->alter_table( $this->table )->add_column( $column )->build(), $action );

		return $this;
	}

	/**
	 * Rename a column.
	 *
	 * Does nothing when the column already has the new name ($from is gone
	 * and $to exists). Throws when both columns exist, or neither, since
	 * then it cannot tell which column is meant.
	 *
	 * @param string $from The current column name.
	 * @param string $to   The new column name.
	 * @return static
	 * @throws MigrationException When the rename is ambiguous or fails.
	 */
	public function rename( string $from, string $to ) : static {
		$from_exists = $this->exists( $from );
		$to_exists   = $this->exists( $to );

		if ( ! $from_exists && $to_exists ) {
			return $this;
		}

		if ( $from_exists && $to_exists ) {
			throw new MigrationException(
				sprintf( 'Cannot rename column "%s" to "%s" in table "%s": both columns exist.', $from, $to, $this->table )
			);
		}

		if ( ! $from_exists ) {
			throw new MigrationException(
				sprintf( 'Cannot rename column "%s" to "%s" in table "%s": neither column exists.', $from, $to, $this->table )
			);
		}

		$this->run(
			$this->query()->alter_table( $this->table )->rename_column( $from, $to )->build(),
			sprintf( 'Renaming column "%s" to "%s" in table "%s"', $from, $to, $this->table )
		);

		return $this;
	}

	/**
	 * Set a column's whole definition.
	 *
	 * Everything the Column describes is applied, and what it leaves unset
	 * takes Column's own default: a column modified without required() is
	 * nullable, without default() has no default, without a comment has
	 * none. The column keeps its name; use rename() for that.
	 *
	 * An auto-increment column stays auto-increment, so its type can be
	 * widened (e.g. INTEGER to BIG_INT). A column cannot be made
	 * auto-increment here; that is decided when it is created.
	 *
	 * @param Column $column The column's new definition; its name says which column.
	 * @return static
	 * @throws InvalidArgumentException When the definition turns on auto-increment.
	 * @throws MigrationException       When the table or column does not exist, or a stored
	 *                                  value cannot be carried into the new definition.
	 */
	public function modify( Column $column ) : static {
		$this->require_table( sprintf( 'modify column "%s"', $column->name ) );

		$current = $this->inspect()->get_column_details( $this->table )[ $column->name ] ?? null;

		if ( null === $current ) {
			throw new MigrationException(
				sprintf( 'Cannot modify column "%s": it does not exist in table "%s".', $column->name, $this->table )
			);
		}

		$auto_increment = (bool) ( $current['auto_increment'] ?? false );

		if ( $column->auto_increment && ! $auto_increment ) {
			throw new InvalidArgumentException(
				sprintf( 'Column "%s" cannot be made auto-increment by modify(); auto-increment is set when a column is created.', $column->name )
			);
		}

		$column                 = clone $column;
		$column->auto_increment = $auto_increment;
		$action                 = sprintf( 'Modifying column "%s" in table "%s"', $column->name, $this->table );

		if ( $this->is_sqlite() ) {
			$this->sqlite_modify( $column );
			return $this;
		}

		$sql = $this->query()->alter_table( $this->table )->modify_column( $column )->build();

		if ( 'mysql' === $this->db->get_driver() ) {
			$this->mysql_strict( fn() => $this->run( $sql, $action ) );
		} else {
			$this->run( $sql, $action );
		}

		return $this;
	}

	/**
	 * Drop a column.
	 *
	 * Does nothing when no column has this name.
	 *
	 * On SQLite, a column whose own definition carries a constraint (e.g.
	 * `email TEXT UNIQUE`) is dropped by rebuilding the table, since SQLite's
	 * ALTER TABLE refuses it. A column that an index, a table constraint, a
	 * view or a trigger uses is refused: drop those first.
	 *
	 * @param string $name The column name.
	 * @return static
	 * @throws MigrationException When the column cannot be dropped.
	 */
	public function drop( string $name ) : static {
		if ( ! $this->exists( $name ) ) {
			return $this;
		}

		if ( $this->is_sqlite() && $this->sqlite_definition()->column_has_constraints( $name ) ) {
			$this->rebuild( fn( TableDefinition $table ) => $table->drop_column( $name ) );
			return $this;
		}

		$this->run(
			$this->query()->alter_table( $this->table )->drop_column( $name )->build(),
			sprintf( 'Dropping column "%s" from table "%s"', $name, $this->table )
		);

		return $this;
	}

	/*
	|---------
	| Helpers
	|---------
	*/

	/**
	 * Modify a SQLite column by rebuilding the table.
	 *
	 * The column's definition is replaced; the constraints written inside it
	 * are kept (see TableDefinition::replace_column()). After the rows are
	 * copied, a column with a numeric type must hold no text or blob values:
	 * SQLite keeps a value it cannot convert as it was, so such a value is
	 * one the new type cannot hold.
	 *
	 * @param Column $column The new definition; auto_increment as the column has it now.
	 * @return void
	 * @throws MigrationException When the rebuild fails or a value cannot be held.
	 */
	private function sqlite_modify( Column $column ) : void {
		// Auto-increment lives in the PRIMARY KEY clause the old definition keeps.
		$plain                 = clone $column;
		$plain->auto_increment = false;
		$definition            = $this->sqlite_column( $plain );
		$type                  = (string) TableDefinition::from_sql( 'CREATE TABLE t (' . $definition . ')' )->get_column_type( $column->name );
		$affinity              = $this->sqlite_affinity( $type );

		$verify = null;

		if ( in_array( $affinity, [ 'INTEGER', 'REAL', 'NUMERIC' ], true ) ) {
			$verify = function ( string $build ) use ( $column, $type ) {
				$count = $this->db->get_var(
					sprintf(
						"SELECT COUNT(*) FROM %s WHERE typeof( %s ) IN ( 'text', 'blob' )",
						$this->quote_sqlite( $build ),
						$this->quote_sqlite( $column->name )
					)
				);

				if ( null === $count ) {
					throw new MigrationException(
						sprintf( 'Checking column "%s" failed: %s', $column->name, $this->db->get_last_error() ?? 'unknown error' )
					);
				}

				if ( (int) $count > 0 ) {
					throw new MigrationException(
						sprintf(
							'Cannot modify column "%s" in table "%s": %d stored value(s) cannot be held as %s. Change or remove them first.',
							$column->name,
							$this->table,
							(int) $count,
							$type
						)
					);
				}
			};
		}

		$this->rebuild( fn( TableDefinition $table ) => $table->replace_column( $column->name, $definition ), $verify );
	}

	/**
	 * Run a MySQL statement with strict mode on.
	 *
	 * Without strict mode, MySQL fits values into a changed column by
	 * cutting strings short, clamping numbers and turning NULL into '' or 0.
	 * With it, such a change fails and the table is left as it was. The
	 * session's sql_mode is restored afterwards.
	 *
	 * @param callable $statement Runs the statement.
	 * @return void
	 * @throws MigrationException When the statement fails or sql_mode cannot be read.
	 */
	private function mysql_strict( callable $statement ) : void {
		$mode  = $this->db->get_var( 'SELECT @@SESSION.sql_mode' );
		$modes = array_filter( explode( ',', (string) $mode ) );

		if ( ! is_string( $mode ) || ! preg_match( '/^[A-Z_,]*$/', $mode ) ) {
			throw new MigrationException( 'Could not read the session sql_mode to turn on strict mode.' );
		}

		if ( in_array( 'STRICT_ALL_TABLES', $modes, true ) ) {
			$statement();
			return;
		}

		$this->run(
			sprintf( "SET SESSION sql_mode = '%s'", implode( ',', [ ...$modes, 'STRICT_ALL_TABLES' ] ) ),
			'Turning on strict mode'
		);

		try {
			$statement();
		} finally {
			$this->db->exec( sprintf( "SET SESSION sql_mode = '%s'", $mode ) );
		}
	}

	/**
	 * A column as SQLite writes it inside CREATE TABLE.
	 *
	 * Rendered by the SQLite renderer as part of a CREATE TABLE statement,
	 * then taken out of it.
	 *
	 * @param Column $column The column.
	 * @return string
	 */
	private function sqlite_column( Column $column ) : string {
		$sql = $this->query()->create_table( $this->table )->add_column( $column )->build();

		return (string) TableDefinition::from_sql( $sql )->get_column( $column->name );
	}

	/**
	 * This SQLite table's stored definition.
	 *
	 * @return TableDefinition
	 * @throws MigrationException When it cannot be read.
	 */
	private function sqlite_definition() : TableDefinition {
		$sql = $this->db->get_var(
			"SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ? COLLATE NOCASE",
			[ $this->table ]
		);

		if ( ! is_string( $sql ) ) {
			throw new MigrationException( sprintf( 'Could not read the definition of table "%s".', $this->table ) );
		}

		return TableDefinition::from_sql( $sql );
	}

	/**
	 * The type affinity SQLite gives a declared type.
	 *
	 * @see https://www.sqlite.org/datatype3.html#determination_of_column_affinity
	 *
	 * @param string $type The declared type.
	 * @return string INTEGER, TEXT, BLOB, REAL or NUMERIC.
	 */
	private function sqlite_affinity( string $type ) : string {
		$type = strtoupper( $type );

		return match ( true ) {
			str_contains( $type, 'INT' )                                                          => 'INTEGER',
			str_contains( $type, 'CHAR' ), str_contains( $type, 'CLOB' ), str_contains( $type, 'TEXT' ) => 'TEXT',
			'' === trim( $type ), str_contains( $type, 'BLOB' )                                   => 'BLOB',
			str_contains( $type, 'REAL' ), str_contains( $type, 'FLOA' ), str_contains( $type, 'DOUB' ) => 'REAL',
			default                                                                               => 'NUMERIC',
		};
	}

	/**
	 * Rebuild this SQLite table with an edited definition.
	 *
	 * @param callable      $edit   fn( TableDefinition $table ): void
	 * @param callable|null $verify fn( string $build_table ): void
	 * @return void
	 * @throws MigrationException When the rebuild fails; the table is left as it was.
	 */
	private function rebuild( callable $edit, ?callable $verify = null ) : void {
		( new TableRebuilder( $this->db ) )->rebuild( $this->table, $edit, $verify );
	}

	/**
	 * Throw unless this table exists.
	 *
	 * @param string $action What was to be done.
	 * @return void
	 * @throws MigrationException
	 */
	private function require_table( string $action ) : void {
		if ( ! $this->inspect()->table_exists( $this->table ) ) {
			throw new MigrationException( sprintf( 'Cannot %s: table "%s" does not exist.', $action, $this->table ) );
		}
	}

	/**
	 * Quote an identifier for SQLite.
	 *
	 * @param string $identifier The identifier.
	 * @return string
	 */
	private function quote_sqlite( string $identifier ) : string {
		return '"' . str_replace( '"', '""', $identifier ) . '"';
	}
}