<?php
/**
 * Supports Slicing Trait file.
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\Traits
 * @since 0.2.0
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\Traits;

/**
 * Provides fluent methods for managing SQL LIMIT and OFFSET boundaries.
 * 
 * This trait encapsulates pagination constraints, allowing execution sets
 * like SelectionIntent and CompoundQueryIntent to control row counts.
 * 
 * @since 0.2.0
 */
trait SupportsSlicingTrait {
    /**
     * @var int|null $limit Maximum number of rows to return.
     */
    protected ?int $limit = null;

    /**
     * @var int|null $offset Number of rows to skip.
     */
    protected ?int $offset = null;

    /**
     * Set the maximum row window constraint (LIMIT clause).
     * 
     * @param int $limit The maximum number of records to pull.
     * @return static Fluent builder instance.
     */
    public function limit( int $limit ) : static {
        $this->limit = $limit;
        return $this;
    }

    /**
     * Set the structural starting boundary offset (OFFSET clause).
     * 
     * @param int $offset The number of preceding records to skip.
     * @return static Fluent builder instance.
     */
    public function offset( int $offset ) : static {
        $this->offset = $offset;
        return $this;
    }

    /**
     * Retrieve the defined row limit constraint.
     * 
     * @return int|null The row threshold count or null if unbound.
     */
    public function get_limit() : ?int {
        return $this->limit;
    }

    /**
     * Retrieve the active pagination starting offset constraint.
     * 
     * @return int|null The row skipped counter or null if unbound.
     */
    public function get_offset() : ?int {
        return $this->offset;
    }
}