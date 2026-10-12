<?php
/**
 * Constraint operator class file.
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
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use InvalidArgumentException;

/**
 * Constraint operations on one table: the primary key, unique constraints
 * and foreign keys.
 *
 *   $this->constraint( 'licenses' )->add_primary_key( 'id' );
 *   $this->constraint( 'licenses' )->add_unique( 'uq_licenses_key', 'license_key' );
 *   $this->constraint( 'licenses' )->add_foreign_key(
 *       Constraint::foreign_key( 'fk_licenses_owner' )
 *           ->on( 'owner_id' )
 *           ->references( 'users', 'id' )
 *           ->on_delete( 'cascade' )
 *   );
 *   $this->constraint( 'licenses' )->drop_foreign_key( 'fk_licenses_owner' );
 *
 * A unique constraint or foreign key is known by its name: the name is how
 * the operator checks whether it is already there, so every one of these
 * operations takes one. A table has at most one primary key, so the primary
 * key is known by its columns.
 *
 * Every operation checks the schema first and does nothing when its change
 * is already there, so a migration can safely run again.
 *
 * Foreign key actions are only written when given: without on_delete() or
 * on_update(), the engine's own default applies.
 *
 * On SQLite, whose ALTER TABLE cannot add or drop constraints, the table is
 * rebuilt (see TableRebuilder). Only constraints written as table
 * constraints can be dropped there, and an unnamed unique constraint or
 * foreign key cannot be found by name.
 *
 * Indexes, plain or unique, are managed with the index operator.
 *
 * @since 0.2.0
 */
class ConstraintOperator extends AbstractOperator {

	/*
	|-------------
	| Primary key
	|-------------
	*/

	/**
	 * Add the primary key.
	 *
	 * Does nothing when the table's primary key is already on these columns,
	 * in this order. Throws when it has a primary key on other columns.
	 *
	 * @param string $column  The first key column.
	 * @param string ...$more Further key columns, in order.
	 * @return static
	 * @throws MigrationException When the table or a column does not exist, the table
	 *                            has a different primary key, or the key cannot be added.
	 */
	public function add_primary_key( string $column, string ...$more ) : static {
		$columns = [ $column, ...$more ];

		$this->require_table( 'add a primary key' );

		$current = $this->inspect()->get_primary_key( $this->table );

		if ( null !== $current ) {
			if ( $this->same_columns( $current, $columns ) ) {
				return $this;
			}

			throw new MigrationException(
				sprintf(
					'Cannot add a primary key on (%s) to table "%s": it already has one on (%s).',
					implode( ', ', $columns ),
					$this->table,
					implode( ', ', $current )
				)
			);
		}

		$this->require_columns( $this->table, $columns, 'add a primary key' );

		return $this->add( Constraint::primary()->on( ...$columns ), 'Adding a primary key' );
	}

	/**
	 * Drop the primary key.
	 *
	 * Does nothing when the table has no primary key.
	 *
	 * @return static
	 * @throws MigrationException When the primary key cannot be dropped, e.g. on SQLite
	 *                            when its column uses AUTOINCREMENT.
	 */
	public function drop_primary_key() : static {
		if ( ! $this->inspect()->table_exists( $this->table ) || null === $this->inspect()->get_primary_key( $this->table ) ) {
			return $this;
		}

		$action = sprintf( 'Dropping the primary key of table "%s"', $this->table );

		if ( $this->is_sqlite() ) {
			$this->rebuild( fn( TableDefinition $definition ) => $definition->drop_primary_key() );
			return $this;
		}

		$name = $this->inspect()->get_primary_key_name( $this->table );

		if ( null === $name ) {
			throw new MigrationException( sprintf( '%s failed: its name could not be read.', $action ) );
		}

		$this->run( $this->query()->alter_table( $this->table )->drop_constraint( $name )->build(), $action );

		return $this;
	}

	/*
	|--------------------
	| Unique constraints
	|--------------------
	*/

	/**
	 * Add a unique constraint.
	 *
	 * Does nothing when a unique constraint with this name exists; its
	 * columns are not compared or changed.
	 *
	 * @param string $name    The constraint name.
	 * @param string $column  The first column.
	 * @param string ...$more Further columns, in order.
	 * @return static
	 * @throws MigrationException When the table or a column does not exist, or the constraint
	 *                            cannot be added, e.g. when existing rows hold duplicate values.
	 */
	public function add_unique( string $name, string $column, string ...$more ) : static {
		$columns = [ $column, ...$more ];

		$this->require_name( $name );

		if ( $this->has_unique( $name ) ) {
			return $this;
		}

		$this->require_table( sprintf( 'add unique constraint "%s"', $name ) );
		$this->require_columns( $this->table, $columns, sprintf( 'add unique constraint "%s"', $name ) );

		return $this->add( Constraint::unique( $name )->on( ...$columns ), sprintf( 'Adding unique constraint "%s"', $name ) );
	}

	/**
	 * Drop a unique constraint.
	 *
	 * Does nothing when no unique constraint has this name.
	 *
	 * @param string $name The constraint name.
	 * @return static
	 * @throws MigrationException When the constraint cannot be dropped.
	 */
	public function drop_unique( string $name ) : static {
		if ( ! $this->has_unique( $name ) ) {
			return $this;
		}

		return $this->drop( $name, 'unique', sprintf( 'Dropping unique constraint "%s"', $name ) );
	}

	/*
	|--------------
	| Foreign keys
	|--------------
	*/

	/**
	 * Add a foreign key.
	 *
	 * Does nothing when a foreign key with this name exists; its definition
	 * is not compared or changed.
	 *
	 * @param Constraint $foreign_key A named foreign key, e.g.
	 *                                Constraint::foreign_key( 'fk_name' )->on( 'col' )->references( 'table', 'id' ).
	 * @return static
	 * @throws InvalidArgumentException When the definition is not a complete, named foreign key.
	 * @throws MigrationException       When a table or column does not exist, or the key cannot
	 *                                  be added, e.g. when existing rows break it.
	 */
	public function add_foreign_key( Constraint $foreign_key ) : static {
		if ( 'foreign' !== strtolower( $foreign_key->type ) ) {
			throw new InvalidArgumentException( sprintf( 'add_foreign_key() expects a foreign key, [%s] given.', $foreign_key->type ) );
		}

		$name = (string) $foreign_key->name;

		$this->require_name( $name );

		if ( empty( $foreign_key->columns ) || empty( $foreign_key->references_table ) ) {
			throw new InvalidArgumentException( sprintf( 'Foreign key "%s" needs its columns and the table it references.', $name ) );
		}

		if ( count( $foreign_key->columns ) !== count( $foreign_key->references_columns ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Foreign key "%s" has %d column(s) but references %d.',
					$name,
					count( $foreign_key->columns ),
					count( $foreign_key->references_columns )
				)
			);
		}

		if ( $this->inspect()->table_exists( $this->table ) && $this->inspect()->has_foreign_key( $this->table, $name ) ) {
			return $this;
		}

		$action = sprintf( 'add foreign key "%s"', $name );

		$this->require_table( $action );
		$this->require_columns( $this->table, $foreign_key->columns, $action );

		if ( ! $this->inspect()->table_exists( $foreign_key->references_table ) ) {
			throw new MigrationException(
				sprintf( 'Cannot %s: the referenced table "%s" does not exist.', $action, $foreign_key->references_table )
			);
		}

		$this->require_columns( $foreign_key->references_table, $foreign_key->references_columns, $action );

		return $this->add( $foreign_key, sprintf( 'Adding foreign key "%s"', $name ) );
	}

	/**
	 * Drop a foreign key.
	 *
	 * Does nothing when no foreign key has this name.
	 *
	 * @param string $name The foreign key name.
	 * @return static
	 * @throws MigrationException When the foreign key cannot be dropped.
	 */
	public function drop_foreign_key( string $name ) : static {
		if ( ! $this->inspect()->table_exists( $this->table ) || ! $this->inspect()->has_foreign_key( $this->table, $name ) ) {
			return $this;
		}

		return $this->drop( $name, 'foreign', sprintf( 'Dropping foreign key "%s"', $name ) );
	}

	/*
	|---------
	| Helpers
	|---------
	*/

	/**
	 * Add a constraint: in place, or by rebuilding the table on SQLite.
	 *
	 * @param Constraint $constraint The constraint.
	 * @param string     $action     What is being done, for the error message.
	 * @return static
	 * @throws MigrationException When the constraint cannot be added.
	 */
	private function add( Constraint $constraint, string $action ) : static {
		if ( $this->is_sqlite() ) {
			$definition = $this->sqlite_definition( $constraint );
			$this->rebuild( fn( TableDefinition $table ) => $table->add_constraint( $definition ) );

			return $this;
		}

		$sql = $this->query()
			->alter_table( $this->table )
			->add_constraint( $constraint )
			->build();

		$this->run( $sql, sprintf( '%s on table "%s"', $action, $this->table ) );

		return $this;
	}

	/**
	 * Drop a named constraint: in place, or by rebuilding the table on SQLite.
	 *
	 * @param string $name   The constraint name.
	 * @param string $type   The type it must have on SQLite: unique or foreign.
	 * @param string $action What is being done, for the error message.
	 * @return static
	 * @throws MigrationException When the constraint cannot be dropped.
	 */
	private function drop( string $name, string $type, string $action ) : static {
		if ( $this->is_sqlite() ) {
			$this->rebuild( function ( TableDefinition $table ) use ( $name, $type ) {
				$found = $table->get_constraint( $name );

				if ( null !== $found && $type !== $found['type'] ) {
					throw new InvalidArgumentException( sprintf( 'Constraint "%s" is a %s constraint, not %s.', $name, $found['type'], $type ) );
				}

				$table->drop_constraint( $name );
			} );

			return $this;
		}

		$sql = $this->query()
			->alter_table( $this->table )
			->drop_constraint( $name )
			->build();

		$this->run( $sql, sprintf( '%s on table "%s"', $action, $this->table ) );

		return $this;
	}

	/**
	 * Rebuild this SQLite table with an edited definition.
	 *
	 * @param callable $edit fn( TableDefinition $table ): void
	 * @return void
	 * @throws MigrationException When the rebuild fails; the table is left as it was.
	 */
	private function rebuild( callable $edit ) : void {
		( new TableRebuilder( $this->db ) )->rebuild( $this->table, $edit );
	}

	/**
	 * A constraint as SQLite writes it inside CREATE TABLE.
	 *
	 * Rendered by the SQLite renderer as part of a CREATE TABLE statement for
	 * this table, then taken out of it, so the text is exactly what the
	 * renderer writes for a new table.
	 *
	 * @param Constraint $constraint The constraint.
	 * @return string
	 */
	private function sqlite_definition( Constraint $constraint ) : string {
		$sql = $this->query()
			->create_table( $this->table )
			->add_column( Column::make( 'column' )->type( ColumnType::INTEGER ) )
			->add_constraint( $constraint )
			->build();

		$constraints = TableDefinition::from_sql( $sql )->get_constraints();

		return end( $constraints )['sql'];
	}

	/**
	 * Whether this table has a unique constraint with the given name.
	 *
	 * @param string $name The constraint name.
	 * @return bool
	 */
	private function has_unique( string $name ) : bool {
		if ( ! $this->inspect()->table_exists( $this->table ) ) {
			return false;
		}

		foreach ( array_keys( $this->inspect()->get_unique_constraints( $this->table ) ) as $existing ) {
			if ( $this->same_name( (string) $existing, $name ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Throw unless this table exists.
	 *
	 * @param string $action What was to be done, e.g. 'add a primary key'.
	 * @return void
	 * @throws MigrationException
	 */
	private function require_table( string $action ) : void {
		if ( ! $this->inspect()->table_exists( $this->table ) ) {
			throw new MigrationException( sprintf( 'Cannot %s: table "%s" does not exist.', $action, $this->table ) );
		}
	}

	/**
	 * Throw unless every column exists in a table.
	 *
	 * Checked here rather than left to the engine, because SQLite reads a
	 * quoted name that matches no column as a string instead of failing.
	 *
	 * @param string   $table   The table name.
	 * @param string[] $columns The column names.
	 * @param string   $action  What was to be done.
	 * @return void
	 * @throws MigrationException
	 */
	private function require_columns( string $table, array $columns, string $action ) : void {
		foreach ( $columns as $column ) {
			if ( ! $this->inspect()->column_exists( $table, $column ) ) {
				throw new MigrationException(
					sprintf( 'Cannot %s: column "%s" does not exist in table "%s".', $action, $column, $table )
				);
			}
		}
	}

	/**
	 * Throw unless a constraint name is given.
	 *
	 * @param string $name The constraint name.
	 * @return void
	 * @throws InvalidArgumentException
	 */
	private function require_name( string $name ) : void {
		if ( '' === trim( $name ) ) {
			throw new InvalidArgumentException( 'A unique constraint or foreign key needs a name; the name is how a migration finds it again.' );
		}
	}

	/**
	 * Whether two column lists are the same, in the same order.
	 *
	 * @param string[] $a
	 * @param string[] $b
	 * @return bool
	 */
	private function same_columns( array $a, array $b ) : bool {
		if ( count( $a ) !== count( $b ) ) {
			return false;
		}

		foreach ( array_values( $a ) as $i => $column ) {
			if ( ! $this->same_name( $column, $b[ $i ] ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether two identifiers name the same object on this engine.
	 *
	 * PostgreSQL compares quoted identifiers exactly; MySQL (for columns and
	 * constraints) and SQLite ignore case.
	 *
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	private function same_name( string $a, string $b ) : bool {
		return 'pgsql' === $this->db->get_driver() ? $a === $b : 0 === strcasecmp( $a, $b );
	}
}