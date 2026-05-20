<?php
/**
 * Insert Renderer tests.
 */

declare(strict_types=1);

namespace Callismart\DBAL\Tests\Query\Renderer;

use PHPUnit\Framework\TestCase;
use function Callismart\DBAL\tests\dbal;
use function Callismart\DBAL\tests\queryBuilder;

final class InsertRendererTest extends TestCase {

    private function engine(): string {
        return dbal()->get_driver();
    }

    private function quote(string $identifier): string {

        $wrapper = match ($this->engine()) {
            'mysql'  => '`',
            'sqlite' => '"',
            'pgsql'  => '"',
            default  => '',
        };

        if (!str_contains($identifier, '.')) {
            return $wrapper . $identifier . $wrapper;
        }

        return implode(
            '.',
            array_map(
                fn($segment) => $wrapper . $segment . $wrapper,
                explode('.', $identifier)
            )
        );
    }

    /**
     * Test basic INSERT rendering.
     */
    public function test_basic_insert_rendering(): void {

        $query = queryBuilder()
            ->insert('smwoo_licenses')
            ->values([
                'license_key' => 'SMW-123-ABC',
                'status'      => 'active',
                'created_at'  => '2026-05-10 12:00:00',
            ]);

        $engine = $this->engine();

        if ($engine === 'mysql') {

            $this->assertSame(
                'INSERT INTO `smwoo_licenses` (`license_key`, `status`, `created_at`) VALUES (?, ?, ?);',
                $query->build()
            );

        } else {

            $this->assertSame(
                'INSERT INTO "smwoo_licenses" ("license_key", "status", "created_at") VALUES (?, ?, ?);',
                $query->build()
            );
        }
    }

    /**
     * Test single-column INSERT rendering.
     */
    public function test_single_column_insert_rendering(): void {

        $query = queryBuilder()
            ->insert('wp_options')
            ->values([
                'option_name' => 'site_name',
            ]);

        $engine = $this->engine();

        if ($engine === 'mysql') {

            $this->assertSame(
                'INSERT INTO `wp_options` (`option_name`) VALUES (?);',
                $query->build()
            );

        } else {

            $this->assertSame(
                'INSERT INTO "wp_options" ("option_name") VALUES (?);',
                $query->build()
            );
        }
    }

    /**
     * Test multi-column INSERT formatting consistency.
     */
    public function test_multi_column_insert_structure(): void {

        $query = queryBuilder()
            ->insert('calldbal_licenses')
            ->values([
                'license_key' => 'ABC-999',
                'status'      => 'expired',
                'type'        => 'pro',
                'created_at'  => '2026-01-01 00:00:00',
            ]);

        $sql = $query->build();

        // Structure checks (engine-agnostic validation)
        $this->assertStringContainsString('INSERT INTO', $sql);
        $this->assertStringContainsString('VALUES', $sql);

        // Ensure parentheses balance (basic sanity check)
        $this->assertSame(
            substr_count($sql, '('),
            substr_count($sql, ')')
        );
    }

    /**
     * Test column order consistency in SQL output.
     */
    public function test_insert_column_order_is_preserved(): void {

        $query = queryBuilder()
            ->insert('calldbal_licenses')
            ->values([
                'a' => 1,
                'b' => 2,
                'c' => 3,
            ]);

        $sql = $query->build();

        // Extract column section between first (...) after INTO
        preg_match('/INSERT INTO .*?\((.*?)\)/', $sql, $matches);

        $this->assertArrayHasKey(1, $matches);

        $columns = array_map('trim', explode(',', str_replace(['`', '"'], '', $matches[1])));

        $this->assertSame(['a', 'b', 'c'], $columns);
    }
}