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

use Callismart\DBPrism\Utils\CaseExpression;

/**
 * Provides fluent methods for managing SQL GROUP BY clauses.
 * 
 * This trait encapsulates grouping capabilities, safely isolating raw row aggregation
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
     * Group bindings.
     * 
     * @var string[] $groups_bindings
     */
    protected array $groups_bindings = [];

    /**
     * Add one or more columns to the GROUP BY clause.
     * 
     * @param string ...$columns Variadic list of column identifiers.
     * @return static Fluent builder instance.
     */
    public function group_by( string ...$columns ) : static {
        foreach ( $columns as $column ) {
            $this->groups[] = [
                'type'  => 'string',
                'value' => $column
            ];
        }
        return $this;
    }

    /**
     * Group rows dynamically using conditional ANSI CASE evaluation blocks.
     * * @param callable ...$callables Variadic list of closures receiving a clean CaseExpression DTO.
     * @return static Fluent builder instance.
     */
    public function group_by_case( callable ...$callables ) : static {
        foreach ( $callables as $callback ) {
            $case_expression = new CaseExpression();

            $callback( $case_expression );

            $this->groups[] = [
                'type'  => 'case_expression',
                'value' => $case_expression
            ];

            // Parameter Cascade Synchronization
            // Because group clauses are rendered before HAVING/LIMIT/LOCK, 
            // these parameters must sit chronologically right after the WHERE bindings.
            foreach ( $case_expression->get_branches() as $branch ) {
                foreach ( $branch['criteria']->get_bindings() as $condition_binding ) {
                    $this->groups_bindings[] = $condition_binding;
                }

                if ( ! is_object( $branch['then_value'] ) ) {
                    $this->groups_bindings[] = $branch['then_value'];
                }
            }

            $else_value = $case_expression->get_else();
            if ( null !== $else_value && ! is_object( $else_value ) ) {
                $this->groups_bindings[] = $else_value;
            }
        }

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