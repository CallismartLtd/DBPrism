<?php
/**
 * DeleteIntent tests (LOGIC ONLY).
 */

declare( strict_types=1 );

namespace Callismart\DBAL\Tests\Query\Intent;

use PHPUnit\Framework\TestCase;
use function Callismart\DBAL\tests\{
    queryBuilder,
    dbal
};

final class DeleteIntentTest extends TestCase {

    private function engine(): string {
        return dbal()->get_driver();
    }

    /**
     * Test table assignment.
     */
    public function test_table_is_set_correctly() : void {

        $query = queryBuilder()
            ->delete( 'smwoo_licenses' );

        $this->assertSame(
            'smwoo_licenses',
            $query->get_table_name()
        );
    }

    /**
     * Test basic WHERE + OR + NULL conditions binding order.
     */
    public function test_basic_conditions_bindings() : void {

        $query = queryBuilder()
            ->delete( 'smwoo_licenses' )
            ->where( 'status', '=', 'expired' )
            ->where_null( 'last_checked' )
            ->or_where( 'id', '<', 100 );

        $this->assertSame(
            [ 'expired', 100 ],
            $query->get_bindings()
        );

        $this->assertIsArray(
            $query->get_conditions()
        );
    }

    /**
     * Test where_null does not add bindings.
     */
    public function test_where_null_has_no_bindings() : void {

        $query = queryBuilder()
            ->delete( 'smwoo_licenses' )
            ->where_null( 'deleted_at' )
            ->where( 'status', '=', 'active' );

        $this->assertSame(
            [ 'active' ],
            $query->get_bindings()
        );
    }

    /**
     * Test grouped condition structure + binding flattening.
     */
    public function test_grouped_conditions_structure() : void {

        $query = queryBuilder()
            ->delete( 'wp_users' )
            ->where_group( function ( $q ) {

                $q->where( 'status', '=', 'active' )
                    ->or_where( 'role', '=', 'admin' );

            })
            ->where( 'id', '=', 1 );

        $this->assertSame(
            [ 'active', 'admin', 1 ],
            $query->get_bindings()
        );

        $this->assertIsArray(
            $query->get_conditions()
        );
    }

    /**
     * Stress test: deep nested delete condition tree.
     */
    public function test_deep_nested_delete_tree() : void {

        $query = queryBuilder()
            ->delete( 'wp_users' )
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

        $this->assertIsArray(
            $query->get_conditions()
        );
    }

    /**
     * Stress test: multiple sibling groups.
     */
    public function test_multiple_sibling_groups() : void {

        $query = queryBuilder()
            ->delete( 'wp_users' )
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
}