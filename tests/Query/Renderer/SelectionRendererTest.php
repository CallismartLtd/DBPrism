<?php
/**
 * SelectionIntent RENDERER tests only.
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Query\Renderer;

use PHPUnit\Framework\TestCase;
use function Callismart\DBPrism\tests\queryBuilder;
use function Callismart\DBPrism\tests\dbal;
use function Callismart\DBPrism\tests\dbDriver;

final class SelectionRendererTest extends TestCase {

    private function engine(): string {
        return dbDriver();
    }

    private function quote( string $identifier ): string {
        $wrapper = match ( $this->engine() ) {
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

    public function test_basic_where_query() : void {

        $query = queryBuilder()
            ->select('*')
            ->from('calldbal_licenses')
            ->where('license_key', '=', 'ABC-123');

        $this->assertSame(
            "SELECT * FROM {$this->quote('calldbal_licenses')} WHERE {$this->quote('license_key')} = ?;",
            $query->build()
        );
    }

    public function test_or_where_query() : void {

        $query = queryBuilder()
            ->select('*')
            ->from('wp_users')
            ->where('id', '=', 1)
            ->or_where('status', '=', 'active');

        $this->assertSame(
            "SELECT * FROM {$this->quote('wp_users')} WHERE {$this->quote('id')} = ? OR {$this->quote('status')} = ?;",
            $query->build()
        );
    }

    public function test_where_null() : void {

        $query = queryBuilder()
            ->select('*')
            ->from('calldbal_licenses')
            ->where_null('deleted_at');

        $this->assertSame(
            "SELECT * FROM {$this->quote('calldbal_licenses')} WHERE {$this->quote('deleted_at')} IS NULL;",
            $query->build()
        );
    }

    public function test_where_not_null() : void {

        $query = queryBuilder()
            ->select('*')
            ->from('wp_users')
            ->where_not_null('deleted_at');

        $this->assertSame(
            "SELECT * FROM {$this->quote('wp_users')} WHERE {$this->quote('deleted_at')} IS NOT NULL;",
            $query->build()
        );
    }

    public function test_where_in() : void {

        $query = queryBuilder()
            ->select('*')
            ->from('calldbal_licenses')
            ->where_in('status', ['active','expired','suspended']);

        $this->assertSame(
            "SELECT * FROM {$this->quote('calldbal_licenses')} WHERE {$this->quote('status')} IN (?, ?, ?);",
            $query->build()
        );
    }

    public function test_where_not_in() : void {

        $query = queryBuilder()
            ->select('*')
            ->from('wp_users')
            ->where_not_in('role', ['admin','root']);

        $this->assertSame(
            "SELECT * FROM {$this->quote('wp_users')} WHERE {$this->quote('role')} NOT IN (?, ?);",
            $query->build()
        );
    }

    public function test_where_group() : void {

        $query = queryBuilder()
            ->select('*')
            ->from('wp_users')
            ->where('id', '=', 1)
            ->where_group(function ($q) {

                $q->where_null('deleted_at')
                    ->or_where('legacy_id', '>', 0);

            });

        $this->assertSame(
            "SELECT * FROM {$this->quote('wp_users')} WHERE {$this->quote('id')} = ? AND ({$this->quote('deleted_at')} IS NULL OR {$this->quote('legacy_id')} > ?);",
            $query->build()
        );
    }

    public function test_nested_where_group() : void {

        $query = queryBuilder()
            ->select('*')
            ->from('wp_users')
            ->where_group(function ($q) {

                $q->where('status', '=', 'active')
                    ->or_where_group(function ($q2) {

                        $q2->where('role', '=', 'admin')
                            ->where_null('deleted_at');

                    });

            });

        $this->assertSame(
            "SELECT * FROM {$this->quote('wp_users')} WHERE ({$this->quote('status')} = ? OR ({$this->quote('role')} = ? AND {$this->quote('deleted_at')} IS NULL));",
            $query->build()
        );
    }

    public function test_complex_selection_query() : void {

        $query = queryBuilder()
            ->select('l.id','l.license_key','m.meta_value')
            ->from('calldbal_licenses l')
            ->left_join('calldbal_meta m','l.id','=','m.license_id')
            ->where('l.status','=','active')
            ->group_by('l.id')
            ->order_by('l.created_at','DESC')
            ->limit(20)
            ->offset(40);

        $this->assertSame(
            "SELECT {$this->quote('l.id')}, {$this->quote('l.license_key')}, {$this->quote('m.meta_value')} "
            . "FROM {$this->quote('calldbal_licenses')} {$this->quote('l')} "
            . "LEFT JOIN {$this->quote('calldbal_meta')} {$this->quote('m')} ON {$this->quote('l.id')} = {$this->quote('m.license_id')} "
            . "WHERE {$this->quote('l.status')} = ? "
            . "GROUP BY {$this->quote('l.id')} "
            . "ORDER BY {$this->quote('l.created_at')} DESC "
            . "LIMIT 20 OFFSET 40;",
            $query->build()
        );
    }
}