<?php
/**
 * Backup value codec class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup;

use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\ParamType;

/**
 * Converts column values to and from their form in a backup archive.
 *
 * Every engine and driver returns values its own way: a boolean is true, 1,
 * "1" or "t"; an integer is an int or a numeric string; binary data is a
 * string of bytes. In an archive a value is written once, the same way for
 * every engine, by its column's canonical type:
 *
 * | Column type                 | In the archive                                  |
 * |-----------------------------|-------------------------------------------------|
 * | any, when NULL              | null                                            |
 * | BOOLEAN                     | true / false                                    |
 * | SMALL_INT, INTEGER, BIG_INT | number; a string beyond PHP's int range         |
 * | FLOAT, DOUBLE               | number; "NaN", "Infinity", "-Infinity" as text  |
 * | DECIMAL                     | string, exactly as stored                       |
 * | BINARY, BLOB, LONG_BLOB     | base64 string                                   |
 * | everything else             | string                                          |
 *
 * A text value that is not valid UTF-8 (which JSON cannot hold) is written
 * as {"$base64": "..."} and read back to the same bytes.
 *
 * A value that does not fit its declared type (SQLite stores any value in
 * any column) is written as it is, never coerced into something else.
 *
 * @since 2.6.0
 */
final class ValueCodec {

	/**
	 * Static utility; not instantiable.
	 */
	private function __construct() {}

	/**
	 * A value as it is written to an archive.
	 *
	 * @param Column $column The value's column.
	 * @param mixed  $value  The value as the adapter returned it.
	 * @return mixed A JSON-encodable value.
	 */
	public static function encode( Column $column, mixed $value ) : mixed {
		if ( null === $value ) {
			return null;
		}

		if ( ParamType::is_stream( $value ) ) {
			$value = ParamType::read_stream( $value );
		}

		if ( self::is_binary( $column ) ) {
			return base64_encode( (string) $value );
		}

		return match ( true ) {
			ColumnType::BOOLEAN === $column->type => self::to_bool( $value ),
			self::is_integer( $column )           => self::to_integer( $value ),
			self::is_float( $column )             => self::to_float( $value ),
			default                               => self::to_text( $value ),
		};
	}

	/**
	 * A value from an archive, ready to bind on any engine.
	 *
	 * Binary data becomes a stream (see ParamType), which the caller closes
	 * after the query has run.
	 *
	 * @param Column $column The value's column.
	 * @param mixed  $value  The value as the archive holds it.
	 * @return mixed
	 * @throws BackupException When the value cannot be what the archive says it is.
	 */
	public static function decode( Column $column, mixed $value ) : mixed {
		if ( null === $value ) {
			return null;
		}

		if ( is_array( $value ) ) {
			if ( ! is_string( $value['$base64'] ?? null ) ) {
				throw new BackupException( sprintf( 'The backup is damaged: column "%s" holds a value of an unknown form.', $column->name ) );
			}

			return self::base64( $column, $value['$base64'] );
		}

		if ( self::is_binary( $column ) ) {
			$stream = fopen( 'php://memory', 'r+b' );
			fwrite( $stream, self::base64( $column, (string) $value ) );
			rewind( $stream );

			return $stream;
		}

		if ( ColumnType::BOOLEAN === $column->type && is_bool( $value ) ) {
			return $value;
		}

		if ( self::is_integer( $column ) && is_string( $value ) ) {
			// A string here is an integer beyond PHP's range (bound as text,
			// which the engine reads as a number), or a value SQLite stored
			// in a column of another type; either way, sent as it is.
			return $value;
		}

		if ( self::is_float( $column ) && is_int( $value ) ) {
			return (float) $value;
		}

		return $value;
	}

	/**
	 * A literal DEFAULT value in the PHP type of its column.
	 *
	 * Engines report defaults as text ("0", "1", "'abc'"); a boolean column's
	 * default becomes a bool and a number column's a number, so each engine
	 * writes it in its own syntax (DEFAULT TRUE on PostgreSQL, DEFAULT 1 on
	 * MySQL).
	 *
	 * @param Column $column
	 * @param mixed  $value
	 * @return mixed
	 */
	public static function typed_literal( Column $column, mixed $value ) : mixed {
		if ( null === $value || is_array( $value ) ) {
			return null;
		}

		return match ( true ) {
			ColumnType::BOOLEAN === $column->type => self::to_bool( $value ),
			self::is_integer( $column )           => self::to_integer( $value ),
			self::is_float( $column )             => self::to_float( $value ),
			default                               => is_bool( $value ) ? ( $value ? '1' : '0' ) : (string) $value,
		};
	}

	/**
	 * Whether a column holds binary data.
	 *
	 * @param Column $column
	 * @return bool
	 */
	public static function is_binary( Column $column ) : bool {
		return in_array( $column->type, [ ColumnType::BINARY, ColumnType::BLOB, ColumnType::LONG_BLOB ], true );
	}

	/**
	 * Whether a column holds integers.
	 *
	 * @param Column $column
	 * @return bool
	 */
	public static function is_integer( Column $column ) : bool {
		return in_array( $column->type, [ ColumnType::SMALL_INT, ColumnType::INTEGER, ColumnType::BIG_INT ], true );
	}

	/**
	 * Whether a column holds floating-point numbers.
	 *
	 * @param Column $column
	 * @return bool
	 */
	public static function is_float( Column $column ) : bool {
		return in_array( $column->type, [ ColumnType::FLOAT, ColumnType::DOUBLE ], true );
	}

	/**
	 * Whether a column holds text of unbounded length (which some engines cannot index).
	 *
	 * @param Column $column
	 * @return bool
	 */
	public static function is_long_text( Column $column ) : bool {
		return in_array( $column->type, [ ColumnType::TEXT, ColumnType::LONG_TEXT, ColumnType::JSON ], true );
	}

	/**
	 * A boolean from the ways engines return one.
	 *
	 * @param mixed $value
	 * @return mixed A bool, or the value as it is when it is not a boolean.
	 */
	private static function to_bool( mixed $value ) : mixed {
		if ( is_bool( $value ) ) {
			return $value;
		}

		if ( is_int( $value ) || is_float( $value ) ) {
			return 0 != $value;
		}

		$text = strtolower( trim( (string) $value ) );

		return match ( true ) {
			in_array( $text, [ '1', 't', 'true', 'y', 'yes', 'on' ], true )  => true,
			in_array( $text, [ '0', 'f', 'false', 'n', 'no', 'off' ], true ) => false,
			is_numeric( $text )                                              => 0 != (float) $text,
			default                                                          => self::to_text( $value ),
		};
	}

	/**
	 * An integer, or a string when it is beyond PHP's int range or not an integer.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function to_integer( mixed $value ) : mixed {
		if ( is_int( $value ) ) {
			return $value;
		}

		if ( is_bool( $value ) ) {
			return (int) $value;
		}

		$text = is_float( $value ) ? self::float_text( $value ) : trim( (string) $value );

		if ( preg_match( '/^-?\d+$/', $text ) ) {
			$int = filter_var( $text, FILTER_VALIDATE_INT );

			return false === $int ? $text : $int;
		}

		return self::to_text( $value );
	}

	/**
	 * A float, or text for NaN, the infinities, and values that are not numbers.
	 *
	 * @param mixed $value
	 * @return mixed
	 */
	private static function to_float( mixed $value ) : mixed {
		if ( is_int( $value ) ) {
			return (float) $value;
		}

		if ( is_float( $value ) ) {
			return is_finite( $value ) ? $value : self::float_text( $value );
		}

		if ( is_numeric( $value ) ) {
			$float = (float) $value;

			return is_finite( $float ) ? $float : self::float_text( $float );
		}

		return self::to_text( $value );
	}

	/**
	 * Text, with bytes that are not UTF-8 kept as base64.
	 *
	 * @param mixed $value
	 * @return string|array{"$base64": string}
	 */
	private static function to_text( mixed $value ) : string|array {
		$text = match ( true ) {
			is_bool( $value )  => $value ? '1' : '0',
			is_float( $value ) => self::float_text( $value ),
			default            => (string) $value,
		};

		return preg_match( '//u', $text ) ? $text : [ '$base64' => base64_encode( $text ) ];
	}

	/**
	 * A float as text, including NaN and the infinities as SQL spells them.
	 *
	 * @param float $value
	 * @return string
	 */
	private static function float_text( float $value ) : string {
		return match ( true ) {
			is_nan( $value )    => 'NaN',
			INF === $value      => 'Infinity',
			-INF === $value     => '-Infinity',
			default             => ParamType::float_to_string( $value ),
		};
	}

	/**
	 * Decode base64, strictly.
	 *
	 * @param Column $column
	 * @param string $value
	 * @return string
	 * @throws BackupException When it is not base64.
	 */
	private static function base64( Column $column, string $value ) : string {
		$bytes = base64_decode( $value, true );

		if ( false === $bytes ) {
			throw new BackupException( sprintf( 'The backup is damaged: column "%s" holds binary data that is not base64.', $column->name ) );
		}

		return $bytes;
	}
}
