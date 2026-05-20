<?php
/**
 * UpdateRenderer tests (SQL OUTPUT ONLY).
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Query\Renderer;

use PHPUnit\Framework\TestCase;
use function Callismart\DBPrism\tests\queryBuilder;
use function Callismart\DBPrism\tests\dbal;

final class UpdateRendererTest extends TestCase {

    private function engine(): string {
        return dbal()->get_driver();
    }

    /**
     * Test basic UPDATE rendering.
     */
    public function test_basic_update_rendering() : void {

        $query = queryBuilder()
            ->update( 'smwoo_licenses' )
            ->set([
                'status' => 'expired'
            ])
            ->where( 'license_key', '=', 'SMW-123-ABC' );

        if ( $this->engine() === 'mysql' ) {

            $this->assertSame(
                'UPDATE `smwoo_licenses` SET `status` = ? WHERE `license_key` = ?;',
                $query->build()
            );

        } else {

            $this->assertSame(
                'UPDATE "smwoo_licenses" SET "status" = ? WHERE "license_key" = ?;',
                $query->build()
            );
        }
    }

    /**
     * Test multi-column UPDATE rendering.
     */
    public function test_multi_set_rendering() : void {

        $query = queryBuilder()
            ->update( 'smwoo_licenses' )
            ->values([
                'status'        => 'active',
                'activated_at'  => '2026-05-10 12:00:00'
            ])
            ->where( 'license_key', '=', 'ABC-123' );

        if ( $this->engine() === 'mysql' ) {

            $this->assertSame(
                'UPDATE `smwoo_licenses` SET `status` = ?, `activated_at` = ? WHERE `license_key` = ?;',
                $query->build()
            );

        } else {

            $this->assertSame(
                'UPDATE "smwoo_licenses" SET "status" = ?, "activated_at" = ? WHERE "license_key" = ?;',
                $query->build()
            );
        }
    }

    /**
     * Test grouped WHERE rendering in UPDATE.
     */
    public function test_grouped_update_rendering() : void {

        $query = queryBuilder()
            ->update( 'smwoo_licenses' )
            ->values([
                'status' => 'active'
            ])
            ->where( 'license_key', '=', 'ABC-123' )
            ->where_group( function ( $q ) {

                $q->where_null( 'deleted_at' )
                    ->or_where( 'legacy_id', '>', 0 );

            });

        if ( $this->engine() === 'mysql' ) {

            $this->assertSame(
                'UPDATE `smwoo_licenses` SET `status` = ? WHERE `license_key` = ? AND (`deleted_at` IS NULL OR `legacy_id` > ?);',
                $query->build()
            );

        } else {

            $this->assertSame(
                'UPDATE "smwoo_licenses" SET "status" = ? WHERE "license_key" = ? AND ("deleted_at" IS NULL OR "legacy_id" > ?);',
                $query->build()
            );
        }
    }
}