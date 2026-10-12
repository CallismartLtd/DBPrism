<?php
/**
 * Column Helper for Fluent Column Operations
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\Operators;

use Callismart\DBPrism\Database;
use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\Constraint;

/**
 * Column migration orchestrator.
 *
 * Responsibilities:
 * - Validates column state
 * - Delegates SQL generation to SQLBuilder
 * - Executes via Database abstraction layer
 *
 * @since 0.2.0
 */
class ColumnOperator {
	/**
	 * @param Database $db The database abstraction layer.
	 * @param string $table The table whose column is in context.
	 */
	public function __construct(
		private Database $db,
		private string $table
	) {}

	/**
	 * Add column.
	 * 
	 * @param string|Column $column The new column name.
	 * @param array{
     *     type: int, // @see \Callismart\DBPrism\Utils\ColumnType
     *     length?: int|null,
     *     precision?: int|null,
     *     scale?: int|null,
     *     unsigned?: bool,
     *     nullable?: bool,
     *     auto_increment?: bool,
     *     default?: mixed,
     *     comment?: string
     * } $definitions
	 * 
	 * @param array{
     *     type: string,
     *     name?: string,
     *     columns?: array<int, string>,
     *     references_table?: string,
     *     references_columns?: array<int, string>,
     *     on_delete?: string,
     *     on_update?: string
     * } $constraints
	 * @return static Fluent
	 * @throws \Exception
	 */
	public function add(
		string|Column $column,
		array $definitions = [],
		array $constraints = []
	) : static {
	
		if ( $this->exists( $column ) ) {
			throw new \Exception( "Column '{$column}' already exists in '{$this->table}'" );
		}

		$column		= is_string( $column ) ? Column::make( $column ) : $column;
		$column		= $this->build_column_definitions( $column, $definitions );
		$constraint	= $this->build_constraint( $constraints );

		$sql = $this->query()
			->alter_table( $this->table )
			->add_column( $column, $constraint )
			->build();

		$this->db->exec( $sql );

		return $this;
	}

	/**
	 * Drop column.
	 */
	public function drop( string $column ) : static {

		if ( ! $this->exists( $column ) ) {
			throw new \Exception( "Column '{$column}' does not exist in '{$this->table}'" );
		}

		$sql = $this->query()
			->alter_table( $this->table )
			->drop_column( $column )
			->build();

		$this->db->exec( $sql );
		
		return $this;
	}

	/**
	 * Rename column.
	 */
	public function rename( string $old, string $new ) : static {

		if ( ! $this->exists( $old ) ) {
			throw new \Exception( "Column '{$old}' does not exist in '{$this->table}'" );
		}

		if ( $this->exists( $new ) ) {
			throw new \Exception( "Column '{$new}' already exists in '{$this->table}'" );
		}

		try {
			$sql = $this->query()
				->alter_table( $this->table )
				->rename_column( $old, $new )
				->build();

			$this->db->exec( $sql );


		} catch ( \Exception $e ) {
			// Let engine-specific fallback be handled at a higher migration layer
			throw $e;
		}

		return $this;
	}

	/**
	 * Modify column.
	 * 
	 * @param string|Column $column The column name.
	 * @param array{
     *     name: string,
     *     type: string,
     *     length?: int|null,
     *     precision?: int|null,
     *     scale?: int|null,
     *     unsigned?: bool,
     *     nullable?: bool,
     *     auto_increment?: bool,
     *     default?: mixed,
     *     comment?: string
     * } $definitions
	 * @param array{
     *     type: string,
     *     name?: string,
     *     columns?: array<int, string>,
     *     references_table?: string,
     *     references_columns?: array<int, string>,
     *     on_delete?: string,
     *     on_update?: string
     * } $constraints
	 * 
	 * @return static Fluent.
	 */
	public function modify(
		string|Column $column,
		array $definitions	= [],
		array $constraints	= []
	) : static {

		if ( ! $this->exists( $column ) ) {
			throw new \Exception( "Column '{$column}' does not exist in '{$this->table}'" );
		}

		$column		= is_string( $column ) ? Column::make( $column ) : $column;
		$column		= $this->build_column_definitions( $column, $definitions );
		$constraint	= $this->build_constraint( $constraints );

		$sql = $this->query()
			->alter_table( $this->table )
			->modify_column( $column, $constraint )
			->build();

		$this->db->exec( $sql );

		return $this;
	}

	/**
	 * Change column type.
	 * 
	 * @param string $column The column name.
	 * @param int $type The column type @see \Callismart\DBPrism\Utils\ColumnType constants
	 */
	public function changeType( string $column, int $type ) : static {

		if ( ! $this->exists( $column ) ) {
			throw new \Exception( "Column '{$column}' does not exist in '{$this->table}'" );
		}

		if ( 0 === $type ) {
			throw new \Exception( "Column type is required for this operation." );
		}

		$column	= Column::make( $column )->type( $type );

		$sql = $this->query()
			->alter_table( $this->table )
			->modify_column( $column )
			->build();

		$this->db->exec( $sql );

		return $this;
	}

	/**
	 * Check existence.
	 */
	public function exists( string $column ) : bool {
		return $this->db->column_exists( $this->table, $column );
	}

	/**
	 * Get type.
	 */
	public function getType( string $column ) : ?string {
		return $this->db->get_column_type( $this->table, $column );
	}

	/**
	 * List columns.
	 */
	public function list() : array {
		return $this->db->get_columns( $this->table );
	}

	/*
	|-------------------
	| PRIVATE HELPERS
	|-------------------
	*/

	/**
	 * @param Column $column 
	 * @param array{
     *     name: string,
     *     type: string,
     *     length?: int|null,
     *     precision?: int|null,
     *     scale?: int|null,
     *     unsigned?: bool,
     *     nullable?: bool,
     *     auto_increment?: bool,
     *     default?: mixed,
     *     comment?: string
     * } $definitions
	 */
	protected function build_column_definitions( Column $column, array $definitions ) : Column {
		if ( empty( $definitions['type'] ) ) {
			throw new \Exception( 'Column type is required.' );
		}

		$reflection = new \ReflectionClass( $column );

		foreach ( $definitions as $key => $value ) {
			if ( $reflection->hasProperty( $key ) ) {
				$property = $reflection->getProperty( $key );
				$property->setValue( $column, $value );
			}
		}

		return $column;
	}

	/**
	 * @param array{
     *     type: string,
     *     name?: string,
     *     columns?: array<int, string>,
     *     references_table?: string,
     *     references_columns?: array<int, string>,
     *     on_delete?: string,
     *     on_update?: string
     * } $definitions
	 */
	protected function build_constraint( array $definitions ) : ?Constraint {
		if ( empty( $definitions ) || empty( $definitions['type'] ) ) {
			return null;
		}

		$constraint = Constraint::make( $definitions['type'] );
		$reflection = new \ReflectionClass( $constraint );

		foreach ( $definitions as $key => $value ) {
			if ( $reflection->hasProperty( $key ) ) {
				$property = $reflection->getProperty( $key );
				$property->setValue( $constraint, $value );
			}
		}

		return $constraint;
	}

	/**
	 * Start query building
	 * 
	 * @return SQLBuilder
	 */
	protected function query() : SQLBuilder {
		return new SQLBuilder( $this->db->get_driver() );
	}
}