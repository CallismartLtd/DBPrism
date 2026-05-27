<?php
/**
 * Supports Ordering Trait file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\Traits;

/**
 * Provides fluent methods for managing SQL ORDER BY clauses.
 * 
 * 
 * This trait can be shared across any Query Intent that supports structural
 * sorting, such as SelectionIntent, CompoundQueryIntent, or targeted Delete/Update intents.
 */
trait SupportsOrderingTrait {
    /**
     * @var array $orders Structured ordering definitions (column and direction).
     */
    protected array $orders = [];

    /**
     * Add a column sorting rule to the ORDER BY clause.
     * 
     * @param string $column    The database column name or dot-notation identifier.
     * @param string $direction Sort direction. Must be either 'ASC' or 'DESC'. Default 'ASC'.
     * @return static Fluent builder instance.
     */
    public function order_by( string $column, string $direction = 'ASC' ) : static {
        $this->orders[] = [
            'column'    => $column,
            'direction' => strtoupper( trim( $direction ) )
        ];
        return $this;
    }

    /**
     * Retrieve all defined ordering definitions for rendering.
     * 
     * @return array Array of arrays containing 'column' and 'direction' keys.
     */
    public function get_orders() : array {
        return $this->orders;
    }
}