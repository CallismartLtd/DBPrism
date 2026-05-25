<?php
/**
 * Composite Selection Intent - Multi-Table Query Operations
 * 
 * Wraps SelectionIntent to add JOINs and UNIONs support.
 * Follows the exact same patterns as SelectionIntent:
 * - Uses QueryCriteriaTrait for WHERE conditions
 * - Uses SQLBuilderStrategyTrait for builder reference
 * - Implements QueryIntentInterface
 * - Fluent API returning static
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\QueryIntents
 * @since 0.2.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Query\QueryIntents;

use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Query\SQLBuilderStrategyTrait;

/**
 * CompositeSelectionIntent
 *
 * Extends SelectionIntent's capabilities to support multiple JOINs
 * collected separately and UNIONs combining multiple SelectionIntent queries.
 *
 * Responsibility: Orchestrate multi-table SELECT operations.
 * Rendering delegated to engine-specific renderers.
 *
 * @since 0.2.0
 */
class CompositeSelectionIntent implements QueryIntentInterface {
	use SQLBuilderStrategyTrait;

	/**
	 * Primary (base) selection intent.
	 *
	 * @var SelectionIntent
	 */
	private SelectionIntent $primary_selection;

	/**
	 * Additional joins collected separately from primary_selection.
	 *
	 * These are rendered AFTER the primary_selection's internal joins.
	 * Structure: ['table' => ..., 'first' => ..., 'operator' => ..., 'second' => ..., 'type' => ...]
	 *
	 * @var array
	 */
	private array $composite_joins = [];

	/**
	 * Union intents collected during build.
	 *
	 * @var array<UnionIntent>
	 */
	private array $unions = [];

	/**
	 * Private constructor to enforce static factory usage.
	 *
	 * @param SQLBuilder $builder
	 */
	private function __construct( SQLBuilder $builder ) {
		$this->builder = $builder;
		$this->primary_selection = SelectionIntent::make( $builder );
	}

	/*
	|----------------------------------
	| PRIMARY SELECTION DELEGATION
	|----------------------------------
	| All these methods delegate directly to primary_selection.
	| This allows CompositeSelectionIntent to masquerade as a SelectionIntent
	| while maintaining separate join/union collections.
	*/

	/**
	 * Set columns for the primary SELECT.
	 *
	 * @param string ...$columns Column names or SQL expressions
	 *
	 * @return static
	 */
	public function select( string ...$columns ) : static {
		$this->primary_selection->select( ...$columns );
		return $this;
	}

	/**
	 * Set the primary table.
	 *
	 * @param string $table Table name (raw, unquoted)
	 *
	 * @return static
	 */
	public function from( string $table ) : static {
		$this->primary_selection->from( $table );
		return $this;
	}

	/**
	 * Set the DISTINCT flag.
	 *
	 * @param bool $value
	 *
	 * @return static
	 */
	public function distinct( bool $value = true ) : static {
		$this->primary_selection->distinct( $value );
		return $this;
	}

	/**
	 * Add a basic WHERE clause with column, operator, value.
	 *
	 * @param string $column
	 * @param string $operator
	 * @param mixed  $value
	 *
	 * @return static
	 */
	public function where( string $column, string $operator, $value ) : static {
		$this->primary_selection->where( $column, $operator, $value );
		return $this;
	}

	/**
	 * Add an OR WHERE clause.
	 *
	 * @param string $column
	 * @param string $operator
	 * @param mixed  $value
	 *
	 * @return static
	 */
	public function or_where( string $column, string $operator, $value ) : static {
		$this->primary_selection->or_where( $column, $operator, $value );
		return $this;
	}

	/**
	 * Add a WHERE IS NULL clause.
	 *
	 * @param string $column
	 * @param string $boolean
	 * @param bool   $not
	 *
	 * @return static
	 */
	public function where_null( string $column, string $boolean = 'AND', bool $not = false ) : static {
		$this->primary_selection->where_null( $column, $boolean, $not );
		return $this;
	}

	/**
	 * Add a WHERE IS NOT NULL clause.
	 *
	 * @param string $column
	 * @param string $boolean
	 *
	 * @return static
	 */
	public function where_not_null( string $column, string $boolean = 'AND' ) : static {
		$this->primary_selection->where_not_null( $column, $boolean );
		return $this;
	}

	/**
	 * Add a WHERE IN clause.
	 *
	 * @param string $column
	 * @param array  $values
	 * @param string $boolean
	 * @param bool   $not
	 *
	 * @return static
	 */
	public function where_in( string $column, array $values, string $boolean = 'AND', bool $not = false ) : static {
		$this->primary_selection->where_in( $column, $values, $boolean, $not );
		return $this;
	}

	/**
	 * Add a WHERE NOT IN clause.
	 *
	 * @param string $column
	 * @param array  $values
	 *
	 * @return static
	 */
	public function where_not_in( string $column, array $values ) : static {
		$this->primary_selection->where_not_in( $column, $values );
		return $this;
	}

	/**
	 * Add a WHERE BETWEEN clause.
	 *
	 * @param string $column
	 * @param mixed  $from
	 * @param mixed  $to
	 * @param string $boolean
	 * @param bool   $not
	 *
	 * @return static
	 */
	public function where_between( string $column, $from, $to, string $boolean = 'AND', bool $not = false ) : static {
		$this->primary_selection->where_between( $column, $from, $to, $boolean, $not );
		return $this;
	}

	/**
	 * Add a WHERE NOT BETWEEN clause.
	 *
	 * @param string $column
	 * @param mixed  $from
	 * @param mixed  $to
	 *
	 * @return static
	 */
	public function where_not_between( string $column, $from, $to ) : static {
		$this->primary_selection->where_not_between( $column, $from, $to );
		return $this;
	}

	/**
	 * Add a raw WHERE clause.
	 *
	 * @param string $expression Raw SQL
	 * @param array  $bindings   Bindings for placeholders
	 * @param string $boolean    AND or OR
	 *
	 * @return static
	 */
	public function where_raw( string $expression, array $bindings = [], string $boolean = 'AND' ) : static {
		$this->primary_selection->where_raw( $expression, $bindings, $boolean );
		return $this;
	}

	/**
	 * Add a grouped WHERE clause (nested conditions).
	 *
	 * @param callable $callback Receives a new instance for nested conditions
	 * @param string   $boolean  AND or OR
	 *
	 * @return static
	 */
	public function where_group( callable $callback, string $boolean = 'AND' ) : static {
		$this->primary_selection->where_group( $callback, $boolean );
		return $this;
	}

	/**
	 * Add an OR grouped WHERE clause.
	 *
	 * @param callable $callback
	 *
	 * @return static
	 */
	public function or_where_group( callable $callback ) : static {
		$this->primary_selection->or_where_group( $callback );
		return $this;
	}

	/**
	 * Add columns to GROUP BY.
	 *
	 * @param string ...$columns
	 *
	 * @return static
	 */
	public function group_by( string ...$columns ) : static {
		$this->primary_selection->group_by( ...$columns );
		return $this;
	}

	/**
	 * Add an ORDER BY clause (single column with direction).
	 *
	 * Call multiple times to add multiple columns.
	 * Signature: order_by( $column, $direction = 'ASC' )
	 *
	 * @param string $column    Column name
	 * @param string $direction ASC or DESC
	 *
	 * @return static
	 */
	public function order_by( string $column, string $direction = 'ASC' ) : static {
		$this->primary_selection->order_by( $column, $direction );
		return $this;
	}

	/**
	 * Set LIMIT.
	 *
	 * @param int $limit
	 *
	 * @return static
	 */
	public function limit( int $limit ) : static {
		$this->primary_selection->limit( $limit );
		return $this;
	}

	/**
	 * Set OFFSET.
	 *
	 * @param int $offset
	 *
	 * @return static
	 */
	public function offset( int $offset ) : static {
		$this->primary_selection->offset( $offset );
		return $this;
	}

	/*
	|----------------------------------
	| PRIMARY SELECTION JOINS
	|----------------------------------
	| These methods add joins to the primary_selection.
	*/

	/**
	 * Add an INNER JOIN to the primary selection.
	 *
	 * @param string $table    Table to join
	 * @param string $first    First column (e.g., 'users.id')
	 * @param string $operator Comparison operator (e.g., '=')
	 * @param string $second   Second column (e.g., 'orders.user_id')
	 *
	 * @return static
	 */
	public function join( string $table, string $first, string $operator, string $second ) : static {
		$this->primary_selection->join( $table, $first, $operator, $second );
		return $this;
	}

	/**
	 * Add a LEFT JOIN to the primary selection.
	 *
	 * @param string $table
	 * @param string $first
	 * @param string $operator
	 * @param string $second
	 *
	 * @return static
	 */
	public function left_join( string $table, string $first, string $operator, string $second ) : static {
		$this->primary_selection->left_join( $table, $first, $operator, $second );
		return $this;
	}

	/**
	 * Add a RIGHT JOIN to the primary selection.
	 *
	 * @param string $table
	 * @param string $first
	 * @param string $operator
	 * @param string $second
	 *
	 * @return static
	 */
	public function right_join( string $table, string $first, string $operator, string $second ) : static {
		$this->primary_selection->right_join( $table, $first, $operator, $second );
		return $this;
	}

	/**
	 * Add a CROSS JOIN to the primary selection.
	 *
	 * @param string $table
	 *
	 * @return static
	 */
	public function cross_join( string $table ) : static {
		$this->primary_selection->cross_join( $table );
		return $this;
	}

	/*
	|----------------------------------
	| UNION METHODS
	|----------------------------------
	*/

	/**
	 * Add a UNION (removes duplicates).
	 *
	 * Pass a SelectionIntent or a callable that builds one.
	 *
	 * @param SelectionIntent|callable $selection
	 *
	 * @return static
	 */
	public function union( $selection ) : static {
		$this->add_union( 'UNION', $selection );
		return $this;
	}

	/**
	 * Add a UNION ALL (keeps duplicates).
	 *
	 * @param SelectionIntent|callable $selection
	 *
	 * @return static
	 */
	public function union_all( $selection ) : static {
		$this->add_union( 'UNION ALL', $selection );
		return $this;
	}

	/**
	 * Internal: Process and store a union.
	 *
	 * @param string                    $type UNION or UNION ALL
	 * @param SelectionIntent|callable $selection
	 *
	 * @return void
	 *
	 * @throws \InvalidArgumentException
	 */
	private function add_union( string $type, $selection ) : void {
		// If callable, invoke it with the builder
		if ( is_callable( $selection ) ) {
			$selection = call_user_func( $selection, $this->builder );
		}

		if ( ! $selection instanceof SelectionIntent ) {
			throw new \InvalidArgumentException(
				'Union selection must be a SelectionIntent or callable returning one'
			);
		}

		$this->unions[] = UnionIntent::make( $type, $selection );
	}

	/*
	|----------------------------------
	| INTERFACE IMPLEMENTATION
	|----------------------------------
	*/

	/**
	 * Static factory method.
	 *
	 * @param SQLBuilder $builder
	 *
	 * @return static
	 */
	public static function make( SQLBuilder $builder ) : static {
		return new static( $builder );
	}

	/**
	 * Create a new instance (for nested conditions, etc.).
	 *
	 * @return static
	 */
	public function new_instance() : static {
		return static::make( $this->builder );
	}

	/**
	 * Build the SQL query string.
	 *
	 * @return string
	 */
	public function build() : string {
		// TODO: Implement rendering with composite joins and unions
		// For now, delegate to builder which will render the primary selection
		return $this->builder->build();
	}

	/**
	 * Build the raw SQL with parameters interpolated (debugging).
	 *
	 * @return string
	 */
	public function build_raw() : string {
		// TODO: Implement
		throw new \Exception( 'build_raw() not yet implemented for CompositeSelectionIntent' );
	}

	/**
	 * Get all parameter bindings.
	 *
	 * Collects from:
	 * 1. Primary selection's WHERE conditions
	 * 2. Union selections' WHERE conditions (in order)
	 *
	 * @return array
	 */
	public function get_bindings() : array {
		$bindings = $this->primary_selection->get_bindings();

		foreach ( $this->unions as $union ) {
			$union_selection = $union->get_selection();
			$bindings = array_merge( $bindings, $union_selection->get_bindings() );
		}

		return $bindings;
	}

	/*
	|----------------------------------
	| ACCESSORS
	|----------------------------------
	*/

	/**
	 * Get the primary SelectionIntent.
	 *
	 * @return SelectionIntent
	 */
	public function get_primary_selection() : SelectionIntent {
		return $this->primary_selection;
	}

	/**
	 * Get composite joins (beyond primary's internal joins).
	 *
	 * @return array
	 */
	public function get_composite_joins() : array {
		return $this->composite_joins;
	}

	/**
	 * Get all union intents.
	 *
	 * @return array<UnionIntent>
	 */
	public function get_unions() : array {
		return $this->unions;
	}

	/**
	 * Reset the composite selection.
	 *
	 * @return static
	 */
	public function reset() : static {
		$this->primary_selection = SelectionIntent::make( $this->builder );
		$this->composite_joins = [];
		$this->unions = [];
		return $this;
	}

}