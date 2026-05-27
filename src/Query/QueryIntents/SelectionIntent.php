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
use Callismart\DBPrism\Query\Traits\SupportsOrderingTrait;
use Callismart\DBPrism\Query\Traits\SupportsSlicingTrait;
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
    SupportsSlicingTrait, ColumnNormalizerTrait;

    /**
     * @var array $columns Columns to be selected.
     */
    protected array $columns = [];

    /**
     * @var string $table_name The primary table for the FROM clause.
     */
    protected string $table_name = '';

    /**
     * @var array $joins Structured join definitions.
     */
    protected array $joins = [];

    /**
     * @var int|null $limit Maximum number of rows to return.
     */
    protected ?int $limit = null;

    /**
     * @var int|null $offset Number of rows to skip.
     */
    protected ?int $offset = null;

    /**
     * @var bool $distinct
     */
    protected bool $distinct = false;

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
        return $this->bindings;
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
     * Get all defined joins.
     * 
     * @return array
     */
    public function get_joins() : array {
        return $this->joins;
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

    public function new_instance() : static {
        return new static( $this->builder );
    }
}