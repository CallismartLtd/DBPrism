<?php
/**
 * Union intent class file.
 * 
 * @author Callistus Nwachukwu.
 */

namespace Callismart\DBPrism\Query\QueryIntents;

/**
 * UnionIntent
 *
 * Represents a UNION or UNION ALL operation combining SelectionIntents.
 *
 * @since 0.2.0
 */
class UnionIntent {

	/**
	 * Union type: UNION or UNION ALL.
	 *
	 * @var string
	 */
	private string $type;

	/**
	 * The SelectionIntent to union with.
	 *
	 * @var SelectionIntent
	 */
	private SelectionIntent $selection;

	/**
	 * Constructor.
	 *
	 * @param string $type UNION type
	 * @param SelectionIntent $selection Selection to union
	 */
	private function __construct( string $type, SelectionIntent $selection ) {
		$this->type      = strtoupper( $type );
		$this->selection = $selection;
	}

	/**
	 * Static factory method.
	 *
	 * @param string $type UNION type
	 * @param SelectionIntent $selection Selection to union
	 *
	 * @return self
	 */
	public static function make( string $type, SelectionIntent $selection ) : self {
		return new self( $type, $selection );
	}

	/**
	 * Get UNION type.
	 *
	 * @return string
	 */
	public function get_type() : string {
		return $this->type;
	}

	/**
	 * Get the SelectionIntent.
	 *
	 * @return SelectionIntent
	 */
	public function get_selection() : SelectionIntent {
		return $this->selection;
	}

	/**
	 * Check if this is UNION ALL.
	 *
	 * @return bool
	 */
	public function is_union_all() : bool {
		return 'UNION ALL' === $this->type;
	}

	public function __clone() : void {
		$this->selection = clone $this->selection;
	}

}