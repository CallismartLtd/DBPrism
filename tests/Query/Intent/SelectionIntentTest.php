<?php
/**
 * SelectionIntent LOGIC tests only.
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Query\Intent;

use PHPUnit\Framework\TestCase;
use function Callismart\DBPrism\tests\queryBuilder;
use function Callismart\DBPrism\tests\dbal;

final class SelectionIntentTest extends TestCase {

    /**
     * Engine helper (used only for safe assertions if needed).
     */
    private function engine(): string {
        return dbal()->get_driver();
    }

    /**
     * Test basic WHERE logic and bindings.
     */
    public function test_basic_where_query() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'calldbal_licenses' )
            ->where( 'license_key', '=', 'ABC-123' );

        $this->assertSame(
            [ 'ABC-123' ],
            $query->get_bindings()
        );
    }

    /**
     * Test OR WHERE logic and bindings.
     */
    public function test_or_where_query() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where( 'id', '=', 1 )
            ->or_where( 'status', '=', 'active' );

        $this->assertSame(
            [ 1, 'active' ],
            $query->get_bindings()
        );
    }

    /**
     * Test WHERE NULL logic.
     */
    public function test_where_null() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'calldbal_licenses' )
            ->where_null( 'deleted_at' );

        $this->assertSame( [], $query->get_bindings() );
    }

    /**
     * Test WHERE NOT NULL logic.
     */
    public function test_where_not_null() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where_not_null( 'deleted_at' );

        $this->assertSame( [], $query->get_bindings() );
    }

    /**
     * Test WHERE IN logic.
     */
    public function test_where_in() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'calldbal_licenses' )
            ->where_in( 'status', [ 'active', 'expired', 'suspended' ] );

        $this->assertSame(
            [ 'active', 'expired', 'suspended' ],
            $query->get_bindings()
        );
    }

    /**
     * Test WHERE NOT IN logic.
     */
    public function test_where_not_in() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where_not_in( 'role', [ 'admin', 'root' ] );

        $this->assertSame(
            [ 'admin', 'root' ],
            $query->get_bindings()
        );
    }

    /**
     * Test grouped WHERE logic.
     */
    public function test_where_group() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where( 'id', '=', 1 )
            ->where_group( function ( $q ) {

                $q->where_null( 'deleted_at' )
                    ->or_where( 'legacy_id', '>', 0 );

            });

        $this->assertSame(
            [ 1, 0 ],
            $query->get_bindings()
        );
    }

    /**
     * Test nested grouped logic.
     */
    public function test_nested_where_group() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where_group( function ( $q ) {

                $q->where( 'status', '=', 'active' )
                    ->or_where_group( function ( $q2 ) {

                        $q2->where( 'role', '=', 'admin' )
                            ->where_null( 'deleted_at' );

                    });

            });

        $this->assertSame(
            [ 'active', 'admin' ],
            $query->get_bindings()
        );
    }

    /**
     * Stress: deep nested tree.
     */
    public function test_deep_nested_where_tree() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where( 'status', '=', 'active' )
            ->where_group( function ( $q ) {

                $q->where( 'role', '=', 'admin' )
                    ->or_where_group( function ( $q2 ) {

                        $q2->where( 'level', '=', 1 )
                            ->or_where_group( function ( $q3 ) {

                                $q3->where( 'region', '=', 'africa' )
                                    ->where_null( 'deleted_at' );

                            });

                    });

            });

        $this->assertSame(
            [ 'active', 'admin', 1, 'africa' ],
            $query->get_bindings()
        );
    }

    /**
     * Stress: multiple sibling groups.
     */
    public function test_multiple_sibling_groups() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where( 'status', '=', 'active' )
            ->where_group( function ( $q ) {
                $q->where( 'role', '=', 'admin' );
            })
            ->or_where_group( function ( $q ) {
                $q->where( 'role', '=', 'editor' );
            })
            ->where_group( function ( $q ) {
                $q->where( 'region', '=', 'eu' );
            });

        $this->assertSame(
            [ 'active', 'admin', 'editor', 'eu' ],
            $query->get_bindings()
        );
    }

    /**
     * Stress: IN + nested groups.
     */
    public function test_in_with_nested_groups() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'calldbal_licenses' )
            ->where_in( 'status', [ 'active', 'expired', 'suspended' ] )
            ->where_group( function ( $q ) {

                $q->where( 'type', '=', 'pro' )
                    ->or_where_group( function ( $q2 ) {

                        $q2->where( 'tier', '=', 'gold' )
                            ->where_not_null( 'activated_at' );

                    });

            });

        $this->assertSame(
            [ 'active', 'expired', 'suspended', 'pro', 'gold' ],
            $query->get_bindings()
        );
    }

    /**
     * Stress: long AND/OR chain.
     */
    public function test_long_and_or_chain() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where( 'a', '=', 1 )
            ->or_where( 'b', '=', 2 )
            ->where( 'c', '=', 3 )
            ->or_where( 'd', '=', 4 )
            ->where( 'e', '=', 5 )
            ->or_where( 'f', '=', 6 );

        $this->assertSame(
            [ 1, 2, 3, 4, 5, 6 ],
            $query->get_bindings()
        );
    }

    /**
     * Stress: massive nested tree.
     */
    public function test_massive_nested_tree() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where_group( function ( $q ) {

                $q->where( 'a', '=', 1 )
                    ->or_where_group( function ( $q2 ) {

                        $q2->where( 'b', '=', 2 )
                            ->or_where_group( function ( $q3 ) {

                                $q3->where( 'c', '=', 3 )
                                    ->or_where_group( function ( $q4 ) {

                                        $q4->where( 'd', '=', 4 )
                                            ->where_null( 'deleted_at' );

                                    });

                            });

                    });

            });

        $this->assertSame(
            [ 1, 2, 3, 4 ],
            $query->get_bindings()
        );
    }

    /**
     * Test that standard columns/values are parameterized, 
     * but SQL functional expressions are bypassed from bindings.
     */
    public function test_where_clause_protects_sql_expressions_from_bindings() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'calldbal_licenses' )
            ->where( 'status', '=', 'active' )
            ->where( 'expires_at', '>', 'NOW()' )         // Expression: Skip binding
            ->where( 'seats', '>', 'SUM(legacy_seats)' )  // Expression: Skip binding
            ->where( 'tier_id', '=', 5 );

        $this->assertSame(
            [ 'active', 5 ],
            $query->get_bindings()
        );
    }

    /**
     * Test that mixed entries in an IN expression skip binding functional expressions
     * but preserve native scalar variable mapping tracking.
     */
    public function test_where_in_handles_mixed_expressions_and_values() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'wp_users' )
            ->where_in( 'region', [ 'North', 'LOWER(fallback_field)', 'South' ] );

        $this->assertSame(
            [ 'North', 'South' ],
            $query->get_bindings()
        );
    }

    /**
     * Test that nested subqueries inside a WHERE IN clause bubble up their child
     * bindings sequentially into the parent tree root.
     */
    public function test_where_in_subquery_bubbles_nested_bindings_chronologically() : void {

        $query = queryBuilder()
            ->select( 'name' )
            ->from( 'wp_users' )
            ->where( 'status', '=', 'active' )
            ->where_in_subquery( 'role_id', function( $subquery ) {
                $subquery->select( 'id' )
                    ->from( 'wp_roles' )
                    ->where( 'tier', '=', 'Admin' );
            })
            ->where( 'profile_completed', '=', 1 );

        $this->assertSame(
            [ 'active', 'Admin', 1 ],
            $query->get_bindings()
        );
    }

    /**
     * Stress: Massive mixed tree running standard variables, grouped closures, 
     * expressions, and isolated subqueries combined.
     */
    public function test_massive_composite_expression_tree() : void {

        $query = queryBuilder()
            ->select( '*' )
            ->from( 'calldbal_licenses' )
            ->where( 'is_active', '=', 1 )
            ->where( 'created_at', '<', 'NOW()' ) // Expression bypass
            ->where_group( function( $q ) {
                $q->where_in( 'type', [ 'pro', 'LOWER(custom_type)', 'enterprise' ] ) // 1 Expression
                  ->or_where_in_subquery( 'owner_id', function( $subquery ) {
                      $subquery->select( 'id' )
                          ->from( 'wp_users' )
                          ->where( 'role', '=', 'administrator' );
                  });
            });

        $this->assertSame(
            [ 1, 'pro', 'enterprise', 'administrator' ],
            $query->get_bindings()
        );
    }
}