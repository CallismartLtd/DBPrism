<?php
/**
 * Supports Joins Trait file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\Traits;

use Callismart\DBPrism\Query\QueryIntents\JoinCriteria;

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
     * @var array $joins_bindings Parameter values tracking originating inside JOIN clauses.
     */
    protected array $joins_bindings = [];

    /**
     * Internal helper to standardize join data structures.
     * 
     * @param string $table
     * @param string $first
     * @param string $operator
     * @param string $second
     * @param string $type
     * @return static
     */
    protected function add_join_entry( string $table, string $first, string $operator, string $second, string $type ) : static {
        $this->joins[] = compact( 'table', 'first', 'operator', 'second', 'type' );
        return $this;
    }

    /**
     * Add an INNER JOIN clause.
     * 
     * @param string $table    Table to join.
     * @param string|callable $first    First column in condition.
     * @param string|null $operator Comparison operator.
     * @param string|null $second   Second column in condition.
     * @return static
     */
    public function join( string $table, string|callable $first, ?string $operator = null, ?string $second = null ) : static {
        if ( is_callable( $first ) ) {
            return $this->add_advanced_join_entry( $table, $first, 'INNER' );
        }

        return $this->add_join_entry( $table, $first, $operator ?? '', $second ?? '', 'INNER' );
    }

    /**
     * Add a LEFT JOIN clause.
     * 
     * @param string $table
     * @param string|callable $first
     * @param string|null $operator
     * @param string|null $second
     * @return static
     */
    public function left_join( string $table, string|callable $first, ?string $operator = null, ?string $second = null) : static {
        if ( is_callable( $first ) ) {
            return $this->add_advanced_join_entry( $table, $first, 'LEFT' );
        }

        return $this->add_join_entry( $table, $first, $operator ?? '', $second ?? '', 'LEFT' );
    }

    /**
     * Add a RIGHT JOIN clause.
     * 
     * @param string $table
     * @param string|callable $first
     * @param string|null $operator
     * @param string|null $second
     * @return static
     */
    public function right_join( string $table, string|callable $first, ?string $operator = null, ?string $second = null ) : static {
        if ( is_callable( $first ) ) {
            return $this->add_advanced_join_entry( $table, $first, 'RIGHT' );
        }

        return $this->add_join_entry( $table, $first, $operator, $second, 'RIGHT' );
    }

    /**
     * Add a CROSS JOIN clause.
     * 
     * @param string $table
     * @return static
     */
    public function cross_join( string $table ) : static {
        return $this->add_join_entry( $table, '', '', '', 'CROSS' );
    }

    /**
     * Internal helper to standardize advanced multi-conditional join data structures.
     * 
     * @param string   $table
     * @param callable $callback
     * @param string   $type
     * @return static
     */
    /**
     * Internal helper to standardize advanced multi-conditional join data structures.
     * * @param string   $table
     * @param callable $callback
     * @param string   $type
     * @return static
     */
    protected function add_advanced_join_entry( string $table, callable $callback, string $type ) : static {
        // Instantiate the dedicated relational configuration sandbox container
        $sandbox = new JoinCriteria();
        
        $callback( $sandbox );

        $this->joins[] = [
            'table'       => $table,
            'type'        => $type,
            'is_advanced' => true,
            'conditions'  => $sandbox->get_conditions()
        ];

        foreach ( $sandbox->get_bindings() as $binding ) {
            $this->joins_bindings[] = $binding;
        }

        return $this;
    }

    /**
     * Pull tracked join parameter collections up to SelectionIntent::get_bindings().
     * * @return array
     */
    public function get_joins_bindings() : array {
        return $this->joins_bindings;
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