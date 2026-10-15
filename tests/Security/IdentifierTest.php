<?php
/**
 * Identifier quoting tests.
 *
 * Every position that writes a table or column name must quote it for the
 * engine and escape the quote character inside it by doubling, so a name
 * cannot close its own quoting. Each test passes the name 'a<q>b', where
 * <q> is the engine's quote character, and expects '<q>a<q><q>b<q>'.
 *
 * Names that look like SQL expressions (COUNT(*), lower(name)) are written
 * as given by design and are trusted input; see SECURITY.md. These tests
 * cover plain names only.
 *
 * @package Callismart\DBPrism\Tests\Security
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Security;

use Callismart\DBPrism\Query\SQLBuilder;
use PHPUnit\Framework\Attributes\DataProvider;

final class IdentifierTest extends SecurityTestCase {

	/**
	 * The name 'a<q>b' for an engine.
	 *
	 * @param string $engine Engine name.
	 * @return string
	 */
	private static function engine_name( string $engine ) : string {
		return 'a' . self::quote_char( $engine ) . 'b';
	}

	#[DataProvider( 'engines' )]
	public function test_select_column_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( self::engine_name( $engine ) )->from( 'users' )->build(),
			$engine,
			"select column, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_from_table_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( self::engine_name( $engine ) )->build(),
			$engine,
			"from table, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_dotted_name_segments_are_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( 'users.' . self::engine_name( $engine ) )->from( 'users' )->build(),
			$engine,
			"dotted column, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_where_column_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->where( self::engine_name( $engine ), '=', 1 )->build(),
			$engine,
			"where column, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_insert_column_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->insert( 'users' )->values( [ self::engine_name( $engine ) => 1 ] )->build(),
			$engine,
			"insert column, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_insert_table_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->insert( self::engine_name( $engine ) )->values( [ 'id' => 1 ] )->build(),
			$engine,
			"insert table, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_update_column_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->update( 'users' )->set( [ self::engine_name( $engine ) => 1 ] )->where( 'id', '=', 1 )->build(),
			$engine,
			"update column, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_delete_table_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->delete( self::engine_name( $engine ) )->where( 'id', '=', 1 )->build(),
			$engine,
			"delete table, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_order_by_column_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->order_by( self::engine_name( $engine ) )->build(),
			$engine,
			"order_by column, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_group_by_column_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( 'id' )->from( 'users' )->group_by( self::engine_name( $engine ) )->build(),
			$engine,
			"group_by column, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_join_table_and_columns_are_quoted( string $engine ) : void {
		$name = self::engine_name( $engine );

		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( 'users.id' )->from( 'users' )->join( $name, 'users.id', '=', 'x.id' )->build(),
			$engine,
			"join table, {$engine}"
		);
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( 'users.id' )->from( 'users' )->join( 'orders', 'users.' . $name, '=', 'orders.user_id' )->build(),
			$engine,
			"join first column, {$engine}"
		);
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )->select( 'users.id' )->from( 'users' )->join( 'orders', 'users.id', '=', 'orders.' . $name )->build(),
			$engine,
			"join second column, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_join_condition_columns_are_quoted( string $engine ) : void {
		$name = self::engine_name( $engine );

		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'users.id' )
				->from( 'users' )
				->left_join( 'orders', fn( $on ) => $on->on_column( 'orders.' . $name, '=', 'users.id' ) )
				->build(),
			$engine,
			"on_column column, {$engine}"
		);
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'users.id' )
				->from( 'users' )
				->left_join( 'orders', fn( $on ) => $on->on_value( 'orders.' . $name, '=', 1 ) )
				->build(),
			$engine,
			"on_value column, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_subquery_alias_is_quoted( string $engine ) : void {
		$this->assertIdentifierEscaped(
			fn() => ( new SQLBuilder( $engine ) )
				->select( 'id' )
				->select_subquery( fn( $s ) => $s->select( 'id' )->from( 'orders' ), self::engine_name( $engine ) )
				->from( 'users' )
				->build(),
			$engine,
			"subquery alias, {$engine}"
		);
	}

	#[DataProvider( 'engines' )]
	public function test_comment_sequences_stay_inside_quotes( string $engine ) : void {
		// A comment marker inside a name must stay part of the quoted name,
		// never comment out the rest of the statement.
		$quote = self::quote_char( $engine );

		foreach ( [ 'a--b', 'a/*b', 'a#b', 'a;b' ] as $name ) {
			$sql = ( new SQLBuilder( $engine ) )->select( $name )->from( 'users' )->where( 'id', '=', 1 )->build();

			$this->assertStringContainsString(
				$quote . $name . $quote,
				$sql,
				"name {$name} must be written inside quotes, {$engine}"
			);
			$this->assertStringContainsString( ' WHERE ', $sql, "the WHERE clause must survive name {$name}, {$engine}" );
		}
	}
}