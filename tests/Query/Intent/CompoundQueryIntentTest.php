<?php
/**
 * CompoundQueryIntent LOGIC tests only.
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Query\Intent;

use PHPUnit\Framework\TestCase;
use function Callismart\DBPrism\tests\queryBuilder;
use function Callismart\DBPrism\tests\dbal;

final class CompoundQueryIntentTest extends TestCase {

    /**
     * Engine helper (used only for safe assertions if needed).
     */
    private function engine(): string {
        return dbal()->get_driver();
    }

    /**
     * Test a standard flat UNION statement and structural parameters.
     */
    public function test_basic_union_bindings_collation() : void {
        $query1 = queryBuilder()
            ->select( 'status', "'plugin' as type" )
            ->from( 'wp_smliser_plugins' )
            ->where( 'status', '=', 'active' );

        $query2 = queryBuilder()
            ->select( 'status', "'theme' as type" )
            ->from( 'wp_smliser_themes' )
            ->where( 'status', '=', 'inactive' );

        // Stack the sets vertically
        $compound = $query1->union( $query2 );

        // Positional bindings must merge down the track in exact execution order
        $this->assertSame(
            [ 'active', 'inactive' ],
            $compound->get_bindings()
        );
    }

    /**
     * Test a standard flat UNION ALL statement parameters collection.
     */
    public function test_union_all_bindings_collation() : void {
        $query1 = queryBuilder()
            ->select( 'name' )
            ->from( 'wp_smliser_plugins' )
            ->where( 'id', '>', 100 );

        $query2 = queryBuilder()
            ->select( 'name' )
            ->from( 'wp_smliser_software' )
            ->where( 'id', '<', 50 );

        $compound = $query1->union_all( $query2 );

        $this->assertSame(
            [ 100, 50 ],
            $compound->get_bindings()
        );
    }

    /**
     * Test deep vertical chaining of multiple distinct union sets.
     */
    public function test_multiple_chained_unions_bindings() : void {
        $q1 = queryBuilder()->select( 'id' )->from( 'table1' )->where( 'a', '=', 'val1' );
        $q2 = queryBuilder()->select( 'id' )->from( 'table2' )->where( 'b', '=', 'val2' );
        $q3 = queryBuilder()->select( 'id' )->from( 'table3' )->where( 'c', '=', 'val3' );
        $q4 = queryBuilder()->select( 'id' )->from( 'table4' )->where( 'd', '=', 'val4' );

        // Chaining fluid syntax pipelines
        $compound = $q1->union_all( $q2 )->union( $q3 )->union_all( $q4 );

        $this->assertSame(
            [ 'val1', 'val2', 'val3', 'val4' ],
            $compound->get_bindings()
        );
    }

    /**
     * Stress: Combining selection filters containing nested criteria blocks into a compound query.
     */
    public function test_compound_unions_containing_nested_where_groups() : void {
        $plugins = queryBuilder()
            ->select( 'slug' )
            ->from( 'wp_smliser_plugins' )
            ->where( 'status', '=', 'active' )
            ->where_group( function ( $q ) {
                $q->where( 'visibility', '=', 'public' )
                  ->or_where_null( 'deleted_at' );
            });

        $themes = queryBuilder()
            ->select( 'slug' )
            ->from( 'wp_smliser_themes' )
            ->where_in( 'tier', [ 'pro', 'developer' ] )
            ->where_group( function ( $q ) {
                $q->where( 'downloads', '>', 500 );
            });

        $compound = $plugins->union_all( $themes );

        // The positional bindings must match perfectly through nested levels sequentially
        $this->assertSame(
            [ 'active', 'public', 'pro', 'developer', 500 ],
            $compound->get_bindings()
        );
    }

    /**
     * Test global sorting state preservation on compound objects via SupportsOrderingTrait.
     */
    public function test_compound_global_sorting_assignment() : void {
        $q1 = queryBuilder()->select( 'name', 'status' )->from( 'wp_smliser_plugins' );
        $q2 = queryBuilder()->select( 'name', 'status' )->from( 'wp_smliser_themes' );

        $compound = $q1->union_all( $q2 );
        
        // Apply sorting directly to the compound instance wrapper
        $compound->order_by( 'name', 'ASC' )
        ->order_by( 'status', 'DESC' );

        $expected_orders = [
            [ 
                'type'      => 'string',
                'column'    => 'name', 
                'direction' => 'ASC' ,
                
            ],
            [
                'type'      => 'string',
                'column'    => 'status', 
                'direction' => 'DESC',
                
            ]
        ];

        $this->assertSame( $expected_orders, $compound->get_orders() );
    }

    /**
     * Test global cursor pagination state preservation on compound objects via SupportsSlicingTrait.
     */
    public function test_compound_global_slicing_assignment() : void {
        $q1 = queryBuilder()->select( 'id' )->from( 'wp_smliser_plugins' );
        $q2 = queryBuilder()->select( 'id' )->from( 'wp_smliser_themes' );

        $compound = $q1->union_all( $q2 );
        
        // Apply slicing directly to the compound instance wrapper
        $compound->limit( 25 )->offset( 50 );

        $this->assertSame( 25, $compound->get_limit() );
        $this->assertSame( 50, $compound->get_offset() );
    }

    /**
     * Test state isolation checking method constraints on structural anchors.
     */
    public function test_primary_and_sub_intent_extraction() : void {
        $primary = queryBuilder()->select( '*' )->from( 'wp_smliser_plugins' );
        $next    = queryBuilder()->select( '*' )->from( 'wp_smliser_themes' );

        $compound = $primary->union_all( $next );

        // Verify anchor references stay distinct
        $this->assertSame( $primary, $compound->get_primary() );
        
        $unions = $compound->get_unions();
        $this->assertCount( 1, $unions );
        $this->assertSame( $next, $unions[0]->intent );
        $this->assertSame( 'UNION ALL', $unions[0]->operator );
    }
}