<?php
/**
 * Selection Query Intent class file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\QueryIntents
 * @since 0.2.0
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\QueryIntents;

use Callismart\DBPrism\Query\Traits\QueryCriteriaTrait;
use Callismart\DBPrism\Query\Traits\SupportsUnionsTrait;
use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Query\Traits\ColumnNormalizerTrait;
use Callismart\DBPrism\Query\Traits\SQLBuilderStrategyTrait;
use Callismart\DBPrism\Query\Traits\SupportsGroupingTrait;
use Callismart\DBPrism\Query\Traits\SupportsHavingTrait;
use Callismart\DBPrism\Query\Traits\SupportsJoinsTrait;
use Callismart\DBPrism\Query\Traits\SupportsOrderingTrait;
use Callismart\DBPrism\Query\Traits\SupportsSlicingTrait;
use Callismart\DBPrism\Utils\CaseExpression;
use Callismart\DBPrism\Utils\DefaultColumnValue;
use Callismart\DBPrism\Utils\LockMode;

/**
 * Represents an intent to select data from the database.
 * 
 * This class orchestrates the components of a SELECT query, including
 * columns, tables, joins, filtering, grouping, and ordering.
 * 
 * @since 0.2.0
 */
class SelectionIntent implements QueryIntentInterface{
    use QueryCriteriaTrait, SQLBuilderStrategyTrait,
    SupportsUnionsTrait, SupportsGroupingTrait, SupportsOrderingTrait,
    SupportsSlicingTrait, SupportsJoinsTrait, ColumnNormalizerTrait,
    SupportsHavingTrait;

    /**
     * @var array $columns Columns to be selected.
     */
    protected array $columns = [];

    /**
     * @var string $table_name The primary table for the FROM clause.
     */
    protected string $table_name = '';

    /**
     * @var bool $distinct
     */
    protected bool $distinct = false;

    /**
     * @var array $select_bindings Parameters originating from column selections or subqueries.
     */
    protected array $select_bindings = [];

    /**
     * Query lock mode.
     * 
     * @var LockMode
     */
    protected LockMode $lockMode    = LockMode::NONE;

    /**
     * Private constructor to enforce static factory usage.
     */
    private function __construct( SQLBuilder $builder ) {
        $this->builder  = $builder;
    }

    /**
     * Initialize a selection intent with specific columns.
     * 
     * @param string ...$columns Variadic list of column names.
     * @return static
     */
    public function select( string ...$columns ) : static {
        
        if ( empty( $columns ) ) {
            $columns = ['*'];
        }

        foreach ( $columns as $column ) {
            $this->columns[] = $this->normalize_column( $column );
        }
        
        return $this;
    }

    /**
     * Select raw expressions without normalization.
     * 
     * @param string ...$expressions Variadic list of raw expressions.
     * @return static
     */
    public function select_raw( string ...$expressions ) : static {
        foreach ( $expressions as $expr ) {
            $this->columns[] = [
                'type'  => 'expression',
                'value' => DefaultColumnValue::expression( $expr )
            ];
        }

        return $this;
    }

    /**
     * Select ANSI-compliant CASE expressions.
     * 
     * @param callable ...$callables Variadic list of closures,
     * each receiving a clean CaseExpression DTO.
     * @return static
     */
    public function select_case( callable ...$callables ) : static {
        foreach ( $callables as $callback ) {
            $case_expression = new CaseExpression();
            $callback( $case_expression );

            $this->columns[] = [
                'type'  => 'case_expression',
                'value' => $case_expression,
                'alias' => $case_expression->get_alias()
            ];

            // Cascade inner branch parameters straight up
            // into the parent binding sequence.
            foreach ( $case_expression->get_branches() as $branch ) {
                // Pull bindings generated inside the WHEN condition branch sandbox.
                foreach ( $branch['criteria']->get_bindings() as $condition_binding ) {
                    $this->bindings[] = $condition_binding;
                }

                // Pull the output THEN value if it is an executable parameter.
                if ( ! is_object( $branch['then_value'] ) ) {
                    $this->bindings[] = $branch['then_value'];
                }
            }

            // Pull the final fallback ELSE binding if present.
            $else_value = $case_expression->get_else();
            if ( null !== $else_value && ! is_object( $else_value ) ) {
                $this->bindings[] = $else_value;
            }
        }

        return $this;
    }

    /**
     * Append a scalar subquery expression cleanly using an independent query sandbox.
     * 
     * @param callable $callback Configures a fresh SelectionIntent sandbox instance.
     * @param string    $alias    The column output projection naming marker.
     * @return static
     */
    public function select_subquery( callable $callback, string $alias ) : static {
        $subquery_intent = $this->get_selection_intent();

        $callback( $subquery_intent );

        $compiled_sql = $subquery_intent->build();

        if ( str_ends_with( $compiled_sql, ';' ) ) {
            $compiled_sql = rtrim( $compiled_sql, ';' );
        }
        
        $this->columns[] = [
            'type'  => 'expression',
            'value' => DefaultColumnValue::expression( "( {$compiled_sql} )" ),
            'alias' => $alias
        ];

        foreach ( $subquery_intent->get_bindings() as $sub_binding ) {
            $this->select_bindings[] = $sub_binding;
        }

        return $this;
    }

    /**
     * Set the distinct flag
     * 
     * @param bool $value
     * @return static
     */
    public function distinct( bool $value = true ) : static {
        $this->distinct = $value;

        return $this;
    }

    /**
     * Set the source table for the query.
     * 
     * @param string $table The raw table name.
     * @return $this
     */
    public function from( string $table ) : static {
        $this->table_name = $table;
        return $this;
    }

    /**
     * Set shared lock mode
     */
    public function shared_lock() : static {
        return $this->set_lock_mode( LockMode::SHARED );
    }

    /**
     * Set for update lock mode
     */
    public function lock_for_update() {
        return $this->set_lock_mode( LockMode::EXCLUSIVE );
    }

    /**
     * Set no-wait lock mode.
     */
    public function lock_no_wait() : static {
        return $this->set_lock_mode( LockMode::NO_WAIT );
    }

    /**
     * Set lock mode none
     */
    public function lock_none() : static {
        return $this->set_lock_mode( LockMode::NONE );
    }

    /**
     * Set skip lock mode
     */
    public function lock_mode_skip() : static {
        return $this->set_lock_mode( LockMode::SKIP_LOCKED );
    }

    /**
     * Set lock mode.
     * 
     * @param Lockmode $mode
     */
    public function set_lock_mode( LockMode $mode ) : static {
        $this->lockMode = $mode;

        return $this;
    }

    /**
     * Get the value of lock mode.
     */
    public function get_lock_mode() : LockMode {
        return $this->lockMode;
    }

    /**
     * Tells whether lock mode is set.
     * 
     * @return bool
     */
    public function has_lock_mode() : bool {
        return LockMode::NONE !== $this->lockMode;
    }

    /**
     * Retrieve parameter bindings for the query criteria.
     * 
     * @return array
     */
    public function get_bindings() : array {

        if ( null !== $this->custom_bindings ) {
            return $this->custom_bindings;
        }
        
        return \array_merge(
            $this->select_bindings,
            $this->joins_bindings,
            $this->bindings,
            $this->groups_bindings,
            $this->having_bindings,
            $this->orders_bindings
        );
    }

    /**
     * Check distinct flag
     * 
     * @return bool
     */
    public function is_distinct() : bool {
        return $this->distinct;
    }

    /**
     * Get the columns to be selected. Returns ['*'] if none specified.
     * 
     * @return array
     */
    public function get_columns() : array {
        return empty( $this->columns ) ? ['*'] : $this->columns;
    }

    /**
     * Get the primary table name.
     * 
     * @return string
     */
    public function get_table_name() : string {
        return $this->table_name;
    }

    /**
     * Static factory
     * 
     * @param SQLBuilder $builder
     * @return static Fluent
     */
    public static function make( SQLBuilder $builder ) : static {
        return new static( $builder );
    }

    public function __clone() : void {
        $this->builder = clone $this->builder;
        
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