<?php
/**
 * How values are written to and read from an archive.
 */

namespace Callismart\DBPrism\Tests\Backup;

use Callismart\DBPrism\Backup\ValueCodec;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ValueCodecTest extends TestCase {

	private static function column( int $type ) : Column {
		return Column::make( 'c' )->type( $type );
	}

	public static function encodings() : array {
		return [
			'null'                       => [ ColumnType::TEXT, null, null ],
			'boolean from int'           => [ ColumnType::BOOLEAN, 1, true ],
			'boolean from "0"'           => [ ColumnType::BOOLEAN, '0', false ],
			'boolean from PostgreSQL t'  => [ ColumnType::BOOLEAN, 't', true ],
			'boolean from bool'          => [ ColumnType::BOOLEAN, false, false ],
			'integer from string'        => [ ColumnType::INTEGER, '42', 42 ],
			'negative integer'           => [ ColumnType::BIG_INT, '-9223372036854775808', PHP_INT_MIN ],
			'integer beyond PHP range'   => [ ColumnType::BIG_INT, '18446744073709551615', '18446744073709551615' ],
			'integer column with text'   => [ ColumnType::INTEGER, 'abc', 'abc' ],
			'double from string'         => [ ColumnType::DOUBLE, '0.30000000000000004', 0.30000000000000004 ],
			'double infinity'            => [ ColumnType::DOUBLE, INF, 'Infinity' ],
			'double NaN'                 => [ ColumnType::FLOAT, NAN, 'NaN' ],
			'decimal stays exact'        => [ ColumnType::DECIMAL, '12345678901234567890.123456789', '12345678901234567890.123456789' ],
			'text'                       => [ ColumnType::TEXT, "O'Brien ✓", "O'Brien ✓" ],
			'text from int (SQLite)'     => [ ColumnType::STRING, 5, '5' ],
			'binary as base64'           => [ ColumnType::BLOB, "\0\xff", base64_encode( "\0\xff" ) ],
			'text that is not UTF-8'     => [ ColumnType::TEXT, "caf\xe9", [ '$base64' => base64_encode( "caf\xe9" ) ] ],
		];
	}

	#[DataProvider( 'encodings' )]
	public function test_encode( int $type, mixed $value, mixed $expected ) : void {
		$this->assertSame( $expected, ValueCodec::encode( self::column( $type ), $value ) );
	}

	public function test_binary_streams_are_read() : void {
		$stream = fopen( 'php://memory', 'r+b' );
		fwrite( $stream, 'bytes' );
		rewind( $stream );

		$this->assertSame( base64_encode( 'bytes' ), ValueCodec::encode( self::column( ColumnType::LONG_BLOB ), $stream ) );
	}

	public function test_binary_decodes_to_a_stream_of_the_bytes() : void {
		$value = ValueCodec::decode( self::column( ColumnType::BLOB ), base64_encode( "\0\xff" ) );

		$this->assertTrue( is_resource( $value ) );
		$this->assertSame( "\0\xff", stream_get_contents( $value ) );
	}

	public function test_text_that_is_not_utf8_decodes_to_its_bytes() : void {
		$column = self::column( ColumnType::TEXT );

		$this->assertSame( "caf\xe9", ValueCodec::decode( $column, ValueCodec::encode( $column, "caf\xe9" ) ) );
	}

	public function test_malformed_base64_is_refused() : void {
		$this->expectException( \Callismart\DBPrism\Exceptions\BackupException::class );

		ValueCodec::decode( self::column( ColumnType::BLOB ), '***' );
	}

	public function test_typed_literal_defaults() : void {
		$this->assertTrue( ValueCodec::typed_literal( self::column( ColumnType::BOOLEAN ), '1' ) );
		$this->assertSame( 0, ValueCodec::typed_literal( self::column( ColumnType::INTEGER ), '0' ) );
		$this->assertSame( 1.5, ValueCodec::typed_literal( self::column( ColumnType::DOUBLE ), '1.5' ) );
		$this->assertSame( '0.00', ValueCodec::typed_literal( self::column( ColumnType::DECIMAL ), '0.00' ) );
	}
}
