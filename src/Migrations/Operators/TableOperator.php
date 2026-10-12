<?php
/**
 * Table Helper class file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\Operators;

use Callismart\DBPrism\Database;
use Callismart\DBPrism\Query\SQLBuilder;

/**
 * Table-level migration orchestrator.
 *
 * Responsibilities:
 * - Delegates SQL generation to SQLBuilder
 * - Executes SQL via Database abstraction
 * - Keeps migration logic DB-agnostic
 *
 * @since 0.2.0
 */
class TableOperator {

	/**
	 * Database engine type.
	 *
	 * @var string
	 */
	protected string $engine;

	/**
	 * Constructor.
	 *
	 * @param Database    $db
	 * @param string      $table
	 */
	public function __construct( 
		private Database $db, 
		private string $table 
	) {
		$this->engine   = $db->get_driver();
	}

	/**
	 * Rename this table.
	 *
	 * @param string $new_name
	 *
	 * @return static
	 */
	public function rename( string $new_name ) : static {
		$sql	= $this->query()
			->alter_table( $this->table )
			->rename( $new_name );
		$this->db->exec( $sql->build() );

		return $this;
	}

	/**
	 * Begin column operation.
	 *
	 * @return ColumnOperator
	 */
	public function column() : ColumnOperator {
		return new ColumnOperator(
			$this->db,
			$this->table
		);
	}

	/**
	 * Begin constraint operation on table.
	 * 
	 * @return ConstraintOperator
	 */
	public function constraint() {
		return new ConstraintOperator(
			$this->db,
			$this->table
		);
	}

	/**
	 * Truncate table.
	 *
	 * @return static
	 */
	public function truncate( bool $restart = true, bool $cascade = false ) : static {
		$sql = $this->query()
			->truncate_table( $this->table )
			->restart_identity( $restart )
			->cascade( $cascade );

		$this->db->exec( $sql->build() );
		return $this;
	}

	/**
	 * Drop this table.
	 * 
	 * @param bool $exists_check
	 * @return static Fluent
	 */
	public function drop( bool $exists_check = true ) : static {

		$sql	= $this->query()
			->drop_table( $this->table );
		if ( $exists_check ) {
			$sql->if_exists();
		}

		$this->db->exec( $sql->build() );

		return $this;

	}

	/**
	 * Drop index.
	 */
	public function drop_index( string $name ) :static {
		$sql	= $this->query()
			->alter_table( $this->table )
			->drop_index( $name );
		
		$this->db->exec( $sql->build() );

		return $this;

	}

	/**
	 * Start query building.
	 * 
	 * @return SQLBuilder
	 */
	public function query() : SQLBuilder {
		return new SQLBuilder( $this->db->get_driver() );
	}
}