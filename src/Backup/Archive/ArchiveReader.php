<?php
/**
 * Backup archive reader class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup\Archive
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup\Archive;

use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Utils\ParamType;
use Generator;
use JsonException;

/**
 * Reads a DBPrism backup archive written by ArchiveWriter, one record at a time.
 *
 * records() yields every record after the header, and verifies the archive as
 * it goes: the header must name a format and version this reader knows, and
 * the archive must end with a footer whose checksum matches every byte before
 * it. A truncated or altered archive throws BackupException when its end is
 * reached, before the generator finishes, so a restore that commits only
 * after reading the whole archive never commits a damaged one.
 *
 * A path is read through PHP's zlib wrapper, which reads gzip-compressed and
 * plain files alike. A stream resource is read as it is and left open.
 *
 * @since 2.6.0
 */
final class ArchiveReader {

	/**
	 * The open handle.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * The header record.
	 *
	 * @var array<string, mixed>
	 */
	private array $header;

	/**
	 * The footer record, once read.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $footer = null;

	/**
	 * Whether records() has started.
	 *
	 * @var bool
	 */
	private bool $started = false;

	/**
	 * Running SHA-256 of every line before the footer.
	 *
	 * @var \HashContext
	 */
	private \HashContext $hash;

	/**
	 * Line number of the last line read, for error messages.
	 *
	 * @var int
	 */
	private int $line = 0;

	/**
	 * Constructor. Use open().
	 *
	 * @param resource $handle The handle to read.
	 * @param bool     $owns   Whether this reader opened the handle and closes it.
	 * @throws BackupException When the header is missing or not a supported archive.
	 */
	private function __construct( $handle, private bool $owns ) {
		$this->handle = $handle;
		$this->hash   = hash_init( 'sha256' );
		$this->header = $this->read_header();
	}

	/**
	 * Open an archive and read its header.
	 *
	 * @param string|resource $source A file path, or an open readable stream.
	 * @return self
	 * @throws BackupException When it cannot be opened or is not a DBPrism backup.
	 */
	public static function open( mixed $source ) : self {
		if ( ParamType::is_stream( $source ) ) {
			return new self( $source, false );
		}

		if ( ! is_string( $source ) || '' === $source ) {
			throw new BackupException( 'A backup source must be a file path or an open stream.' );
		}

		if ( ! is_file( $source ) || ! is_readable( $source ) ) {
			throw new BackupException( sprintf( 'Cannot read the backup "%s": no such readable file.', $source ) );
		}

		$handle = @fopen( 'compress.zlib://' . $source, 'rb' );

		if ( false === $handle ) {
			throw new BackupException( sprintf( 'Cannot open the backup "%s".', $source ) );
		}

		try {
			return new self( $handle, true );
		} catch ( BackupException $e ) {
			@fclose( $handle );
			throw $e;
		}
	}

	/**
	 * The header record.
	 *
	 * @return array<string, mixed>
	 */
	public function header() : array {
		return $this->header;
	}

	/**
	 * The footer record, once records() has reached it; null before.
	 *
	 * @return array<string, mixed>|null
	 */
	public function footer() : ?array {
		return $this->footer;
	}

	/**
	 * Every record after the header, up to the footer (which is not yielded).
	 *
	 * Single pass. The checksum is verified when the footer is reached.
	 *
	 * @return Generator<int, array<string, mixed>>
	 * @throws BackupException When a line is not valid JSON, the archive ends
	 *                         without a footer, or the checksum does not match.
	 */
	public function records() : Generator {
		if ( $this->started ) {
			throw new BackupException( 'A backup archive can be read once only.' );
		}

		$this->started = true;

		while ( null !== ( $line = $this->next_line() ) ) {
			$record = $this->decode( $line );

			if ( 'footer' === $record['kind'] ) {
				$this->verify_footer( $record );
				$this->footer = $record;

				if ( null !== $this->next_line() ) {
					throw new BackupException( sprintf( 'The backup has data after its footer (line %d).', $this->line ) );
				}

				return;
			}

			hash_update( $this->hash, $line );

			yield $record;
		}

		throw new BackupException( 'The backup is incomplete: it ends without a footer. It may have been truncated, or the backup that wrote it failed.' );
	}

	/**
	 * Close the archive. Safe to call more than once.
	 *
	 * @return void
	 */
	public function close() : void {
		if ( $this->owns && null !== $this->handle ) {
			@fclose( $this->handle );
		}

		$this->handle = null;
	}

	/**
	 * Close the archive when the reader is dropped.
	 */
	public function __destruct() {
		$this->close();
	}

	/**
	 * Read and check the header.
	 *
	 * @return array<string, mixed>
	 * @throws BackupException
	 */
	private function read_header() : array {
		$line = $this->next_line();

		if ( null === $line ) {
			throw new BackupException( 'The backup is empty.' );
		}

		try {
			$header = $this->decode( $line );
		} catch ( BackupException $e ) {
			throw new BackupException( 'Not a DBPrism backup: its first line is not a backup header.', 0, $e );
		}

		if ( 'header' !== $header['kind'] || ArchiveWriter::FORMAT !== ( $header['format'] ?? null ) ) {
			throw new BackupException( 'Not a DBPrism backup: its first line is not a backup header.' );
		}

		$version = $header['version'] ?? null;

		if ( ! is_int( $version ) || $version < 1 || $version > ArchiveWriter::VERSION ) {
			throw new BackupException( sprintf(
				'Unsupported backup format version %s; this version of DBPrism reads version %d or earlier.',
				is_scalar( $version ) ? (string) $version : 'unknown',
				ArchiveWriter::VERSION
			) );
		}

		hash_update( $this->hash, $line );

		return $header;
	}

	/**
	 * Compare the footer's checksum with the bytes read.
	 *
	 * @param array<string, mixed> $footer
	 * @return void
	 * @throws BackupException When it does not match.
	 */
	private function verify_footer( array $footer ) : void {
		$expected = $footer['sha256'] ?? null;
		$actual   = hash_final( $this->hash );

		if ( ! is_string( $expected ) || ! hash_equals( $expected, $actual ) ) {
			throw new BackupException( 'The backup is damaged: its checksum does not match its contents.' );
		}
	}

	/**
	 * The next line, with its newline; null at the end.
	 *
	 * @return string|null
	 * @throws BackupException When reading fails.
	 */
	private function next_line() : ?string {
		if ( null === $this->handle ) {
			throw new BackupException( 'The backup archive is closed.' );
		}

		$line = fgets( $this->handle );

		if ( false === $line ) {
			if ( ! feof( $this->handle ) ) {
				throw new BackupException( sprintf( 'Reading the backup failed after line %d.', $this->line ) );
			}

			return null;
		}

		++$this->line;

		return $line;
	}

	/**
	 * Decode one line into a record.
	 *
	 * Large integers are kept as strings, so no value loses precision.
	 *
	 * @param string $line
	 * @return array<string, mixed>
	 * @throws BackupException When the line is not a JSON record.
	 */
	private function decode( string $line ) : array {
		if ( ! str_ends_with( $line, "\n" ) ) {
			throw new BackupException( sprintf( 'The backup is incomplete: line %d is cut off.', $this->line ) );
		}

		try {
			$record = json_decode( $line, true, 512, JSON_BIGINT_AS_STRING | JSON_THROW_ON_ERROR );
		} catch ( JsonException $e ) {
			throw new BackupException( sprintf( 'The backup is damaged: line %d is not valid JSON (%s).', $this->line, $e->getMessage() ), 0, $e );
		}

		if ( ! is_array( $record ) || ! is_string( $record['kind'] ?? null ) ) {
			throw new BackupException( sprintf( 'The backup is damaged: line %d is not a backup record.', $this->line ) );
		}

		return $record;
	}
}
