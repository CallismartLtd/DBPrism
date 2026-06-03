<?php
/**
 * UpdateIntent tests (Intent layer only).
 */

declare(strict_types=1);

namespace Callismart\DBPrism\Tests\Query\Intent;

use PHPUnit\Framework\TestCase;
use function Callismart\DBPrism\tests\queryBuilder;
use function Callismart\DBPrism\tests\dbal;

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

    /**
     * Verify that UPDATE operations completely protect functional SQL expressions
     * inside the data assignment payload from being bound as variable parameters.
     */
    public function test_update_payload_protects_sql_expressions_from_bindings(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->set([
                'status'     => 'expired',
                'updated_at' => 'NOW()',            // Expression: Should NOT bind!
                'score'      => 'COALESCE(score, 0)' // Expression: Should NOT bind!
            ])
            ->where('id', '=', 10);

        // 1. Data assignment parameter stream must strictly isolate data elements
        $this->assertSame(
            [
                'expired',
                10
            ],
            $query->get_bindings()
        );

        // 2. State dictionary metadata payload must remain unmutated for the compiler
        $data = $query->get_data();
        $this->assertSame('NOW()', $data['updated_at']);
        $this->assertSame('COALESCE(score, 0)', $data['score']);
    }

    /**
     * Verify that dynamic set_case blocks cleanly evaluate conditional paths,
     * stripping out raw expression tokens from THEN/ELSE branches uniformly.
     */
    public function test_update_set_case_filters_branch_expressions(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->set_case('quota_tier', function ($case) {
                $case->when(fn($q) => $q->where('manager_tier', '=', 'Senior'), 'COUNT(id)') // Expression branch
                     ->else(50);
            })
            ->where('status', '=', 'active');

        // Only 'Senior', 50, and 'active' are parameters; 'COUNT(id)' is bypassed!
        $this->assertSame(
            [
                'Senior',
                50,
                'active'
            ],
            $query->get_bindings()
        );
    }

    /**
     * Stress: Complex relational scenario combining explicit column updates,
     * dynamic functional calculations, custom conditional cases, and subqueries together.
     */
    public function test_massive_composite_update_alignment_tree(): void {

        $query = queryBuilder()
            ->update('smwoo_licenses')
            ->set([
                'status'     => 'provisioned',
                'updated_at' => 'NOW()' // Expression bypass
            ])
            ->set_case('priority_index', function ($case) {
                $case->when(fn($q) => $q->where('tier_level', '=', 'Platinum'), 99)
                     ->else('LOWER(default_index)'); // Expression bypass
            })
            ->where_in_subquery('owner_id', function ($subquery) {
                $subquery->select('id')
                         ->from('wp_users')
                         ->where('role', '=', 'administrator');
            });

        // Verifying chronological binding extraction remains absolutely synchronous
        $this->assertSame(
            [
                'provisioned',   // 1. Plain SET data value
                'Platinum',      // 2. Case Branch condition assignment
                99,              // 3. Case Branch THEN outcome parameter
                'administrator'  // 4. Subquery selector evaluation parameter
            ],
            $query->get_bindings()
        );
    }
}