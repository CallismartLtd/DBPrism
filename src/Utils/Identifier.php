<?php
/**
 * Identifier class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Utils
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Utils;

use InvalidArgumentException;

/**
 * Validates and quotes the table and column names the adapters' insert(),
 * update() and delete() write into SQL.
 *
 * Those helpers take names, never expressions. A name is letters, digits and
 * underscores; a table may carry one schema prefix ("schema.table"). Anything
 * else throws InvalidArgumentException before a statement is built, so a
 * hostile array key (often request data passed straight through) can never
 * become SQL. Valid names are quoted, as the query builder quotes them, so
 * reserved words such as "order" or "key" work as column names.
 *
 * Expressions, functions and aliases belong in the query builder.
 */
final class Identifier {

	/**
	 * One name segment: letters, digits and underscores.
	 */
	public const PATTERN = '/^[A-Za-z0-9_]+$/';

	/**
	 * Validate and quote a table name.
	 *
	 * @param string $name  Table name, or "schema.table".
	 * @param string $quote The engine's identifier quote character.
	 * @return string
	 * @throws InvalidArgumentException When the name is not a plain table name.
	 */
	public static function table( string $name, string $quote ) : string {
		$segments = explode( '.', $name );

		if ( count( $segments ) > 2 || ! self::all_valid( $segments ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid table name "%s": use letters, digits and underscores, with at most one schema prefix.', $name )
			);
		}

		return implode( '.', array_map( fn( string $segment ) => $quote . $segment . $quote, $segments ) );
	}

	/**
	 * Validate and quote the column names of a column => value array.
	 *
	 * @param array  $data  Column => value.
	 * @param string $quote The engine's identifier quote character.
	 * @return string[] Quoted column names, in the array's order.
	 * @throws InvalidArgumentException When a key is not a plain column name.
	 */
	public static function columns( array $data, string $quote ) : array {
		$columns = [];

		foreach ( array_keys( $data ) as $name ) {
			if ( ! is_string( $name ) || ! self::all_valid( [ $name ] ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Invalid column name "%s": use letters, digits and underscores. Use the query builder for expressions.', $name )
				);
			}

			$columns[] = $quote . $name . $quote;
		}

		return $columns;
	}

	/**
	 * Whether every segment is a valid name segment.
	 *
	 * @param string[] $segments Name segments.
	 * @return bool
	 */
	private static function all_valid( array $segments ) : bool {
		foreach ( $segments as $segment ) {
			if ( ! preg_match( self::PATTERN, $segment ) ) {
				return false;
			}
		}

		return true;
	}
}