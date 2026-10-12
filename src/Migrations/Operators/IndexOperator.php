<?php
/**
 * Index operator class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations\Operators
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\Operators;

use Callismart\DBPrism\Exceptions\MigrationException;
use Callismart\DBPrism\Utils\Constraint;

/**
 * Index operations on one table.
 *
 *   $this->index( 'licenses' )->create( 'idx_licenses_status', 'status' );
 *   $this->index( 'licenses' )->create( 'idx_licenses_owner', 'owner_id', 'created_at' );
 *   $this->index( 'licenses' )->create_unique( 'uniq_licenses_key', 'license_key' );
 *   $this->index( 'licenses' )->rename( 'idx_licenses_status', 'idx_status' );
 *   $this->index( 'licenses' )->drop( 'idx_status' );
 *
 * An index is known by its name: the name is how the operator checks whether
 * the index is already there, so every operation takes one. An index on more
 * than one column (a composite index) is created by listing the columns in
 * order.
 *
 * Every operation checks the schema first and does nothing when its change
 * is already there, so a migration can safely run again.
 *
 * These operations manage indexes, plain or unique. A UNIQUE constraint is
 * managed with the constraint operator.
 *
 * @since 0.2.0
 */
class IndexOperator extends AbstractOperator {

	/**
	 * Whether this table has an index with the given name.
	 *
	 * @param string $name The index name.
	 * @return bool
	 */
	public function exists( string $name ) : bool {
		return $this->inspect()->has_index( $this->table, $name );
	}

	/**
	 * Create an index.
	 *
	 * Does nothing when an index with this name already exists; its columns
	 * are not compared or changed.
	 *
	 * @param string $name      The index name.
	 * @param string $column    The first indexed column.
	 * @param string ...$more   Further columns, in order, for a composite index.
	 * @return static
	 * @throws MigrationException When the index cannot be created.
	 */
	public function create( string $name, string $column, string ...$more ) : static {
		return $this->add( Constraint::index( $name )->on( $column, ...$more ) );
	}

	/**
	 * Create a unique index.
	 *
	 * Does nothing when an index with this name already exists; its columns
	 * and uniqueness are not compared or changed.
	 *
	 * @param string $name      The index name.
	 * @param string $column    The first indexed column.
	 * @param string ...$more   Further columns, in order, for a composite index.
	 * @return static
	 * @throws MigrationException When the index cannot be created, e.g. when
	 *                            existing rows hold duplicate values.
	 */
	public function create_unique( string $name, string $column, string ...$more ) : static {
		return $this->add( Constraint::unique( $name )->on( $column, ...$more ) );
	}

	/**
	 * Rename an index.
	 *
	 * Does nothing when the index already has the new name ($from is gone and
	 * $to exists). Throws when both indexes exist, or neither, since then it
	 * cannot tell which index is meant.
	 *
	 * The index is rebuilt under the new name from its columns and uniqueness,
	 * which works the same way on every engine. Any other property of the
	 * original index (for example a MySQL prefix length or a PostgreSQL index
	 * method) is not carried over. The rebuild runs in a transaction, so a
	 * failure leaves the original index in place.
	 *
	 * @param string $from The current index name.
	 * @param string $to   The new index name.
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
				sprintf( 'Cannot rename index "%s" to "%s" on table "%s": both indexes exist.', $from, $to, $this->table )
			);
		}

		if ( ! $from_exists ) {
			throw new MigrationException(
				sprintf( 'Cannot rename index "%s" to "%s" on table "%s": neither index exists.', $from, $to, $this->table )
			);
		}

		$definition = $this->inspect()->get_indexes( $this->table )[ $from ] ?? null;

		if ( empty( $definition['columns'] ) ) {
			throw new MigrationException(
				sprintf( 'Cannot rename index "%s" on table "%s": its columns could not be read.', $from, $this->table )
			);
		}

		$index = ! empty( $definition['unique'] ) ? Constraint::unique( $to ) : Constraint::index( $to );
		$index->on( ...array_values( $definition['columns'] ) );

		$sql = $this->query()
			->alter_table( $this->table )
			->drop_index( $from )
			->add_index( $index )
			->build();

		$this->db->transactional( function () use ( $sql, $from, $to ) {
			$this->run( $sql, sprintf( 'Renaming index "%s" to "%s" on table "%s"', $from, $to, $this->table ) );
		} );

		return $this;
	}

	/**
	 * Drop an index.
	 *
	 * Does nothing when no index with this name exists.
	 *
	 * @param string $name The index name.
	 * @return static
	 * @throws MigrationException When the index cannot be dropped.
	 */
	public function drop( string $name ) : static {
		if ( ! $this->exists( $name ) ) {
			return $this;
		}

		$sql = $this->query()
			->alter_table( $this->table )
			->drop_index( $name )
			->build();

		$this->run( $sql, sprintf( 'Dropping index "%s" on table "%s"', $name, $this->table ) );

		return $this;
	}

	/**
	 * Add an index unless one with the same name exists.
	 *
	 * Every column is checked before the index is created. SQLite reads a
	 * quoted name that matches no column as a string, and would index that
	 * constant instead of failing; checking first gives every engine the same
	 * error.
	 *
	 * @param Constraint $index An 'index' or 'unique' definition with a name and columns.
	 * @return static
	 * @throws MigrationException When the table or a column does not exist, or the index cannot be created.
	 */
	private function add( Constraint $index ) : static {
		if ( $this->exists( $index->name ) ) {
			return $this;
		}

		if ( ! $this->inspect()->table_exists( $this->table ) ) {
			throw new MigrationException(
				sprintf( 'Cannot create index "%s": table "%s" does not exist.', $index->name, $this->table )
			);
		}

		foreach ( $index->columns as $column ) {
			if ( ! $this->inspect()->column_exists( $this->table, $column ) ) {
				throw new MigrationException(
					sprintf( 'Cannot create index "%s" on table "%s": column "%s" does not exist.', $index->name, $this->table, $column )
				);
			}
		}

		$sql = $this->query()
			->alter_table( $this->table )
			->add_index( $index )
			->build();

		$this->run( $sql, sprintf( 'Creating index "%s" on table "%s"', $index->name, $this->table ) );

		return $this;
	}
}