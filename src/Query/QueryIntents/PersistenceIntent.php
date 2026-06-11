<?php
/**
 * Persistence Query Intent class file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\QueryIntents
 * @since 0.2.0
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\QueryIntents;

use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Query\Traits\ColumnNormalizerTrait;
use Callismart\DBPrism\Query\Traits\QueryCriteriaTrait;
use InvalidArgumentException;
use Callismart\DBPrism\Query\Traits\SQLBuilderStrategyTrait;
use Callismart\DBPrism\Query\Traits\SupportsUnionsTrait;
use Callismart\DBPrism\Utils\CaseExpression;

/**
 * Represents an intent to persist or modify data (INSERT/UPDATE).
 * 
 * Encapsulates the dataset and column mappings for DML operations.
 * 
 * @since 0.2.0
 */
class PersistenceIntent implements QueryIntentInterface {
    use QueryCriteriaTrait, SQLBuilderStrategyTrait, SupportsUnionsTrait,
    ColumnNormalizerTrait;
    /**
     * @var string $table_name The target table name.
     */
    private string $table_name;

    /**
     * The data payload for the operation.
     * 
     * @var array $data
     */
    private array $data = [];

    /**
     * Whether this is a multi-row operation.
     * 
     * @var bool $is_multi
     */
    private bool $is_multi = false;

    /**
     * Constructor.
     * 
     * @param string $table_name
     */
    private function __construct( string $table_name ) {
        $this->table_name = $table_name;
    }

    /**
     * Alias for values
     * 
     * @param array $data
     * @return static Fluent
     */
    public function set( array $data ) : static {
        return $this->values( $data );
    }

    /**
     * Set data for a single row operation.
     * 
     * @param array $data Column => Value pairs.
     * @return static
     * @throws \InvalidArgumentException If a CaseExpression is supplied on 
     * an operation that isn't an UPDATE.
     */
    public function values( array $data ) : static {
        foreach ( $data as $value ) {
            if ( $value instanceof CaseExpression ) {
                throw new \InvalidArgumentException(
                    "Symmetry Violation: CaseExpression cannot be passed directly into raw value sets. " .
                    "Use the dedicated 'set_case()' method to apply dynamic updates."
                );
            }
        }

        $this->is_multi = false;
        
        $this->data = array_merge( $this->data, $data );

        return $this;
    }

    /**
     * Set data for multiple rows (Bulk Insert).
     * 
     * @param array $rows Array of column => value arrays.
     * @return static
     * @throws InvalidArgumentException
     */
    public function multi_values( array $rows ) : static {
        if ( empty( $rows ) ) {
            throw new InvalidArgumentException( 'Multi-values intent requires at least one row.' );
        }

        // Must be an array of associative arrays.
        foreach ( $rows as $row ) {
            if ( ! is_array( $row ) || array_values( $row ) === $row ) {
                throw new InvalidArgumentException( 'Each row in multi-values intent must be an associative array of column => value pairs.' );
            }

            foreach ( $row as $value ) {
                if ( $value instanceof CaseExpression ) {
                    throw new InvalidArgumentException(
                        "Symmetry Violation: CaseExpression cannot be passed directly into raw value sets. " .
                        "Use the dedicated 'set_case()' method to apply dynamic updates."
                    );
                }
            }
        }

        $this->is_multi = true;
        $this->data     = array_merge( $this->data, $rows );

        return $this;
    }

    /**
     * Update an individual database column using a custom dynamic ANSI CASE evaluation block.
     * 
     * Note: This method is only applicable to UPDATE statements.
     * Attempting to use it in an INSERT context will throw an exception during rendering, 
     * as CASE expressions are not valid in value sets for new records.
     * 
     * @param string   $column   The target database column to be updated.
     * @param callable $callback Closure receiving a clean CaseExpression DTO to formulate branches.
     * @return static Fluent builder instance.
     */
    public function set_case( string $column, callable $callback ) : static {
        $case_expression = new \Callismart\DBPrism\Utils\CaseExpression();
        $callback( $case_expression );

        // Because values() throws on CaseExpression, appending it directly allows 
        // update statements to capture it seamlessly while keeping insert blocks clean.
        $this->data[ trim( $column ) ] = $case_expression;

        return $this;
    }

    /**
     * Retrieve the table name.
     * 
     * @return string
     */
    public function get_table_name() : string {
        return $this->table_name;
    }

    /**
     * Retrieve the dataset.
     * 
     * @return array
     */
    public function get_data() : array {
        return $this->data;
    }

    /**
     * Check if the operation is multi-row.
     * 
     * @return bool
     */
    public function is_multi() : bool {
        return $this->is_multi;
    }

    /**
     * Get bindings for the operation.
     * 
     * Flattens the internal data into a one-dimensional array of values.
     * 
     * @return array
     */
    public function get_bindings() : array {
        if ( null !== $this->custom_bindings ) {
            return $this->custom_bindings;
        }
        
        $set_bindings = [];

        if ( $this->is_multi ) {
            // Bulk INSERT contexts
            foreach ( $this->data as $row ) {
                foreach ( $row as $val ) {
                    $set_bindings = array_merge( $set_bindings, $this->extract_value_bindings( $val ) );
                }
            }
        } else {
            // Single row INSERT or UPDATE context
            foreach ( $this->data as $val ) {
                $set_bindings = array_merge( $set_bindings, $this->extract_value_bindings( $val ) );
            }
        }

        // Get the WHERE criteria bindings from the trait
        $where_bindings = $this->bindings; 

        return array_merge( $set_bindings, $where_bindings );
    }

    /**
     * Safely unpack value tokens or cascading expression criteria bindings.
     * 
     * @param mixed $value
     * @return array
     */
    private function extract_value_bindings( mixed $value ) : array {
        if ( $value instanceof CaseExpression ) {
            $extracted = [];
            foreach ( $value->get_branches() as $branch ) {
                // Pull bindings generated inside the WHEN condition branch sandbox
                foreach ( $branch['criteria']->get_bindings() as $condition_binding ) {
                    $extracted[] = $condition_binding;
                }

                // Pull the output THEN value if it is an executable parameter
                if ( ! is_object( $branch['then_value'] ) && $this->should_bind_value( $branch['then_value'] ) ) {
                    $extracted[] = $branch['then_value'];
                }
            }

            // C. Pull the final fallback ELSE binding if present
            $else_value = $value->get_else();
            if ( null !== $else_value && ! is_object( $else_value ) && $this->should_bind_value( $else_value ) ) {
                $extracted[] = $else_value;
            }

            return $extracted;
        }

        // Standard scalars or raw expression tokens
        if ( is_object( $value ) || ! $this->should_bind_value( $value ) ) {
            return [];
        }

        return [ $value ];
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

    /**
     * Static factory.
     * 
     * @param string     $table_name
     * @param SQLBuilder $builder
     * @return static Fluent
     */
    public static function make( string $table_name, SQLBuilder $builder ) : static {
        $static          = new static( $table_name );
        $static->builder = $builder;

        return $static;
    }
}