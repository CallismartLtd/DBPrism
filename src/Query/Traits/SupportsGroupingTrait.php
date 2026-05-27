<?php
/**
 * Supports Grouping Trait file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\Traits
 * @since 0.2.0
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\Traits;

/**
 * Provides fluent methods for managing SQL GROUP BY clauses.
 * * This trait encapsulates grouping capabilities, safely isolating raw row aggregation
 * functions within relevant structural queries like SelectionIntent.
 * 
 * @since 0.2.0
 */
trait SupportsGroupingTrait {
    /**
     * @var array $groups Tracking array of grouping column strings.
     */
    protected array $groups = [];

    /**
     * Add one or more columns to the GROUP BY clause.
     * 
     * @param string ...$columns Variadic list of column identifiers.
     * @return static Fluent builder instance.
     */
    public function group_by( string ...$columns ) : static {
        $this->groups = array_merge( $this->groups, $columns );
        return $this;
    }

    /**
     * Retrieve all specified grouping columns for rendering.
     * 
     * @return array List of raw grouping column strings.
     */
    public function get_groups() : array {
        return $this->groups;
    }
}