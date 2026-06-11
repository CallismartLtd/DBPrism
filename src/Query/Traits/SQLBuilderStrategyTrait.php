<?php
/**
 * SQL Builder strategy trait file.
 * 
 * @author Callistus Nwachukwu
 */

namespace Callismart\DBPrism\Query\Traits;

use Callismart\DBPrism\Query\SQLBuilder;

trait SQLBuilderStrategyTrait {
    /**
     * The SQL builder instance.
     * 
     * @var SQLBuilder $builder
     */
    protected SQLBuilder $builder;

    /**
     * @var array|null $custom_bindings Explicit user-defined parameter override.
     */
    protected ?array $custom_bindings = null;

    /**
     * Manually override the internal calculated bindings for this intent.
     * * @param array $bindings An explicit sequence of parameters to use.
     * @return static
     */
    public function set_bindings( array $bindings ) : static {
        $this->custom_bindings = $bindings;
        return $this;
    }
    
    /**
     * Build query.
     * 
     * @return string
     */
    public function build() : string {
        return $this->builder->build();
    }

    /**
     * Build the raw sql with the parameters.
     * 
     * @return string
     */
    public function build_raw(): string {
        $sql      = $this->build();

        $bindings = $this->get_bindings();

        foreach ( $bindings as $value ) {
            $escapedValue = is_string( $value ) ? "'" . addslashes( $value ) . "'" : (string) $value;
            
            $pos = strpos( $sql, '?' );
            if ($pos !== false) {
                $sql = substr_replace( $sql, $escapedValue, $pos, 1 );
            }
        }

        return $sql;
    }

    abstract public function get_bindings() : array;
}