<?php

declare(strict_types=1);

namespace CallismartDBPrism\Tests\Query\Intent;

use Callismart\DBPrism\Query\QueryIntents\SelectionIntent;
use Callismart\DBPrism\Query\SQLBuilder;
use PHPUnit\Framework\TestCase;
use Callismart\DBPrism\Query\Traits\QueryCriteriaTrait;

use function Callismart\DBPrism\tests\queryBuilder;

/**
 * Concrete Stub class to enable clean isolate-testing of the QueryCriteriaTrait.
 */
class QueryCriteriaStub {
    use QueryCriteriaTrait;
    protected SQLBuilder $builder;

    public function __construct() {
        $this->builder  = queryBuilder();
    }
}

/**
 * Class QueryCriteriaTest
 *
 * @package CallismartDBPrism\Tests\Query\Intent
 */
class QueryCriteriaTest extends TestCase {

    private QueryCriteriaStub $criteria;

    protected function setUp() : void {
        parent::setUp();
        $this->criteria = new QueryCriteriaStub();
    }

    /**
     * Verify basic where and or_where routing combinations capture structures and parameters correctly.
     */
    public function test_basic_where_and_or_where_structures() : void {
        $this->criteria->where( 'status', '=', 'active' )
                       ->or_where( 'role', '=', 'admin' );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 2, $conditions );
        $this->assertSame( ['active', 'admin'], $bindings );

        $this->assertSame( 'Basic', $conditions[0]['type'] );
        $this->assertSame( 'status', $conditions[0]['column'] );
        $this->assertSame( '=', $conditions[0]['operator'] );
        $this->assertSame( 'AND', $conditions[0]['boolean'] );

        $this->assertSame( 'OR', $conditions[1]['boolean'] );
    }

    /**
     * Verify IS NULL and IS NOT NULL expressions register proper boolean payloads.
     */
    public function test_null_and_not_null_variants() : void {
        $this->criteria->where_null( 'deleted_at' )
                       ->where_not_null( 'verified_at' )
                       ->or_where_null( 'suspended_at' )
                       ->or_where_not_null( 'archived_at' );

        $conditions = $this->criteria->get_conditions();
        $this->assertCount( 4, $conditions );
        $this->assertEmpty( $this->criteria->get_bindings() );

        $this->assertSame( 'Null', $conditions[0]['type'] );
        $this->assertFalse( $conditions[0]['not'] );
        $this->assertSame( 'AND', $conditions[0]['boolean'] );

        $this->assertTrue( $conditions[1]['not'] );
        $this->assertSame( 'AND', $conditions[1]['boolean'] );

        $this->assertFalse( $conditions[2]['not'] );
        $this->assertSame( 'OR', $conditions[2]['boolean'] );

        $this->assertTrue( $conditions[3]['not'] );
        $this->assertSame( 'OR', $conditions[3]['boolean'] );
    }

    /**
     * Verify IN and NOT IN collection validations capture elements and protect empty matrices.
     */
    public function test_where_in_and_where_not_in_payloads() : void {
        $this->criteria->where_in( 'id', [1, 2, 3] )
                       ->where_not_in( 'category', ['draft', 'trash'] );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 2, $conditions );
        $this->assertSame( [1, 2, 3, 'draft', 'trash'], $bindings );

        $this->assertSame( 'In', $conditions[0]['type'] );
        $this->assertFalse( $conditions[0]['not'] );
        $this->assertSame( [1, 2, 3], $conditions[0]['values'] );

        $this->assertTrue( $conditions[1]['not'] );
    }

    /**
     * Verify that an empty array passed into where_in causes an immediate domain failure.
     */
    public function test_where_in_throws_exception_on_empty_array() : void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'where_in values cannot be empty.' );
        $this->criteria->where_in( 'id', [] );
    }

    /**
     * Verify boundary windows map positional bindings cleanly.
     */
    public function test_between_and_not_between_bounds() : void {
        $this->criteria->where_between( 'age', 18, 30 )
                       ->where_not_between( 'score', 0, 50 );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 2, $conditions );
        $this->assertSame( [18, 30, 0, 50], $bindings );

        $this->assertSame( 'Between', $conditions[0]['type'] );
        $this->assertFalse( $conditions[0]['not'] );
        $this->assertSame( [18, 30], $conditions[0]['values'] );

        $this->assertTrue( $conditions[1]['not'] );
    }

    /**
     * Verify arbitrary raw injection layers track independent statements alongside custom elements.
     */
    public function test_where_raw_expressions() : void {
        $this->criteria->where_raw( 'custom_func(column) = ?', ['test_val'], 'OR' );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 1, $conditions );
        $this->assertSame( ['test_val'], $bindings );
        $this->assertSame( 'Raw', $conditions[0]['type'] );
        $this->assertSame( 'custom_func(column) = ?', $conditions[0]['expression'] );
        $this->assertSame( 'OR', $conditions[0]['boolean'] );
    }

    /**
     * Verify sub-group isolations execute sequentially and merge variables down parameters cleanly.
     */
    public function test_nested_where_groups() : void {
        $this->criteria->where( 'global', '=', 'yes' )
                       ->where_group( function( SelectionIntent $query ) {
                           $query->where( 'nested_one', '=', 'a' )
                                 ->or_where( 'nested_two', '=', 'b' );
                       } )
                       ->or_where_group( function( SelectionIntent $query ) {
                           $query->where( 'alternative', '=', 'c' );
                       } );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 3, $conditions );
        $this->assertSame( ['yes', 'a', 'b', 'c'], $bindings );

        $this->assertSame( 'Group', $conditions[1]['type'] );
        $this->assertSame( 'AND', $conditions[1]['boolean'] );
        $this->assertCount( 2, $conditions[1]['conditions'] );

        $this->assertSame( 'Group', $conditions[2]['type'] );
        $this->assertSame( 'OR', $conditions[2]['boolean'] );
    }

    /**
     * Verify custom-defined wildcards pass through without regression while escaping standard text characters.
     */
    public function test_explicit_where_like_value_escaping_bounds() : void {
        $this->criteria->where_like( 'title', '%10% off%' )
                       ->or_where_like( 'slug', 'custom_path_' )
                       ->where_not_like( 'sku', '%prod_=_id%' )
                       ->or_where_not_like( 'code', 'prefix%' );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 4, $conditions );
        
        // Assertions checking boundary wildcards are preserved while core contents are neutralized
        $this->assertSame( '%10=% off%', $bindings[0] );
        
        $this->assertSame( 'custom=_path_', $bindings[1] ); 
        
        $this->assertSame( '%prod=_===_id%', $bindings[2] );
        $this->assertSame( 'prefix%', $bindings[3] );

        $this->assertFalse( $conditions[0]['not'] );
        $this->assertSame( 'AND', $conditions[0]['boolean'] );
        $this->assertSame( 'OR', $conditions[1]['boolean'] );
        $this->assertTrue( $conditions[2]['not'] );
        $this->assertTrue( $conditions[3]['not'] );
    }

    /**
     * Verify semantic wrapper systems encapsulate items uniformly with directional modifiers.
     */
    public function test_semantic_contains_starts_and_ends_permutations() : void {
        $this->criteria->where_contains( 'name', 'Callis_N%w' )
                       ->where_not_contains( 'name', 'test' )
                       ->or_where_contains( 'email', '@gmail' )
                       ->or_where_not_contains( 'email', '@yahoo' )
                       ->where_starts_with( 'sku', 'abc_123' )
                       ->where_not_starts_with( 'sku', 'xyz' )
                       ->or_where_starts_with( 'code', 'start' )
                       ->or_where_not_starts_with( 'code', 'end' )
                       ->where_ends_with( 'file', '.png' )
                       ->where_not_ends_with( 'file', '.jpg' )
                       ->or_where_ends_with( 'ext', 'zip' )
                       ->or_where_not_ends_with( 'ext', 'rar' );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 12, $conditions );

        // Contains verification
        $this->assertSame( '%Callis=_N=%w%', $bindings[0] );
        $this->assertSame( '%test%', $bindings[1] );
        $this->assertTrue( $conditions[1]['not'] );
        $this->assertSame( 'OR', $conditions[2]['boolean'] );
        $this->assertTrue( $conditions[3]['not'] );
        $this->assertSame( 'OR', $conditions[3]['boolean'] );

        // Starts with verification
        $this->assertSame( 'abc=_123%', $bindings[4] );
        $this->assertSame( 'xyz%', $bindings[5] );
        $this->assertTrue( $conditions[5]['not'] );
        $this->assertSame( 'OR', $conditions[6]['boolean'] );
        $this->assertTrue( $conditions[7]['not'] );

        // Ends with verification
        $this->assertSame( '%.png', $bindings[8] );
        $this->assertSame( '%.jpg', $bindings[9] );
        $this->assertTrue( $conditions[9]['not'] );
        $this->assertSame( 'OR', $conditions[10]['boolean'] );
        $this->assertTrue( $conditions[11]['not'] );
    }

    /**
     * Verify basic where updates safely handle raw functional strings by 
     * recording structural payload criteria but blocking tracking assignments.
     */
    public function test_where_clause_intercepts_and_filters_sql_expressions() : void {
        $this->criteria->where( 'status', '=', 'active' )
                       ->where( 'updated_at', '>', 'NOW()' )         // Expression: Block parameter
                       ->where( 'score', '<', 'AVG(total_score)' )    // Expression: Block parameter
                       ->where( 'limit_bound', '=', 25 );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 4, $conditions );
        $this->assertSame( ['active', 25], $bindings );

        $this->assertSame( 'Basic', $conditions[1]['type'] );
        $this->assertSame( 'NOW()', $conditions[1]['value'] );
        $this->assertSame( 'AVG(total_score)', $conditions[2]['value'] );
    }

    /**
     * Verify that mixed collections within a standard IN clause isolate structural expressions
     * while accurately preserving standard scalar configurations.
     */
    public function test_where_in_filters_expressions_within_value_arrays() : void {
        $this->criteria->where_in( 'region', ['North', 'LOWER(fallback_field)', 'South'] );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 1, $conditions );
        $this->assertSame( ['North', 'South'], $bindings );
        $this->assertSame( ['North', 'LOWER(fallback_field)', 'South'], $conditions[0]['values'] );
    }

    /**
     * Verify that where_in_subquery structures register proper relational payload descriptors
     * and bubble nested criteria parameter bindings chronologically.
     */
    public function test_where_in_subquery_registrations_and_nested_binding_bubbling() : void {
        $this->criteria->where( 'scope', '=', 'global' )
                       ->where_in_subquery( 'role_id', function( SelectionIntent $subquery ) {
                           $subquery->select( 'id' )
                                    ->from( 'wp_roles' )
                                    ->where( 'tier_level', '=', 'Admin' );
                       } )
                       ->or_where_not_in_subquery( 'status_code', function( SelectionIntent $subquery ) {
                           $subquery->select( 'code' )
                                    ->from( 'wp_statuses' )
                                    ->where( 'is_archived', '=', 1 );
                       } );

        $conditions = $this->criteria->get_conditions();
        $bindings   = $this->get_flat_bindings( $this->criteria->get_bindings() );

        $this->assertCount( 3, $conditions );
        
        // Assert chronological parameter flow matches exactly
        $this->assertSame( ['global', 'Admin', 1], $bindings );

        // Validate first subquery condition structure
        $this->assertSame( 'InSubquery', $conditions[1]['type'] );
        $this->assertSame( 'role_id', $conditions[1]['column'] );
        $this->assertSame( 'AND', $conditions[1]['boolean'] );
        $this->assertFalse( $conditions[1]['not'] );
        $this->assertInstanceOf( SelectionIntent::class, $conditions[1]['subquery'] );

        // Validate second subquery configuration modifications
        $this->assertSame( 'InSubquery', $conditions[2]['type'] );
        $this->assertSame( 'status_code', $conditions[2]['column'] );
        $this->assertSame( 'OR', $conditions[2]['boolean'] );
        $this->assertTrue( $conditions[2]['not'] );
    }

    /**
     * Verify that where_like captures raw functional configurations and registers them via
     * 'expression_value' metadata properties instead of polluting standard string bindings.
     */
    public function test_where_like_expression_interception_metadata_registration() : void {
        $this->expectException( \InvalidArgumentException::class );
        $this->expectExceptionMessage( 'LIKE value must be a scalar parameter.' );

        $this->criteria->where_like( 'computed_hash', 'SUM(amount)' );
    }

    /**
     * Helper to reliably flatten the parameter array elements across potential type anomalies.
     */
    private function get_flat_bindings( array $bindings ) : array {
        return array_values( $bindings );
    }
}