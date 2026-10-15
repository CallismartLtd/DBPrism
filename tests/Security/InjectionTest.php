<?php
/**
 * SQL injection test suite.
 *
 * These tests assert DBPrism's injection guarantees at the builder level,
 * with no database connection:
 *
 *   - Values bound to the query builder are sent as parameters, never
 *     written into the SQL text.
 *   - Identifiers (table and column names) are quoted, with the quote
 *     character inside them escaped, so they cannot close the quoting.
 *   - Search helpers escape the LIKE wildcards % and _ in the term.
 *   - Operators and sort directions, which are written into the SQL rather
 *     than bound, are either rejected or neutralised, so a hostile value
 *     cannot reach the SQL text.
 *
 * The invariant used throughout is strict: a payload carries a unique
 * sentinel, and a method is safe when that sentinel never appears in the
 * built SQL. A method that throws on the payload is also safe: rejecting
 * bad input is a valid defence. assertNotInjectable() accepts either.
 *
 * @package Callismart\DBPrism\Tests\Security
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Security;

use Callismart\DBPrism\Query\SQLBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

final class InjectionTest extends SecurityTestCase {

	/**
	 * Hostile values that must always travel as bound parameters.
	 *
	 * @return array<string, array{string}>
	 */
	public static function payloads() : array {
		return [
			'quote break'      => [ "x' OR '1'='1" ],
			'statement end'    => [ "x'; DROP TABLE users; --" ],
			'comment'          => [ 'x" -- ' ],
			'union'            => [ "x' UNION SELECT password FROM users --" ],
			'backslash'        => [ 'x\\' ],
			'null byte'        => [ "x\0y" ],
		];
	}

	/*
	|----------
	| Values
	|----------
	*/

	#[DataProvider( 'engines' )]
	public function test_where_value_is_always_bound( string $engine ) : void {
		foreach ( array_keys( self::payloads() ) as $label ) {
			$payload = self::payloads()[ $label ][0];
			$intent  = ( new SQLBuilder( $engine ) )
				->select( 'id' )
				->from( 'users' )
				->where( 'name', '=', $payload );

			$this->assertValueBound( $intent, $payload, "where value, {$label}" );
		}
	}

	#[DataProvider( 'engines' )]
	public function test_where_in_values_are_always_bound( string $engine ) : void {
		$payload = "x'; DROP TABLE users; --";
		$intent  = ( new SQLBuilder( $engine ) )
			->select( 'id' )
			->from( 'users' )
			->where_in( 'status', [ 'active', $payload ] );

		$this->assertValueBound( $intent, $payload, 'where_in value' );
	}

	#[DataProvider( 'engines' )]
	public function test_where_between_bounds_are_always_bound( string $engine ) : void {
		$payload = "x' OR '1'='1";
		$intent  = ( new SQLBuilder( $engine ) )
			->select( 'id' )
			->from( 'users' )
			->where_between( 'age', $payload, 65 );

		$this->assertValueBound( $intent, $payload, 'where_between bound' );
	}

	#[DataProvider( 'engines' )]
	public function test_insert_values_are_always_bound( string $engine ) : void {
		$payload = "x'; DROP TABLE users; --";
		$intent  = ( new SQLBuilder( $engine ) )
			->insert( 'users' )
			->values( [ 'name' => $payload ] );

		$this->assertValueBound( $intent, $payload, 'insert value' );
	}

	#[DataProvider( 'engines' )]
	public function test_update_values_are_always_bound( string $engine ) : void {
		$payload = "x' OR '1'='1";
		$intent  = ( new SQLBuilder( $engine ) )
			->update( 'users' )
			->set( [ 'name' => $payload ] )
			->where( 'id', '=', 1 );

		$this->assertValueBound( $intent, $payload, 'update value' );
	}

	/*
	|-------------
	| Identifiers
	|-------------
	*/

	#[DataProvider( 'engines' )]
	public function test_column_identifier_quote_char_is_escaped( string $engine ) : void {
		$quote  = self::quote_char( $engine );
		$column = 'a' . $quote . 'b';

		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( $column )->from( 'users' )->build(),
			$engine,
			"column identifier, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_table_identifier_quote_char_is_escaped( string $engine ) : void {
		$quote = self::quote_char( $engine );
		$table = 'a' . $quote . 'b';

		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( $table )->build(),
			$engine,
			"table identifier, {$engine}"
		);
	}

	/*
	|---------------
	| Search helpers
	|---------------
	*/

	#[DataProvider( 'engines' )]
	public function test_search_helpers_escape_wildcards( string $engine ) : void {
		// The % and _ must be escaped so they match literally, not as wildcards.
		foreach ( [ 'where_contains', 'where_starts_with', 'where_ends_with' ] as $method ) {
			$intent   = ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->{$method}( 'name', '100%_done' );
			$bindings = $intent->get_bindings();
			$term     = (string) ( $bindings[0] ?? '' );

			$this->assertStringNotContainsString(
				'%_done',
				$term,
				"{$method} on {$engine}: the % and _ in the term must be escaped, not left as LIKE wildcards"
			);
		}
	}

	/*
	|---------------------------
	| Operators and directions
	|---------------------------
	*/

	#[DataProvider( 'engines' )]
	public function test_where_operator_is_not_injectable( string $engine ) : void {
		$operator = '= 1 OR ' . self::SENTINEL . ' --';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->where( 'id', $operator, 1 )->build(),
			"where operator, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_where_column_operator_is_not_injectable( string $engine ) : void {
		$operator = '= b OR ' . self::SENTINEL . ' --';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->where_column( 'a', $operator, 'c' )->build(),
			"where_column operator, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_having_operator_is_not_injectable( string $engine ) : void {
		$operator = '> 0 OR ' . self::SENTINEL . ' --';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'category' )
				->from( 'products' )
				->group_by( 'category' )
				->having( 'COUNT(*)', $operator, 1 )
				->build(),
			"having operator, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_join_operator_is_not_injectable( string $engine ) : void {
		$operator = '= u.id OR ' . self::SENTINEL . ' --';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'id' )
				->from( 'users' )
				->join( 'orders', 'users.id', $operator, 'orders.user_id' )
				->build(),
			"join operator, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_join_on_value_operator_is_not_injectable( string $engine ) : void {
		$operator = '= 1 OR ' . self::SENTINEL . ' --';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'id' )
				->from( 'users' )
				->left_join( 'orders', fn( $on ) => $on->on_value( 'orders.status', $operator, 'paid' ) )
				->build(),
			"join on_value operator, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_order_direction_is_not_injectable( string $engine ) : void {
		$direction = 'ASC, (SELECT ' . self::SENTINEL . ')';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->order_by( 'id', $direction )->build(),
			"order_by direction, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_where_connector_is_not_injectable( string $engine ) : void {
		$connector = 'OR ' . self::SENTINEL . ' OR';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'id' )
				->from( 'users' )
				->where( 'a', '=', 1 )
				->where( 'b', '=', 2, $connector )
				->build(),
			"where connector, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_having_connector_is_not_injectable( string $engine ) : void {
		$connector = 'OR ' . self::SENTINEL . ' OR';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'category' )
				->from( 'products' )
				->group_by( 'category' )
				->having( 'COUNT(*)', '>', 1 )
				->having( 'SUM(stock)', '>', 0, $connector )
				->build(),
			"having connector, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_join_connector_is_not_injectable( string $engine ) : void {
		$connector = 'OR ' . self::SENTINEL . ' OR';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'id' )
				->from( 'users' )
				->left_join(
					'orders',
					fn( $on ) => $on->on_column( 'orders.user_id', '=', 'users.id' )->on_value( 'orders.status', '=', 'paid', $connector )
				)
				->build(),
			"join connector, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_having_case_operator_is_not_injectable( string $engine ) : void {
		$operator = '> 0 OR ' . self::SENTINEL . ' --';

		$this->assertNotInjectable(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'category' )
				->from( 'products' )
				->group_by( 'category' )
				->having_case(
					fn( $case ) => $case->when( fn( $c ) => $c->where( 'stock', '=', 0 ), 1 )->else( 0 ),
					$operator,
					1
				)
				->build(),
			"having_case operator, {$engine}"
		);
	}

	/*
	|-------------------------------
	| Valid keywords still work
	|-------------------------------
	*/

	#[DataProvider( 'engines' )]
	public function test_valid_keywords_are_written_in_canonical_form( string $engine ) : void {
		$sql = ( new SQLBuilder( $engine ) )
			->select( 'id' )
			->from( 'users' )
			->where( 'a', '=', 1 )
			->where( 'b', '!=', 2, 'or' )
			->where( 'c', '<>', 3 )
			->where( 'd', '<', 4 )
			->where( 'e', '<=', 5 )
			->where( 'f', '>', 6 )
			->where( 'g', '>=', 7 )
			->where( 'h', 'like', 'x%' )
			->where( 'i', ' not   like ', 'y%' )
			->order_by( 'id', 'desc' )
			->build();

		foreach ( [ ' = ?', ' OR ', ' != ?', ' <> ?', ' < ?', ' <= ?', ' > ?', ' >= ?', ' LIKE ?', ' NOT LIKE ?', ' DESC' ] as $fragment ) {
			$this->assertStringContainsString( $fragment, $sql, "valid keyword {$fragment} on {$engine}" );
		}
	}
}