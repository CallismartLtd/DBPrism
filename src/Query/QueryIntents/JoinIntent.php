<?php
/**
 * Query Intents - JOIN and UNION
 *
 * Engine-agnostic intent representation for multi-table operations.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\QueryIntents
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Query\QueryIntents;

/**
 * JoinIntent
 *
 * Represents a single JOIN operation in engine-agnostic form.
 *
 * @since 0.2.0
 */
class JoinIntent {

	/**
	 * JOIN type: INNER, LEFT, RIGHT, FULL OUTER.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * Target table name (raw, unquoted).
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * Table alias (optional).
	 *
	 * @var string
	 */
	private string $alias;

	/**
	 * ON condition (raw SQL).
	 *
	 * @var string
	 */
	private string $on;

	/**
	 * Constructor.
	 *
	 * @param string $type JOIN type
	 * @param string $table Table name
	 * @param string $alias Table alias (optional)
	 * @param string $on ON condition (optional)
	 */
	private function __construct( string $type, string $table, string $alias = '', string $on = '' ) {
		$this->type  = strtoupper( $type );
		$this->table = $table;
		$this->alias = $alias;
		$this->on    = $on;
	}

	/**
	 * Static factory method.
	 *
	 * @param string $type JOIN type
	 * @param string $table Table name
	 * @param string $alias Table alias (optional)
	 * @param string $on ON condition (optional)
	 *
	 * @return self
	 */
	public static function make( string $type, string $table, string $alias = '', string $on = '' ) : self {
		return new self( $type, $table, $alias, $on );
	}

	/**
	 * Get JOIN type.
	 *
	 * @return string
	 */
	public function get_type() : string {
		return $this->type;
	}

	/**
	 * Get table name.
	 *
	 * @return string
	 */
	public function get_table() : string {
		return $this->table;
	}

	/**
	 * Get table alias.
	 *
	 * @return string
	 */
	public function get_alias() : string {
		return $this->alias;
	}

	/**
	 * Get ON condition.
	 *
	 * @return string
	 */
	public function get_on() : string {
		return $this->on;
	}

	/**
	 * Check if alias is set.
	 *
	 * @return bool
	 */
	public function has_alias() : bool {
		return ! empty( $this->alias );
	}

	/**
	 * Set the ON condition (fluent).
	 *
	 * @param string $on ON condition
	 *
	 * @return self
	 */
	public function on( string $on ) : self {
		$this->on = $on;
		return $this;
	}

}