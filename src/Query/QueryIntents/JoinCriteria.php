<?php
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\QueryIntents;

/**
 * Data Transfer Object for collecting specialized multi-conditional JOIN parameters.
 */
class JoinCriteria {
    /**
     * @var array Structured criteria configurations tailored specifically for ON operations.
     */
    protected array $conditions = [];

    /**
     * @var array Isolated variable parameters tracking inside this join layer.
     */
    protected array $bindings = [];

    /**
     * Map a column directly to another column across tables (Standard Equi-Join).
     * 
     * @param string $first_column
     * @param string $operator
     * @param string $second_column
     * @param string $boolean
     * @return $this
     */
    public function on_column( string $first_column, string $operator, string $second_column, string $boolean = 'AND' ) : static {
        $this->conditions[] = [
            'type'          => 'OnColumn',
            'first_column'  => $first_column,
            'operator'      => $operator,
            'second_column' => $second_column,
            'boolean'       => $boolean
        ];
        return $this;
    }

    /**
     * Constrain a join mapping using a static literal value/discriminator.
     * 
     * @param string $column
     * @param string $operator
     * @param mixed  $value
     * @param string $boolean
     * @return $this
     */
    public function on_value( string $column, string $operator, mixed $value, string $boolean = 'AND' ) : static {
        $this->conditions[] = [
            'type'     => 'OnValue',
            'column'   => $column,
            'operator' => $operator,
            'value'    => $value,
            'boolean'  => $boolean
        ];
        
        $this->bindings[] = $value;
        return $this;
    }

    /**
     * Assert nullability on a relation column within the join clause.
     * 
     * @param string $column
     * @param string $boolean
     * @param bool   $not
     * @return $this
     */
    public function on_null( string $column, string $boolean = 'AND', bool $not = false ) : static {
        $this->conditions[] = [
            'type'    => 'OnNull',
            'column'  => $column,
            'boolean' => $boolean,
            'not'     => $not
        ];
        return $this;
    }

    /**
     * Assert non-nullability on a relation column.
     */
    public function on_not_null( string $column, string $boolean = 'AND' ) : static {
        return $this->on_null( $column, $boolean, true );
    }

    /**
     * Handle nested groups of conditions inside the ON clause (brackets grouping).
     * 
     * @param callable $callback Receives a fresh instance of JoinCriteria sandbox.
     * @param string   $boolean
     * @return $this
     */
    public function on_group( callable $callback, string $boolean = 'AND' ) : static {
        $group = new static();
        $callback( $group );

        $this->conditions[] = [
            'type'       => 'OnGroup',
            'conditions' => $group->get_conditions(),
            'boolean'    => $boolean
        ];

        foreach ( $group->get_bindings() as $binding ) {
            $this->bindings[] = $binding;
        }

        return $this;
    }

    public function or_on_group( callable $callback ) : static {
        return $this->on_group( $callback, 'OR' );
    }

    public function get_conditions() : array {
        return $this->conditions;
    }

    public function get_bindings() : array {
        return $this->bindings;
    }
}