<?php
/**
 * Delete Query Intent class file.
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\QueryIntents
 * @since 0.2.0
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\QueryIntents;

use Callismart\DBPrism\Query\SQLBuilder;
use Callismart\DBPrism\Query\Traits\QueryCriteriaTrait;
use Callismart\DBPrism\Query\Traits\SQLBuilderStrategyTrait;

/**
 * Represents an intent to delete data from the database.
 * * This class utilizes the QueryCriteriaTrait to manage the WHERE clauses
 * that define the scope of the deletion.
 * @since 0.2.0
 */
class DeleteIntent {
    use QueryCriteriaTrait, SQLBuilderStrategyTrait;

    private string $table_name;

    private function __construct( string $table_name ) {
        $this->table_name = $table_name;
    }

    public function get_table_name() : string {
        return $this->table_name;
    }

    public static function make( string $table_name, SQLBuilder $builder ) : static {
        $static          = new static( $table_name );
        $static->builder = $builder;
        return $static;
    }
}