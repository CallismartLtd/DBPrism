<?php
/**
 * Nested value binding tests.
 *
 * Values bound inside nested structures (groups, subqueries, EXISTS, CASE
 * expressions, multi-row inserts, join conditions, unions and raw clause
 * bindings) must travel as bound parameters, exactly like top-level values.
 * Each test places a hostile value as deep as the structure allows and
 * asserts it reaches the bindings and never the SQL text.
 *
 * @package Callismart\DBPrism\Tests\Security
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Security;

use Callismart\DBPrism\Query\SQLBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

final class NestedBindingTest extends SecurityTestCase {

	/**
	 * A hostile value, unique per test so a leak is unambiguous.
	 *
	 * @param string $tag Distinguishes values within one test.
	 * @return string
	 */
	private static function hostile( string $tag ) : string {
		return "x' OR '1'='1' -- " . self::SENTINEL . " {$tag}";
	}

	#[DataProvider( 'engines' )]
	public function test_values_in_where_groups_are_bound( string $engine ) : void {
		$intent = ( new SQLBuilder( $engine ) )
			->select( 'id' )
			->from( 'users' )
			->where_group(
				fn( $g ) => $g->where( 'a', '=', self::hostile( 'outer' ) )
					->or_where_group( fn( $inner ) => $inner->where( 'b', '=', self::hostile( 'inner' ) ) )
			);

		$this->assertValueBound( $intent, self::hostile( 'outer' ), "where_group, {$engine}" );
		$this->assertValueBound( $intent, self::hostile( 'inner' ), "nested where_group, {$engine}" );
	}

	#[DataProvider( 'engines' )]
	public function test_values_in_subqueries_are_bound( string $engine ) : void {
		$intent = ( new SQLBuilder( $engine ) )
			->select( 'id' )
			->select_subquery(
				fn( $s ) => $s->select( 'COUNT(*)' )->from( 'orders' )->where( 'note', '=', self::hostile( 'select' ) ),
				'order_count'
			)
			->from( 'users' )
			->where_in_subquery(
				'id',
				fn( $s ) => $s->select( 'user_id' )->from( 'orders' )->where( 'status', '=', self::hostile( 'in' ) )
			)
			->where_exists(
				fn( $s ) => $s->select( 'id' )->from( 'bans' )->where( 'reason', '=', self::hostile( 'exists' ) )
			);

		foreach ( [ 'select', 'in', 'exists' ] as $tag ) {
			$this->assertValueBound( $intent, self::hostile( $tag ), "subquery ({$tag}), {$engine}" );
		}
	}

	#[DataProvider( 'engines' )]
	public function test_values_in_select_case_are_bound( string $engine ) : void {
		$intent = ( new SQLBuilder( $engine ) )
			->select( 'id' )
			->select_case(
				fn( $case ) => $case->when( fn( $c ) => $c->where( 'a', '=', self::hostile( 'when' ) ), self::hostile( 'then' ) )
					->else( self::hostile( 'else' ) )
					->as( 'tier' )
			)
			->from( 'users' );

		foreach ( [ 'when', 'then', 'else' ] as $tag ) {
			$this->assertValueBound( $intent, self::hostile( $tag ), "select_case ({$tag}), {$engine}" );
		}
	}

	#[DataProvider( 'engines' )]
	public function test_values_in_order_by_case_are_bound( string $engine ) : void {
		$intent = ( new SQLBuilder( $engine ) )
			->select( 'id' )
			->from( 'users' )
			->order_by_case(
				fn( $case ) => $case->when( fn( $c ) => $c->where( 'a', '=', self::hostile( 'when' ) ), 0 )->else( 1 )
			);

		$this->assertValueBound( $intent, self::hostile( 'when' ), "order_by_case, {$engine}" );
	}

	#[DataProvider( 'engines' )]
	public function test_values_in_set_case_are_bound( string $engine ) : void {
		$intent = ( new SQLBuilder( $engine ) )
			->update( 'users' )
			->set_case(
				'status',
				fn( $case ) => $case->when( fn( $c ) => $c->where( 'a', '=', self::hostile( 'when' ) ), self::hostile( 'then' ) )
					->else( self::hostile( 'else' ) )
			);

		foreach ( [ 'when', 'then', 'else' ] as $tag ) {
			$this->assertValueBound( $intent, self::hostile( $tag ), "set_case ({$tag}), {$engine}" );
		}
	}

	#[DataProvider( 'engines' )]
	public function test_values_in_having_are_bound( string $engine ) : void {
		$intent = ( new SQLBuilder( $engine ) )
			->select( 'category' )
			->from( 'products' )
			->group_by( 'category' )
			->having( 'MAX(name)', '=', self::hostile( 'having' ) )
			->having_case(
				fn( $case ) => $case->when( fn( $c ) => $c->where( 'a', '=', self::hostile( 'when' ) ), 1 )->else( 0 ),
				'=',
				self::hostile( 'compare' )
			);

		foreach ( [ 'having', 'when', 'compare' ] as $tag ) {
			$this->assertValueBound( $intent, self::hostile( $tag ), "having ({$tag}), {$engine}" );
		}
	}

	#[DataProvider( 'engines' )]
	public function test_values_in_multi_row_insert_are_bound( string $engine ) : void {
		$intent = ( new SQLBuilder( $engine ) )
			->insert( 'users' )
			->multi_values( [
				[ 'name' => self::hostile( 'row1' ) ],
				[ 'name' => self::hostile( 'row2' ) ],
			] );

		$this->assertValueBound( $intent, self::hostile( 'row1' ), "multi_values row 1, {$engine}" );
		$this->assertValueBound( $intent, self::hostile( 'row2' ), "multi_values row 2, {$engine}" );
	}

	#[DataProvider( 'engines' )]
	public function test_values_in_join_conditions_are_bound( string $engine ) : void {
		$intent = ( new SQLBuilder( $engine ) )
			->select( 'users.id' )
			->from( 'users' )
			->left_join(
				'orders',
				fn( $on ) => $on->on_column( 'orders.user_id', '=', 'users.id' )
					->on_value( 'orders.status', '=', self::hostile( 'on' ) )
					->on_group( fn( $g ) => $g->on_value( 'orders.note', '=', self::hostile( 'group' ) ) )
			);

		$this->assertValueBound( $intent, self::hostile( 'on' ), "join on_value, {$engine}" );
		$this->assertValueBound( $intent, self::hostile( 'group' ), "join on_group, {$engine}" );
	}

	#[DataProvider( 'engines' )]
	public function test_values_in_union_members_are_bound( string $engine ) : void {
		$first  = ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->where( 'a', '=', self::hostile( 'first' ) );
		$second = ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'archived' )->where( 'b', '=', self::hostile( 'second' ) );
		$union  = $first->union( $second );

		$this->assertValueBound( $union, self::hostile( 'first' ), "union first member, {$engine}" );
		$this->assertValueBound( $union, self::hostile( 'second' ), "union second member, {$engine}" );
	}

	#[DataProvider( 'engines' )]
	public function test_where_raw_bindings_are_bound( string $engine ) : void {
		$intent = ( new SQLBuilder( $engine ) )
			->select( 'id' )
			->from( 'users' )
			->where_raw( 'total > discount * ?', [ self::hostile( 'raw' ) ] );

		$this->assertValueBound( $intent, self::hostile( 'raw' ), "where_raw binding, {$engine}" );
	}

	#[DataProvider( 'engines' )]
	public function test_binding_order_matches_placeholders( string $engine ) : void {
		// Bound values must line up with the placeholders in the SQL, in order.
		// A misaligned binding sends a value to the wrong position: a silent
		// correctness bug and, for authorisation checks, a security one.
		$intent = ( new SQLBuilder( $engine ) )
			->select( 'users.id' )
			->from( 'users' )
			->left_join( 'orders', fn( $on ) => $on->on_value( 'orders.status', '=', 'J' ) )
			->where( 'a', '=', 'W1' )
			->where_in( 'b', [ 'W2', 'W3' ] )
			->group_by( 'users.id' )
			->having( 'COUNT(*)', '>', 'H' )
			->order_by_case( fn( $case ) => $case->when( fn( $c ) => $c->where( 'c', '=', 'O' ), 0 )->else( 1 ) );

		$sql      = $intent->build();
		$bindings = $intent->get_bindings();

		$this->assertSame(
			substr_count( $sql, '?' ),
			count( $bindings ),
			"binding count must match the placeholders, {$engine}"
		);
		$this->assertSame( [ 'J', 'W1', 'W2', 'W3', 'H', 'O', 0, 1 ], $bindings, "bindings in placeholder order, {$engine}" );
	}
}