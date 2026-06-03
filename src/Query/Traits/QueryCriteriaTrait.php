<?php
/**
 * Query Criteria Trait file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\Traits
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\Traits;

use Callismart\DBPrism\Query\QueryIntents\SelectionIntent;
use Callismart\DBPrism\Query\SQLBuilder;
use LogicException;

/**
 * Provides fluent methods for building query conditions and managing bindings.
 */
trait QueryCriteriaTrait {
    /**
     * @var array $conditions Array of structured condition groups.
     */
    protected array $conditions = [];

    /**
     * @var array $bindings Positional parameter values.
     */
    protected array $bindings = [];

    /**
     * Add a basic WHERE clause.
     * 
     * @param string $column
     * @param string $operator
     * @param mixed  $value
     * @param string $boolean
     * @return static
     */
    public function where( string $column, string $operator, $value, string $boolean = 'AND' ) : static {
        $this->conditions[] = [
            'type'     => 'Basic',
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => $boolean
        ];

        $this->bindings[] = $value;

        return $this;
    }

    /**
     * Add a column-to-column comparison filter layer.
     * 
     * @param string $first_column
     * @param string $operator
     * @param string $second_column
     * @param string $boolean
     * @return static
     */
    public function where_column( string $first_column, string $operator, string $second_column, string $boolean = 'AND' ) : static {
        $this->conditions[] = [
            'type'          => 'Column',
            'first_column'  => $first_column,
            'operator'      => $operator,
            'second_column' => $second_column,
            'boolean'       => $boolean
        ];

        // Note: No values are added to $this->bindings 
        // because columns are not parameters!
        return $this;
    }

    /**
     * Add an OR WHERE clause.
     * 
     * @param string $column
     * @param string $operator
     * @param mixed  $value
     * @return static
     */
    public function or_where( string $column, string $operator, $value ) : static {
        return $this->where( $column, $operator, $value, 'OR' );
    }

    /**
     * Add a WHERE IS NULL clause.
     * 
     * @param string $column
     * @param string $boolean
     * @param bool   $not
     * @return static
     */
    public function where_null( string $column, string $boolean = 'AND', bool $not = false ) : static {
        $this->conditions[] = [
            'type'    => 'Null',
            'column'  => $column,
            'boolean' => $boolean,
            'not'     => $not
        ];

        return $this;
    }

    /**
     * Add a WHERE IS NOT NULL clause.
     * 
     * @param string $column
     * @param string $boolean
     * @return static
     */
    public function where_not_null( string $column, string $boolean = 'AND' ) : static {
        return $this->where_null( $column, $boolean, true );
    }

    /**
     * Add an OR WHERE IS NULL clause.
     * 
     * @param string $column The target column.
     * @return static Fluent builder instance.
     */
    public function or_where_null( string $column ) : static {
        return $this->where_null( $column, 'OR' );
    }

    /**
     * Add an OR WHERE IS NOT NULL clause.
     * 
     * @param string $column The target column.
     * @return static Fluent builder instance.
     */
    public function or_where_not_null( string $column ) : static {
        return $this->where_null( $column, 'OR', true );
    }

    /**
     * Add a WHERE IN / NOT IN clause.
     * 
     * @param string $column   The target column.
     * @param array  $values   The set of values for comparison.
     * @param string $boolean  Logical connector (AND / OR).
     * @param bool   $not      Whether to negate the condition (NOT IN).
     * @throws \InvalidArgumentException If values array is empty.
     * @return static
     */
    public function where_in( string $column, array $values, string $boolean = 'AND', bool $not = false ) : static {
        if ( empty( $values ) ) {
            throw new \InvalidArgumentException( 'where_in values cannot be empty.' );
        }

        $this->conditions[] = [
            'type'    => 'In',
            'column'  => $column,
            'values'  => $values,
            'boolean' => $boolean,
            'not'     => $not,
        ];

        foreach ( $values as $value ) {
            $this->bindings[] = $value;
        }

        return $this;
    }

    /**
     * Add a WHERE NOT IN clause.
     * 
     * @param string $column The target column.
     * @param array  $values The set of values to exclude.
     * @return static
     */
    public function where_not_in( string $column, array $values ) : static {
        return $this->where_in( $column, $values, 'AND', true );
    }

    /**
     * Add a WHERE BETWEEN / NOT BETWEEN clause.
     * 
     * @param string $column
     * @param mixed  $from
     * @param mixed  $to
     * @param string $boolean
     * @param bool   $not
     * @return static
     */
    public function where_between( string $column, $from, $to, string $boolean = 'AND', bool $not = false ) : static {
        $this->conditions[] = [
            'type'    => 'Between',
            'column'  => $column,
            'values'  => [ $from, $to ],
            'boolean' => $boolean,
            'not'     => $not,
        ];

        $this->bindings[] = $from;
        $this->bindings[] = $to;

        return $this;
    }

    /**
     * Add a WHERE NOT BETWEEN clause.
     * 
     * @param string $column
     * @param mixed  $from
     * @param mixed  $to
     * @return static
     */
    public function where_not_between( string $column, $from, $to ) : static {
        return $this->where_between( $column, $from, $to, 'AND', true );
    }

    /**
     * Add a raw WHERE clause segment.
     * 
     * @param string $expression Raw SQL expression (must be safe).
     * @param array  $bindings   Optional bindings for placeholders.
     * @param string $boolean
     * @return static
     */
    public function where_raw( string $expression, array $bindings = [], string $boolean = 'AND' ) : static {
        $this->conditions[] = [
            'type'       => 'Raw',
            'expression' => $expression,
            'boolean'    => $boolean,
        ];

        foreach ( $bindings as $binding ) {
            $this->bindings[] = $binding;
        }

        return $this;
    }

    /**
     * Add a grouped WHERE clause using a nested condition set.
     * 
     * @param callable $callback Receives a new query instance for grouping.
     * @param string   $boolean  Logical connector (AND / OR).
     * @return static
     */
    public function where_group( callable $callback, string $boolean = 'AND' ) : static {
        $group = $this->get_selection_intent();

        $callback( $group );

        $this->conditions[] = [
            'type'       => 'Group',
            'conditions' => $group->get_conditions(),
            'boolean'    => $boolean,
        ];

        // Merge bindings in order.
        foreach ( $group->get_bindings() as $binding ) {
            $this->bindings[] = $binding;
        }

        return $this;
    }

    /**
     * Add an OR grouped WHERE clause.
     * 
     * @param callable $callback
     * @return static
     */
    public function or_where_group( callable $callback ) : static {
        return $this->where_group( $callback, 'OR' );
    }

    /**
     * Add a where exists clause with a closure that receives a new query instance.
     * 
     * @param callable $callback Receives a new query instance to build the subquery.
     * @param string   $boolean  Logical connector (AND / OR).
     * @param bool     $not      Whether to negate the condition (NOT EXISTS).
     * @return static
     */
    public function where_exists( callable $callback, string $boolean = 'AND', bool $not = false ) : static {        
        $subquery = $this->get_selection_intent();
        $callback( $subquery );

        $this->conditions[] = [
            'type'     => 'Exists',
            'subquery' => $subquery,
            'boolean'  => $boolean,
            'not'      => $not,
        ];

        foreach ( $subquery->get_bindings() as $binding ) {
            $this->bindings[] = $binding;
        }

        return $this;
    }

    /**
     * Add an OR WHERE EXISTS clause.
     * 
     * @param callable $callback
     * @return static
     */
    public function or_where_exists( callable $callback ) : static {
        return $this->where_exists( $callback, 'OR' );
    }

    /**
     * Add a WHERE NOT EXISTS clause.
     * 
     * @param callable $callback
     * @return static
     */
    public function where_not_exists( callable $callback ) : static {
        return $this->where_exists( $callback, 'AND', true );
    }

    /**
     * Add a basic WHERE LIKE clause with automatic value escaping.
     * @param string $column   The target column.
     * @param string $value    The search pattern (e.g., '%term%').
     * @param string $boolean  Logical connector (AND / OR).
     * @param bool   $not      Whether to negate (NOT LIKE).
     * @param bool   $is_pre_escaped Internal flag to skip double-escaping from helpers.
     * @return static
     */
    public function where_like( string $column, string $value, string $boolean = 'AND', bool $not = false, bool $is_pre_escaped = false ) : static {
        $this->conditions[] = [
            'type'    => 'Like',
            'column'  => $column,
            'boolean' => $boolean,
            'not'     => $not
        ];

        // If it's from where_contains/starts/ends, skip parsing to avoid double-escaping
        $this->bindings[] = $is_pre_escaped ? $value : $this->escape_like_value( $value );

        return $this;
    }

    /**
     * Add an OR WHERE LIKE clause.
     * 
     * @param string $column The target column.
     * @param string $value  The search pattern.
     * @param bool   $is_pre_escaped Internal flag to skip double-escaping from helpers.
     * @return static
     */
    public function or_where_like( string $column, string $value, bool $is_pre_escaped = false ) : static {
        return $this->where_like( $column, $value, 'OR', false, $is_pre_escaped );
    }

    /**
     * Add a WHERE NOT LIKE clause.
     * 
     * @param string $column The target column.
     * @param string $value  The search pattern.
     * @param bool   $is_pre_escaped Internal flag to skip double-escaping from helpers.
     * @return static
     */
    public function where_not_like( string $column, string $value, bool $is_pre_escaped = false ) : static {
        return $this->where_like( $column, $value, 'AND', true, $is_pre_escaped );
    }

    /**
     * Add an OR WHERE NOT LIKE clause.
     * 
     * @param string $column The target column.
     * @param string $value  The search pattern.
     * @param bool   $is_pre_escaped Internal flag to skip double-escaping from helpers.
     * @return static
     */
    public function or_where_not_like( string $column, string $value, bool $is_pre_escaped = false ) : static {
        return $this->where_like( $column, $value, 'OR', true, $is_pre_escaped );
    }

    /**
     * Add a "contains" search (wraps term in % %).
     * 
     * @param string $column The target column.
     * @param string $value  The search term (will be escaped).
     * @param string $boolean Logical connector (AND / OR).
     * @param bool   $not    Whether to negate (NOT LIKE).
     * @return static
     */
    public function where_contains( string $column, string $value, string $boolean = 'AND', bool $not = false ) : static {
        $pattern = '%' . $this->escape_like_term( $value ) . '%';
        return $this->where_like( $column, $pattern, $boolean, $not, true );
    }

    /**
     * Add a "NOT contains" search.
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @return static
     */
    public function where_not_contains( string $column, string $value ) : static {
        return $this->where_contains( $column, $value, 'AND', true );
    }

    /**
     * Add an "OR contains" search.
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @return static
     */
    public function or_where_contains( string $column, string $value ) : static {
        return $this->where_contains( $column, $value, 'OR' );
    }

    /**
     * Add an "OR NOT contains" search.
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @return static
     */
    public function or_where_not_contains( string $column, string $value ) : static {
        return $this->where_contains( $column, $value, 'OR', true );
    }

    /**
     * Add a "starts with" search (appends % to term).
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @param string $boolean Logical connector (AND / OR).
     * @param bool   $not    Whether to negate (NOT LIKE).
     * @return static
     */
    public function where_starts_with( string $column, string $value, string $boolean = 'AND', bool $not = false ) : static {
        $pattern = $this->escape_like_term( $value ) . '%';
        return $this->where_like( $column, $pattern, $boolean, $not, true );
    }

    /**
     * Add a "NOT starts with" search.
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @return static
     */
    public function where_not_starts_with( string $column, string $value ) : static {
        return $this->where_starts_with( $column, $value, 'AND', true );
    }

    /**
     * Add an "OR starts with" search.
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @return static
     */
    public function or_where_starts_with( string $column, string $value ) : static {
        return $this->where_starts_with( $column, $value, 'OR' );
    }

    /**
     * Add an "OR NOT starts with" search.
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @return static
     */
    public function or_where_not_starts_with( string $column, string $value ) : static {
        return $this->where_starts_with( $column, $value, 'OR', true );
    }

    /**
     * Add an "ends with" search (prepends % to term).
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @param string $boolean Logical connector (AND / OR).
     * @param bool   $not    Whether to negate (NOT LIKE).
     * @return static
     */
    public function where_ends_with( string $column, string $value, string $boolean = 'AND', bool $not = false ) : static {
        $pattern = '%' . $this->escape_like_term( $value );
        return $this->where_like( $column, $pattern, $boolean, $not, true );
    }

    /**
     * Add a "NOT ends with" search.
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @return static
     */
    public function where_not_ends_with( string $column, string $value ) : static {
        return $this->where_ends_with( $column, $value, 'AND', true );
    }

    /**
     * Add an "OR ends with" search.
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @return static
     */
    public function or_where_ends_with( string $column, string $value ) : static {
        return $this->where_ends_with( $column, $value, 'OR' );
    }

    /**
     * Add an "OR NOT ends with" search.
     * 
     * @param string $column The target column.
     * @param string $value  The search term.
     * @return static
     */
    public function or_where_not_ends_with( string $column, string $value ) : static {
        return $this->where_ends_with( $column, $value, 'OR', true );
    }

    /**
     * Escapes the internal search term to prevent user-injected wildcards.
     * Standardizes on '=' as the framework escape character.
     * @param string $term
     * @return string
     */
    public function escape_like_term( string $term ) : string {
        return str_replace( ['=', '%', '_'], ['==', '=%', '=_'], $term );
    }

    /**
     * Parse and escape an explicit developer-provided LIKE pattern.
     * * This ensures that edge wildcards ('%' or '_') are preserved as functional SQL rules,
     * while any internal wildcards or escape characters within the actual search phrase 
     * are cleanly isolated.
     * @param string $value The raw pattern string (e.g., '%10% off%').
     * @return string The cross-engine sanitized pattern string.
     */
    public function escape_like_value( string $value ) : string {
        if ( '' === $value ) {
            return '';
        }

        // Capture any sequence of leading wildcards.
        $left_wildcards = '';
        while ( str_starts_with( $value, '%' ) || str_starts_with( $value, '_' ) ) {
            $left_wildcards .= substr( $value, 0, 1 );
            $value = substr( $value, 1 );
        }

        // Capture any sequence of trailing wildcards.
        $right_wildcards = '';
        while ( str_ends_with( $value, '%' ) || str_ends_with( $value, '_' ) ) {
            $right_wildcards = substr( $value, -1 ) . $right_wildcards;
            $value = substr( $value, 0, -1 );
        }

        // The remaining core text is literal text; escape it canonically.
        $escaped_core = str_replace( ['=', '%', '_'], ['==', '=%', '=_'], $value );

        // Reassemble the clean string pattern payload.
        return $left_wildcards . $escaped_core . $right_wildcards;
    }

    /**
     * Get tracked parameters.
     * 
     * @return array
     */
    public function get_bindings() : array {
        return $this->bindings;
    }

    /**
     * Get structured conditions.
     * 
     * @return array
     */
    public function get_conditions() : array {
        return $this->conditions;
    }

    /**
     * Get a new selection intent instance for nested condition building.
     * 
     * @return SelectionIntent
     */
    protected function get_selection_intent() : SelectionIntent {        
        if ( ! property_exists( $this, 'builder' ) || ! ( $this->builder instanceof SQLBuilder ) ) {
            throw new LogicException( 
                \sprintf( 
                    'The %s trait requires a $builder property of type %s to create nested query instances.',
                    static::class, SQLBuilder::class 
                )
            );
        }

        $new_builder        = new SQLBuilder( $this->builder->get_engine() );
        $selection_intent   = SelectionIntent::make( $new_builder );

        $new_builder->set_type( 'SELECT' );
        $new_builder->set_active_intent( $selection_intent );

        return $selection_intent;

    }
}