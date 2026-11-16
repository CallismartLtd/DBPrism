<?php
/**
 * The archive format: writing, reading and verification.
 */

namespace Callismart\DBPrism\Tests\Backup;

use Callismart\DBPrism\Backup\Archive\ArchiveReader;
use Callismart\DBPrism\Backup\Archive\ArchiveWriter;
use Callismart\DBPrism\Exceptions\BackupException;
use PHPUnit\Framework\TestCase;

class ArchiveTest extends TestCase {

	private string $dir;

	protected function setUp() : void {
		$this->dir = sys_get_temp_dir() . '/dbprism-archive-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown() : void {
		array_map( 'unlink', glob( $this->dir . '/{,.}*', GLOB_BRACE ) ? array_filter( glob( $this->dir . '/{,.}*', GLOB_BRACE ), 'is_file' ) : [] );
		@rmdir( $this->dir );
	}

	private function header() : array {
		return [ 'kind' => 'header', 'format' => ArchiveWriter::FORMAT, 'version' => ArchiveWriter::VERSION ];
	}

	private function write_sample( string $path ) : string {
		$writer = ArchiveWriter::open( $path );
		$writer->write( $this->header() );
		$writer->write( [ 'kind' => 'table', 'name' => 't' ] );
		$writer->write_rows( 't', [ '[1,"a"]', '[2,null]' ] );
		$writer->write( [ 'kind' => 'table_end', 'table' => 't', 'rows' => 2 ] );

		return $writer->finish( [ 'rows' => 2 ] );
	}

	private function read_all( string $path ) : array {
		$reader  = ArchiveReader::open( $path );
		$records = iterator_to_array( $reader->records(), false );
		$reader->close();

		return $records;
	}

	public function test_records_are_read_back_and_the_checksum_verified() : void {
		$checksum = $this->write_sample( $this->dir . '/a.dbprism' );

		$reader  = ArchiveReader::open( $this->dir . '/a.dbprism' );
		$records = iterator_to_array( $reader->records(), false );

		$this->assertSame( ArchiveWriter::FORMAT, $reader->header()['format'] );
		$this->assertSame( [ 'table', 'rows', 'table_end' ], array_column( $records, 'kind' ) );
		$this->assertSame( [ [ 1, 'a' ], [ 2, null ] ], $records[1]['rows'] );
		$this->assertSame( $checksum, $reader->footer()['sha256'] );
		$this->assertSame( 64, strlen( $checksum ) );
	}

	public function test_the_file_appears_only_when_finished() : void {
		$writer = ArchiveWriter::open( $this->dir . '/a.dbprism' );
		$writer->write( $this->header() );

		$this->assertFileDoesNotExist( $this->dir . '/a.dbprism' );

		$writer->finish();

		$this->assertFileExists( $this->dir . '/a.dbprism' );
		$this->assertSame( [ $this->dir . '/a.dbprism' ], glob( $this->dir . '/{,.}*', GLOB_BRACE ) ? array_values( array_filter( glob( $this->dir . '/{,.}*', GLOB_BRACE ), 'is_file' ) ) : [] );
	}

	public function test_an_aborted_archive_leaves_nothing() : void {
		$writer = ArchiveWriter::open( $this->dir . '/a.dbprism' );
		$writer->write( $this->header() );
		$writer->abort();

		$this->assertSame( [], array_filter( glob( $this->dir . '/{,.}*', GLOB_BRACE ) ?: [], 'is_file' ) );
	}

	public function test_gz_paths_are_compressed_and_read_back() : void {
		$this->write_sample( $this->dir . '/a.dbprism.gz' );

		$this->assertSame( "\x1f\x8b", substr( (string) file_get_contents( $this->dir . '/a.dbprism.gz' ), 0, 2 ) );
		$this->assertCount( 3, $this->read_all( $this->dir . '/a.dbprism.gz' ) );
	}

	public function test_an_altered_archive_fails_its_checksum() : void {
		$this->write_sample( $this->dir . '/a.dbprism' );
		file_put_contents( $this->dir . '/a.dbprism', str_replace( '"a"', '"b"', (string) file_get_contents( $this->dir . '/a.dbprism' ) ) );

		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'checksum' );

		$this->read_all( $this->dir . '/a.dbprism' );
	}

	public function test_a_truncated_archive_is_incomplete() : void {
		$this->write_sample( $this->dir . '/a.dbprism' );
		$lines = file( $this->dir . '/a.dbprism' );
		file_put_contents( $this->dir . '/a.dbprism', implode( '', array_slice( $lines, 0, -1 ) ) );

		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'without a footer' );

		$this->read_all( $this->dir . '/a.dbprism' );
	}

	public function test_a_line_cut_off_is_incomplete() : void {
		$this->write_sample( $this->dir . '/a.dbprism' );
		$bytes = (string) file_get_contents( $this->dir . '/a.dbprism' );
		file_put_contents( $this->dir . '/a.dbprism', substr( $bytes, 0, strpos( $bytes, '[2,null]' ) ) );

		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'cut off' );

		$this->read_all( $this->dir . '/a.dbprism' );
	}

	public function test_data_after_the_footer_is_refused() : void {
		$this->write_sample( $this->dir . '/a.dbprism' );
		file_put_contents( $this->dir . '/a.dbprism', "{\"kind\":\"rows\"}\n", FILE_APPEND );

		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'after its footer' );

		$this->read_all( $this->dir . '/a.dbprism' );
	}

	public function test_a_file_that_is_not_a_backup_is_refused() : void {
		file_put_contents( $this->dir . '/a.sql', "CREATE TABLE t (id INT);\n" );

		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'Not a DBPrism backup' );

		ArchiveReader::open( $this->dir . '/a.sql' );
	}

	public function test_a_newer_format_version_is_refused() : void {
		file_put_contents( $this->dir . '/a.dbprism', json_encode( [ 'kind' => 'header', 'format' => ArchiveWriter::FORMAT, 'version' => ArchiveWriter::VERSION + 1 ] ) . "\n" );

		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'Unsupported backup format version' );

		ArchiveReader::open( $this->dir . '/a.dbprism' );
	}

	public function test_large_integers_keep_their_precision() : void {
		$writer = ArchiveWriter::open( $this->dir . '/a.dbprism' );
		$writer->write( $this->header() );
		$writer->write_rows( 't', [ '[18446744073709551615]' ] );
		$writer->finish();

		$this->assertSame( [ [ '18446744073709551615' ] ], $this->read_all( $this->dir . '/a.dbprism' )[0]['rows'] );
	}

	public function test_a_missing_directory_is_refused() : void {
		$this->expectException( BackupException::class );

		ArchiveWriter::open( $this->dir . '/missing/a.dbprism' );
	}
}
