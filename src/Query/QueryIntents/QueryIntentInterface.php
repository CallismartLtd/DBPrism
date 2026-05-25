<?php
/**
 * Query intent constract file
 * 
 * @author Callistus Nwachukwu
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\QueryIntents;

interface QueryIntentInterface {
    /**
     * Reconstruct a new self using existing factory methods
     */
    public function new_instance() : static;

    /**
     * Build query.
     * 
     * @return string
     */
    public function build() : string;

    /**
     * Build the raw sql with the parameters.
     *
     * @return string
     */
    public function build_raw(): string;

    /**
     * Get the parameter bindings.
     */
    public function get_bindings() : array;

    public function union( QueryIntentInterface $intent ) : CompoundQueryIntent;
    public function union_all( QueryIntentInterface $intent ) : CompoundQueryIntent;
}