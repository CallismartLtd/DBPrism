<?php
/**
 * Supports Joins Trait file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\Traits;

/**
 * Trait that provides support for defining JOIN clauses in a query.
 * 
 * This trait allows a query intent to specify multiple JOIN operations,
 * including the type of join, the table to join with, and the join conditions.
 * 
 * @since 0.2.0
 */
trait SupportsJoinsTrait {
    /**
     * @var array $joins Structured join definitions.
     */
    protected array $joins = [];

    /**
     * Internal helper to standardize join data structures.
     * 
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     * @param string $type
     * @return $this
     */
    protected function add_join_entry( string $table, string $first, string $operator, string $second, string $type ) : static {
        $this->joins[] = compact( 'table', 'first', 'operator', 'second', 'type' );
        return $this;
    }

    /**
     * Add an INNER JOIN clause.
     * 
     * @param string $table    Table to join.
     * @param string $first    First column in condition.
     * @param string $operator Comparison operator.
     * @param string $second   Second column in condition.
     * @return $this
     */
    public function join( string $table, string $first, string $operator, string $second ) : static {
        return $this->add_join_entry( $table, $first, $operator, $second, 'INNER' );
    }

    /**
     * Add a LEFT JOIN clause.
     * 
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     * @return $this
     */
    public function left_join( string $table, string $first, string $operator, string $second ) : static {
        return $this->add_join_entry( $table, $first, $operator, $second, 'LEFT' );
    }

    /**
     * Add a RIGHT JOIN clause.
     * 
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     * @return $this
     */
    public function right_join( string $table, string $first, string $operator, string $second ) : static {
        return $this->add_join_entry( $table, $first, $operator, $second, 'RIGHT' );
    }

    /**
     * Add a CROSS JOIN clause.
     * 
     * @param string $table
     * @return $this
     */
    public function cross_join( string $table ) : static {
        return $this->add_join_entry( $table, '', '', '', 'CROSS' );
    }

    /**
     * Get all defined joins.
     * 
     * @return array
     */
    public function get_joins() : array {
        return $this->joins;
    }
}