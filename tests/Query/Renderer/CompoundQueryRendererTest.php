<?php
/**
 * CompoundQueryIntent RENDERER tests only.
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Query\Renderer;

use PHPUnit\Framework\TestCase;
use function Callismart\DBPrism\tests\queryBuilder;
use function Callismart\DBPrism\tests\dbDriver;

final class CompoundQueryRendererTest extends TestCase {

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

        if ( ! str_contains( $identifier, '.' ) ) {
            return $wrapper . $identifier . $wrapper;
        }

        return implode(
            '.',
            array_map(
                fn($segment) => $wrapper . $segment . $wrapper,
                explode( '.', $identifier )
            )
        );
    }

    /**
     * Test raw rendering format of a clean binary UNION query setup.
     */
    public function test_basic_union_rendering() : void {
        $q1 = queryBuilder()->select( 'id', 'name' )->from( 'wp_smliser_plugins' );
        $q2 = queryBuilder()->select( 'id', 'name' )->from( 'wp_smliser_themes' );

        $compound = $q1->union( $q2 );

        $inner_sql = "SELECT {$this->quote('id')}, {$this->quote('name')} FROM {$this->quote('wp_smliser_plugins')} "
            . "UNION "
            . "SELECT {$this->quote('id')}, {$this->quote('name')} FROM {$this->quote('wp_smliser_themes')}";

        $expected = "SELECT * FROM (\n{$inner_sql}\n) AS {$this->quote('compound_dataset')};";

        $this->assertSame( $expected, $compound->build() );
    }

    /**
     * Test raw rendering format of a clean UNION ALL query setup.
     */
    public function test_union_all_rendering() : void {
        $q1 = queryBuilder()->select( '*' )->from( 'table_a' )->where( 'status', '=', 'active' );
        $q2 = queryBuilder()->select( '*' )->from( 'table_b' )->where( 'status', '=', 'inactive' );

        $compound = $q1->union_all( $q2 );

        $inner_sql = "SELECT * FROM {$this->quote('table_a')} WHERE {$this->quote('status')} = ? "
            . "UNION ALL "
            . "SELECT * FROM {$this->quote('table_b')} WHERE {$this->quote('status')} = ?";

        $expected = "SELECT * FROM (\n{$inner_sql}\n) AS {$this->quote('compound_dataset')};";

        $this->assertSame( $expected, $compound->build() );
    }

    /**
     * Test sequential stacking rendering layouts across multiple operators.
     */
    public function test_multiple_stacked_unions_rendering() : void {
        $q1 = queryBuilder()->select( 'id' )->from( 't1' );
        $q2 = queryBuilder()->select( 'id' )->from( 't2' );
        $q3 = queryBuilder()->select( 'id' )->from( 't3' );

        $compound = $q1->union_all( $q2 )->union( $q3 );

        $inner_sql = "SELECT {$this->quote('id')} FROM {$this->quote('t1')} "
            . "UNION ALL "
            . "SELECT {$this->quote('id')} FROM {$this->quote('t2')} "
            . "UNION "
            . "SELECT {$this->quote('id')} FROM {$this->quote('t3')}";

        $expected = "SELECT * FROM (\n{$inner_sql}\n) AS {$this->quote('compound_dataset')};";

        $this->assertSame( $expected, $compound->build() );
    }

    /**
     * Test adding global ordering onto the outer dataset wrapper framework.
     */
    public function test_compound_global_ordering_rendering() : void {
        $q1 = queryBuilder()->select( 'name', 'created_at' )->from( 'wp_smliser_plugins' );
        $q2 = queryBuilder()->select( 'name', 'created_at' )->from( 'wp_smliser_themes' );

        $compound = $q1->union_all( $q2 )->order_by( 'name', 'ASC' );

        $inner_sql = "SELECT {$this->quote('name')}, {$this->quote('created_at')} FROM {$this->quote('wp_smliser_plugins')} "
            . "UNION ALL "
            . "SELECT {$this->quote('name')}, {$this->quote('created_at')} FROM {$this->quote('wp_smliser_themes')}";

        $expected = "SELECT * FROM (\n{$inner_sql}\n) AS {$this->quote('compound_dataset')} ORDER BY {$this->quote('name')} ASC;";

        $this->assertSame( $expected, $compound->build() );
    }

    /**
     * Test adding global pagination controls onto the outer dataset wrapper framework.
     */
    public function test_compound_global_slicing_rendering() : void {
        $q1 = queryBuilder()->select( 'id' )->from( 'table1' );
        $q2 = queryBuilder()->select( 'id' )->from( 'table2' );

        $compound = $q1->union_all( $q2 )->limit( 10 )->offset( 20 );

        $inner_sql = "SELECT {$this->quote('id')} FROM {$this->quote('table1')} "
            . "UNION ALL "
            . "SELECT {$this->quote('id')} FROM {$this->quote('table2')}";

        $expected = "SELECT * FROM (\n{$inner_sql}\n) AS {$this->quote('compound_dataset')} LIMIT 10 OFFSET 20;";

        $this->assertSame( $expected, $compound->build() );
    }

    /**
     * Stress: Combining full global modifiers (sorting + pagination) onto complex inner criteria segments.
     */
    public function test_complex_compound_query_rendering() : void {
        $q1 = queryBuilder()
            ->select( 'id', 'slug' )
            ->from( 'wp_smliser_plugins' )
            ->where( 'status', '=', 'active' );

        $q2 = queryBuilder()
            ->select( 'id', 'slug' )
            ->from( 'wp_smliser_themes' )
            ->where_null( 'deleted_at' );

        $compound = $q1->union_all( $q2 )
            ->order_by( 'id', 'DESC' )
            ->limit( 15 )
            ->offset( 30 );

        $inner_sql = "SELECT {$this->quote('id')}, {$this->quote('slug')} FROM {$this->quote('wp_smliser_plugins')} WHERE {$this->quote('status')} = ? "
            . "UNION ALL "
            . "SELECT {$this->quote('id')}, {$this->quote('slug')} FROM {$this->quote('wp_smliser_themes')} WHERE {$this->quote('deleted_at')} IS NULL";

        $expected = "SELECT * FROM (\n{$inner_sql}\n) AS {$this->quote('compound_dataset')} ORDER BY {$this->quote('id')} DESC LIMIT 15 OFFSET 30;";

        $this->assertSame( $expected, $compound->build() );
    }
}