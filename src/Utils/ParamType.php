<?php
/**
 * Parameter type class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Utils
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Utils;

use InvalidArgumentException;
use RuntimeException;

/**
 * Detects how a bound query value is sent to the database.
 *
 * A value's PHP type decides its parameter type; its contents are never
 * inspected. The string '123' is a STRING, and bytes that happen to be valid
 * UTF-8 are still a STRING. Binary data is marked by passing it as a stream.
 *
 * | PHP value                   | Parameter type |
 * |-----------------------------|----------------|
 * | null                        | NULL           |
 * | bool                        | BOOL           |
 * | int                         | INT            |
 * | finite float                | FLOAT          |
 * | string                      | STRING         |
 * | open stream resource        | BINARY         |
 *
 * Anything else (an array, an object, INF or NAN, a closed resource, a
 * resource that is not a stream) cannot be bound and throws, so it never
 * reaches the database as something it was not meant to be.
 *
 * Each adapter maps these types to its own binding constants in
 * get_bind_type(), and converts the value where its driver needs it in
 * get_bind_value().
 */
final class ParamType {

	public const NULL   = 0;
	public const BOOL   = 1;
	public const INT    = 2;
	public const FLOAT  = 3;
	public const STRING = 4;
	public const BINARY = 5;

	/**
	 * Static utility; not instantiable.
	 */
	private function __construct() {}

	/**
	 * Detect the parameter type of a value.
	 *
	 * @param mixed $value Value to bind.
	 * @return int One of the ParamType constants.
	 * @throws InvalidArgumentException When the value cannot be bound.
	 */
	public static function of( mixed $value ) : int {
		return match ( true ) {
			null === $value         => self::NULL,
			is_bool( $value )       => self::BOOL,
			is_int( $value )        => self::INT,
			is_float( $value )      => is_finite( $value ) ? self::FLOAT : throw new InvalidArgumentException(
				sprintf( 'Cannot bind %s: only finite numbers can be stored on every database engine.', self::describe( $value ) )
			),
			is_string( $value )         => self::STRING,
			self::is_stream( $value )   => self::BINARY,
			default                     => throw new InvalidArgumentException(
				sprintf( 'Cannot bind a value of type %s: bind null, a bool, an int, a float, a string, or a stream for binary data.', self::describe( $value ) )
			),
		};
	}

	/**
	 * Write a float as the shortest decimal string that reads back as the same float.
	 *
	 * For drivers that send floats as strings: PHP's own float to string
	 * conversion rounds to the "precision" setting (14 digits by default), so
	 * 0.1 + 0.2 would arrive as "0.3". The result here does not depend on any
	 * ini setting.
	 *
	 * @param float $value Finite float.
	 * @return string
	 * @throws InvalidArgumentException When the value is INF or NAN.
	 */
	public static function float_to_string( float $value ) : string {
		if ( ! is_finite( $value ) ) {
			throw new InvalidArgumentException( sprintf( 'Cannot write %s as a number.', self::describe( $value ) ) );
		}

		// 17 significant digits always read back exactly; try fewer first for the shortest form.
		for ( $digits = 15; $digits < 17; $digits++ ) {
			$string = sprintf( "%.{$digits}H", $value );

			if ( (float) $string === $value ) {
				return $string;
			}
		}

		return sprintf( '%.17H', $value );
	}

	/**
	 * Read a binary stream from its current position to the end.
	 *
	 * For drivers that cannot bind a stream themselves.
	 *
	 * @param resource $stream Open stream.
	 * @return string The bytes read.
	 * @throws InvalidArgumentException When the value is not an open stream.
	 * @throws RuntimeException         When the stream cannot be read.
	 */
	public static function read_stream( mixed $stream ) : string {
		if ( ! self::is_stream( $stream ) ) {
			throw new InvalidArgumentException( sprintf( 'Expected an open stream, got %s.', self::describe( $stream ) ) );
		}

		$bytes = stream_get_contents( $stream );

		if ( false === $bytes ) {
			throw new RuntimeException( 'Could not read the binary stream bound to the query.' );
		}

		return $bytes;
	}

	/**
	 * Whether a value is an open stream resource.
	 *
	 * @param mixed $value Value to check.
	 * @return bool
	 */
	public static function is_stream( mixed $value ) : bool {
		return is_resource( $value ) && 'stream' === get_resource_type( $value );
	}

	/**
	 * Name a value's type for an error message.
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function describe( mixed $value ) : string {
		if ( is_float( $value ) ) {
			return is_nan( $value ) ? 'NAN' : ( $value > 0 ? 'INF' : '-INF' );
		}

		if ( is_resource( $value ) ) {
			return sprintf( 'resource (%s)', get_resource_type( $value ) );
		}

		return get_debug_type( $value );
	}
}