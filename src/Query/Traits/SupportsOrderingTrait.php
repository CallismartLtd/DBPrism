<?php
/**
 * Supports Ordering Trait file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\Traits;

use Callismart\DBPrism\Utils\CaseExpression;

/**
 * Provides fluent methods for managing SQL ORDER BY clauses.
 * 
 * 
 * This trait can be shared across any Query Intent that supports structural
 * sorting, such as SelectionIntent, CompoundQueryIntent, or targeted Delete/Update intents.
 */
trait SupportsOrderingTrait {
    use ColumnNormalizerTrait;
    /**
     * @var array $orders Structured ordering definitions (column and direction).
     */
    protected array $orders = [];

    /**
     * Order bindings.
     * 
     * @var string[] $orders_bindings
     */
    protected array $orders_bindings = [];

    /**
     * Add a column sorting rule to the ORDER BY clause.
     * 
     * @param string $column    The database column name or dot-notation identifier.
     * @param string $direction Sort direction. Must be either 'ASC' or 'DESC'. Default 'ASC'.
     * @return static Fluent builder instance.
     */
    public function order_by( string $column, string $direction = 'ASC' ) : static {
        $this->orders[] = [
            'type'      => 'string',
            'column'    => $column,
            'direction' => strtoupper( trim( $direction ) )
        ];
        return $this;
    }

    /**
     * Sort rows flexibly using a custom conditional ANSI CASE expression hierarchy.
     * * @param callable $callback  Closure receiving a clean CaseExpression DTO to prioritize branches.
     * @param string   $direction Sort direction. Must be either 'ASC' or 'DESC'. Default 'ASC'.
     * @return static Fluent builder instance.
     */
    public function order_by_case( callable $callback, string $direction = 'ASC' ) : static {

        $case_expression = new CaseExpression();

        $callback( $case_expression );

        $this->orders[] = [
            'type'      => 'case_expression',
            'column'    => $case_expression,
            'direction' => strtoupper( trim( $direction ) )
        ];

        // Chronological Parameter Cascading
        // In SQL execution, ORDER BY bindings sit right after GROUP BY parameters!
        foreach ( $case_expression->get_branches() as $branch ) {
            foreach ( $branch['criteria']->get_bindings() as $condition_binding ) {
                if ( $this->should_bind_value( $condition_binding ) ) {
                    $this->orders_bindings[] = $condition_binding;
                }

            }

            if ( $this->should_bind_value( $branch['then_value'] ) ) {
                $this->orders_bindings[] = $branch['then_value'];
            }
        }

        $else_value = $case_expression->get_else();
        if ( $this->should_bind_value( $else_value ) ) {
            $this->orders_bindings[] = $else_value;
        }

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

    /**
     * Determine if a value should be added to the parameter bindings array.
     * 
     * @param mixed $value
     * @return bool
     */
    protected function should_bind_value( mixed $value ) : bool {
        // If it's a string expression or function call, bypass parameterization
        if ( is_string( $value ) && static::is_sql_expression( $value ) ) {
            return false;
        }
        
        return true;
    }
}