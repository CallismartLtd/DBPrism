<?php
/**
 * Table operator class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations\Operators
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\Operators;

use Callismart\DBPrism\Exceptions\MigrationException;
use Callismart\DBPrism\Query\QueryIntents\CreateTableIntent;

/**
 * Table-level migration operations.
 *
 *   $this->table( 'licenses' )->create( function ( CreateTableIntent $table ) { ... } );
 *   $this->table( 'licenses' )->rename( 'smliser_licenses' );
 *   $this->table( 'licenses' )->index()->...;
 *
 * Every operation checks the schema first and does nothing when its change
 * is already there, so a migration can safely run again.
 *
 * @since 0.2.0
 */
class TableOperator extends AbstractOperator {

	/**
	 * Create this table.
	 *
	 * Does nothing when the table already exists; its definition is not
	 * compared or changed. Change an existing table with column(), index()
	 * and constraint().
	 *
	 * @param callable $define fn( CreateTableIntent $table ): void — adds the columns and constraints.
	 * @return static
	 * @throws MigrationException When the table cannot be created.
	 */
	public function create( callable $define ) : static {
		if ( $this->inspect()->table_exists( $this->table ) ) {
			return $this;
		}

		$intent = $this->query()->create_table( $this->table );
		$define( $intent );

		$this->run( $intent->build(), sprintf( 'Creating table "%s"', $this->table ) );

		return $this;
	}

	/**
	 * Rename this table.
	 *
	 * Does nothing when the table already has the new name (this table is
	 * gone and $new_name exists). Throws when both tables exist, or neither,
	 * since then it cannot tell which table is meant.
	 *
	 * @param string $new_name The new table name.
	 * @return static
	 * @throws MigrationException When the rename is ambiguous or fails.
	 */
	public function rename( string $new_name ) : static {
		$old_exists = $this->inspect()->table_exists( $this->table );
		$new_exists = $this->inspect()->table_exists( $new_name );

		if ( ! $old_exists && $new_exists ) {
			return $this;
		}

		if ( $old_exists && $new_exists ) {
			throw new MigrationException(
				sprintf( 'Cannot rename table "%s" to "%s": both tables exist.', $this->table, $new_name )
			);
		}

		if ( ! $old_exists ) {
			throw new MigrationException(
				sprintf( 'Cannot rename table "%s" to "%s": neither table exists.', $this->table, $new_name )
			);
		}

		$sql = $this->query()
			->alter_table( $this->table )
			->rename( $new_name )
			->build();

		$this->run( $sql, sprintf( 'Renaming table "%s" to "%s"', $this->table, $new_name ) );

		return $this;
	}

	/**
	 * Remove all rows from this table.
	 *
	 * Safe to repeat. Throws when the table does not exist.
	 *
	 * @param bool $restart Reset auto-increment counters, where the engine supports it.
	 * @param bool $cascade Also empty tables that reference this one, where the engine supports it.
	 * @return static
	 * @throws MigrationException When the table cannot be emptied.
	 */
	public function truncate( bool $restart = true, bool $cascade = false ) : static {
		// SQLite keeps auto-increment counters in sqlite_sequence, which only
		// exists once an AUTOINCREMENT table has been created. Without it
		// there is no counter to reset, and the reset statement would fail.
		if ( $restart && $this->is_sqlite() && ! $this->inspect()->table_exists( 'sqlite_sequence' ) ) {
			$restart = false;
		}

		$sql = $this->query()
			->truncate_table( $this->table )
			->restart_identity( $restart )
			->cascade( $cascade )
			->build();

		$this->run( $sql, sprintf( 'Emptying table "%s"', $this->table ) );

		return $this;
	}

	/**
	 * Drop this table.
	 *
	 * Does nothing when the table does not exist.
	 *
	 * @return static
	 * @throws MigrationException When the table cannot be dropped.
	 */
	public function drop() : static {
		$sql = $this->query()
			->drop_table( $this->table )
			->if_exists()
			->build();

		$this->run( $sql, sprintf( 'Dropping table "%s"', $this->table ) );

		return $this;
	}

	/*
	|----------------------
	| Fluent operators
	|----------------------
	*/

	/**
	 * Column operations on this table.
	 *
	 * @return ColumnOperator
	 */
	public function column() : ColumnOperator {
		return new ColumnOperator( $this->db, $this->table );
	}

	/**
	 * Index operations on this table.
	 *
	 * @return IndexOperator
	 */
	public function index() : IndexOperator {
		return new IndexOperator( $this->db, $this->table );
	}

	/**
	 * Constraint operations on this table.
	 *
	 * @return ConstraintOperator
	 */
	public function constraint() : ConstraintOperator {
		return new ConstraintOperator( $this->db, $this->table );
	}
}