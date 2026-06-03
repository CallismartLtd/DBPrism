<?php
/**
 * InsertIntent tests (Intent layer only).
 */

declare(strict_types=1);

namespace Callismart\DBPrism\Tests\Query\Intent;

use PHPUnit\Framework\TestCase;
use function Callismart\DBPrism\tests\queryBuilder;

final class InsertIntentTest extends TestCase {

    /**
     * Basic INSERT values mapping
     */
    public function test_basic_insert_values(): void {

        $query = queryBuilder()
            ->insert('smwoo_licenses')
            ->values([
                'license_key' => 'SMW-123-ABC',
                'status'      => 'active',
                'created_at'  => '2026-05-10 12:00:00'
            ]);

        $this->assertSame(
            [
                'SMW-123-ABC',
                'active',
                '2026-05-10 12:00:00'
            ],
            $query->get_bindings()
        );

        $this->assertSame(
            [
                'license_key' => 'SMW-123-ABC',
                'status'      => 'active',
                'created_at'  => '2026-05-10 12:00:00'
            ],
            $query->get_data()
        );
    }

    /**
     * Column order stability (keys define structure, values define stream)
     */
    public function test_column_order_is_stable(): void {

        $query = queryBuilder()
            ->insert('smwoo_licenses')
            ->values([
                'status'      => 'active',
                'license_key' => 'SMW-123-ABC',
                'created_at'  => '2026-05-10 12:00:00'
            ]);

        $data = $query->get_data();

        // intent preserves insertion order of array
        $this->assertSame('active', $data['status']);
        $this->assertSame('SMW-123-ABC', $data['license_key']);
        $this->assertSame('2026-05-10 12:00:00', $data['created_at']);

        // bindings still positional
        $this->assertSame(
            [
                'active',
                'SMW-123-ABC',
                '2026-05-10 12:00:00'
            ],
            $query->get_bindings()
        );
    }

    /**
     * Multi-row insert (flattening behavior)
     */
    public function test_multi_values_insert(): void {

        $query = queryBuilder()
            ->insert('smwoo_licenses')
            ->multi_values([
                [
                    'license_key' => 'KEY-1',
                    'status'      => 'active'
                ],
                [
                    'license_key' => 'KEY-2',
                    'status'      => 'expired'
                ]
            ]);

        $this->assertTrue($query->is_multi());

        $this->assertSame(
            [
                'KEY-1',
                'active',
                'KEY-2',
                'expired'
            ],
            $query->get_bindings()
        );
    }

    /**
     * Multi-row structure integrity
     */
    public function test_multi_values_structure(): void {

        $query = queryBuilder()
            ->insert('smwoo_licenses')
            ->multi_values([
                [
                    'license_key' => 'A',
                    'status'      => 'active'
                ]
            ]);

        $this->assertIsArray($query->get_data());
        $this->assertCount(1, $query->get_data());
        $this->assertTrue($query->is_multi());
    }

    /**
     * Empty multi-values should not be allowed (edge validation)
     */
    public function test_empty_multi_values_structure(): void {

        $this->expectException(\InvalidArgumentException::class);

        queryBuilder()
            ->insert('smwoo_licenses')
            ->multi_values([]);
    }

    /**
     * Verify single-row values filter out functional SQL expressions from parameter bindings, 
     * but preserve them raw within structural record payloads.
     */
    public function test_insert_values_protects_sql_expressions_from_bindings(): void {

        $query = queryBuilder()
            ->insert('smwoo_licenses')
            ->values([
                'license_key' => 'SMW-123-ABC',
                'status'      => 'active',
                'created_at'  => 'NOW()' // Expression: Should NOT bind!
            ]);

        // 1. Parameter tracking array should strictly skip the expression string
        $this->assertSame(
            [
                'SMW-123-ABC',
                'active'
            ],
            $query->get_bindings()
        );

        // 2. Original structural payload data must still contain the string representation
        $this->assertSame(
            [
                'license_key' => 'SMW-123-ABC',
                'status'      => 'active',
                'created_at'  => 'NOW()'
            ],
            $query->get_data()
        );
    }

    /**
     * Verify multi-row insertions correctly analyze internal matrices, stripping out 
     * structural expressions from flattened streaming bindings uniformly.
     */
    public function test_multi_values_insert_skips_nested_expressions(): void {

        $query = queryBuilder()
            ->insert('smwoo_licenses')
            ->multi_values([
                [
                    'license_key' => 'KEY-1',
                    'created_at'  => 'NOW()' // Expression: Skip
                ],
                [
                    'license_key' => 'KEY-2',
                    'created_at'  => 'LOWER(field)' // Expression: Skip
                ]
            ]);

        // Streaming positional elements must keep positions clean and balanced
        $this->assertSame(
            [
                'KEY-1',
                'KEY-2'
            ],
            $query->get_bindings()
        );
    }
}