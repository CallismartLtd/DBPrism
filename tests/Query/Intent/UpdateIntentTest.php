<?php
/**
 * UpdateIntent tests (Intent layer only).
 */

declare(strict_types=1);

namespace Callismart\DBAL\Tests\Query\Intent;

use PHPUnit\Framework\TestCase;
use function Callismart\DBAL\tests\queryBuilder;
use function Callismart\DBAL\tests\dbal;

final class UpdateIntentTest extends TestCase {

    /**
     * Basic SET payload (single column)
     */
    public function test_basic_set_payload(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->set([
                'status' => 'expired'
            ]);

        $this->assertSame(
            ['expired'],
            array_values($query->get_data())
        );

        $this->assertSame(
            ['expired'],
            array_slice($query->get_bindings(), 0, 1)
        );
    }

    /**
     * Multiple SET values (order preserved, keys ignored)
     */
    public function test_multiple_set_values(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->values([
                'status' => 'active',
                'activated_at' => '2026-05-10 12:00:00'
            ]);

        $this->assertSame(
            ['active', '2026-05-10 12:00:00'],
            array_values($query->get_data())
        );

        $this->assertSame(
            ['active', '2026-05-10 12:00:00'],
            array_slice($query->get_bindings(), 0, 2)
        );
    }

    /**
     * SET then WHERE binding order validation
     */
    public function test_set_then_where_binding_order(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->set([
                'status' => 'active'
            ])
            ->where('license_key', '=', 'ABC-123');

        $this->assertSame(
            [
                'active',
                'ABC-123'
            ],
            $query->get_bindings()
        );
    }

    /**
     * Grouped WHERE does not affect SET payload integrity
     */
    public function test_grouped_where_does_not_mutate_set(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->set([
                'status' => 'active'
            ])
            ->where('license_key', '=', 'ABC-123')
            ->where_group(function ($q) {

                $q->where_null('deleted_at')
                  ->or_where('legacy_id', '>', 0);

            });

        $bindings = $query->get_bindings();

        $this->assertSame('active', $bindings[0]);
        $this->assertSame('ABC-123', $bindings[1]);
        $this->assertSame(0, $bindings[2]); // legacy_id > 0
    }

    /**
     * Deep nested WHERE tree does not interfere with SET order
     */
    public function test_deep_nested_where_tree(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->set([
                'status' => 'active'
            ])
            ->where_group(function ($q) {

                $q->where('level', '=', 1)
                  ->or_where_group(function ($q2) {

                      $q2->where('region', '=', 'africa')
                          ->where_null('deleted_at');

                  });

            });

        $bindings = $query->get_bindings();

        $this->assertSame('active', $bindings[0]);
        $this->assertSame(1, $bindings[1]);
        $this->assertSame('africa', $bindings[2]);
    }

    /**
     * Multiple sibling WHERE groups maintain correct ordering
     */
    public function test_multiple_sibling_groups(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->set([
                'status' => 'active'
            ])
            ->where_group(fn($q) => $q->where('role', '=', 'admin'))
            ->or_where_group(fn($q) => $q->where('role', '=', 'editor'))
            ->where_group(fn($q) => $q->where('region', '=', 'eu'));

        $bindings = $query->get_bindings();

        $this->assertSame('active', $bindings[0]);
        $this->assertSame('admin', $bindings[1]);
        $this->assertSame('editor', $bindings[2]);
        $this->assertSame('eu', $bindings[3]);
    }

    /**
     * Ensure WHERE IN expands correctly into binding stream
     */
    public function test_where_in_in_update_context(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->set([
                'status' => 'active'
            ])
            ->where_in('type', ['pro', 'enterprise']);

        $this->assertSame(
            [
                'active',
                'pro',
                'enterprise'
            ],
            $query->get_bindings()
        );
    }
}