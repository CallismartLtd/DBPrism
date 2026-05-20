<?php
/**
 * DeleteRenderer tests (SQL OUTPUT ONLY).
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Query\Renderer;

use PHPUnit\Framework\TestCase;
use function Callismart\DBPrism\tests\{
    queryBuilder,
    dbal
};

final class DeleteRendererTest extends TestCase {

    private function engine(): string {
        return dbal()->get_driver();
    }

    private function quote( string $identifier ): string {

        return match ( $this->engine() ) {
            'mysql'  => "`{$identifier}`",
            'pgsql',
            'sqlite' => "\"{$identifier}\"",
            default  => $identifier,
        };
    }

    /**
     * Test basic DELETE rendering.
     */
    public function test_basic_delete_query() : void {

        $query = queryBuilder()
            ->delete( 'smwoo_licenses' )
            ->where( 'status', '=', 'expired' )
            ->where_null( 'last_checked' )
            ->or_where( 'id', '<', 100 );

        $engine = $this->engine();

        if ( $engine === 'mysql' ) {

            $this->assertSame(
                "DELETE FROM `smwoo_licenses` WHERE `status` = ? AND `last_checked` IS NULL OR `id` < ?;",
                $query->build()
            );

        } else {

            $this->assertSame(
                "DELETE FROM \"smwoo_licenses\" WHERE \"status\" = ? AND \"last_checked\" IS NULL OR \"id\" < ?;",
                $query->build()
            );
        }
    }

    /**
     * Test grouped DELETE rendering.
     */
    public function test_grouped_delete_rendering() : void {

        $query = queryBuilder()
            ->delete( 'wp_users' )
            ->where( 'id', '=', 1 )
            ->where_group( function ( $q ) {

                $q->where_null( 'deleted_at' )
                    ->or_where( 'legacy_id', '>', 0 );

            });

        if ( $this->engine() === 'mysql' ) {

            $this->assertSame(
                "DELETE FROM `wp_users` WHERE `id` = ? AND (`deleted_at` IS NULL OR `legacy_id` > ?);",
                $query->build()
            );

        } else {

            $this->assertSame(
                "DELETE FROM \"wp_users\" WHERE \"id\" = ? AND (\"deleted_at\" IS NULL OR \"legacy_id\" > ?);",
                $query->build()
            );
        }
    }

    /**
     * Test deep nested DELETE rendering.
     */
    public function test_deep_nested_delete_rendering() : void {

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

        $this->assertIsString( $query->build() );
        $this->assertStringContainsString( 'DELETE FROM', $query->build() );
    }
}