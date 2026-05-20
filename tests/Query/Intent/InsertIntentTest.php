<?php
/**
 * InsertIntent tests (Intent layer only).
 */

declare(strict_types=1);

namespace Callismart\DBAL\Tests\Query\Intent;

use PHPUnit\Framework\TestCase;
use function Callismart\DBAL\tests\queryBuilder;

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
}