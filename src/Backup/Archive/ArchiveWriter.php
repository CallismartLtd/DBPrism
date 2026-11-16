<?php
/**
 * Backup archive writer class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup\Archive
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup\Archive;

use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Utils\ParamType;
use JsonException;

/**
 * Writes a DBPrism backup archive, one JSON record per line.
 *
 * The archive is engine-neutral and is written as it is produced, so a
 * backup of any size needs memory for one batch of rows only:
 *
 *   {"kind":"header", "format":"dbprism-backup", "version":1, ...}
 *   {"kind":"table", "name":"users", "columns":[...], "constraints":[...]}   one per table, all first
 *   {"kind":"rows", "table":"users", "rows":[[...], [...]]}                    batches, table by table
 *   {"kind":"table_end", "table":"users", "rows":1200}
 *   {"kind":"footer", "tables":3, "rows":5000, "sha256":"..."}
 *
 * The footer's sha256 is the SHA-256 of every byte before the footer line,
 * so a restore can tell a complete archive from a truncated or altered one.
 *
 * A path is written to a temporary file next to it and renamed into place
 * by finish(), so the path never holds a partial backup. A path ending in
 * ".gz" is gzip-compressed. A stream resource is written as it is and is
 * left open.
 *
 * @since 2.6.0
 */
final class ArchiveWriter {

	/**
	 * The archive format name, in every header.
	 */
	public const FORMAT = 'dbprism-backup';

	/**
	 * The archive format version this writer produces.
	 */
	public const VERSION = 1;

	/**
	 * JSON flags for every record.
	 */
	public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR;

	/**
	 * The open handle.
	 *
	 * @var resource|null
	 */
	private $handle;

	/**
	 * Running SHA-256 of every line written.
	 *
	 * @var \HashContext
	 */
	private \HashContext $hash;

	/**
	 * Bytes written (uncompressed).
	 *
	 * @var int
	 */
	private int $bytes = 0;

	/**
	 * Whether finish() or abort() has run.
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Constructor. Use open().
	 *
	 * @param resource    $handle     The handle to write to.
	 * @param bool        $owns       Whether this writer opened the handle and closes it.
	 * @param string|null $temp_path  The temporary file being written, for a path target.
	 * @param string|null $final_path The path the temporary file is renamed to.
	 */
	private function __construct(
		$handle,
		private bool $owns,
		private ?string $temp_path = null,
		private ?string $final_path = null,
	) {
		$this->handle = $handle;
		$this->hash   = hash_init( 'sha256' );
	}

	/**
	 * Open an archive for writing.
	 *
	 * @param string|resource $target   A file path, or an open writable stream.
	 * @param bool|null       $compress Gzip the file. Null: when the path ends in ".gz".
	 *                                  Ignored for a stream, which is written as it is.
	 * @return self
	 * @throws BackupException When the file cannot be created.
	 */
	public static function open( mixed $target, ?bool $compress = null ) : self {
		if ( ParamType::is_stream( $target ) ) {
			return new self( $target, false );
		}

		if ( ! is_string( $target ) || '' === $target ) {
			throw new BackupException( 'A backup target must be a file path or an open stream.' );
		}

		$compress ??= str_ends_with( strtolower( $target ), '.gz' );
		$directory  = dirname( $target );

		if ( ! is_dir( $directory ) || ! is_writable( $directory ) ) {
			throw new BackupException( sprintf( 'Cannot write the backup: directory "%s" does not exist or is not writable.', $directory ) );
		}

		$temp_path = sprintf( '%s/.%s.%s.part', $directory, basename( $target ), bin2hex( random_bytes( 6 ) ) );
		$handle    = @fopen( ( $compress ? 'compress.zlib://' : '' ) . $temp_path, 'wb' );

		if ( false === $handle ) {
			throw new BackupException( sprintf( 'Cannot create the backup file "%s".', $temp_path ) );
		}

		return new self( $handle, true, $temp_path, $target );
	}

	/**
	 * Write one record.
	 *
	 * @param array<string, mixed> $record The record; its "kind" names it.
	 * @return void
	 * @throws BackupException When the record cannot be encoded or written.
	 */
	public function write( array $record ) : void {
		$this->write_line( self::encode( $record ) );
	}

	/**
	 * Write a rows record from rows already encoded as JSON arrays.
	 *
	 * Lets the caller size batches by their encoded length.
	 *
	 * @param string   $table        Table name.
	 * @param string[] $encoded_rows Each row as a JSON array.
	 * @return void
	 * @throws BackupException When the record cannot be written.
	 */
	public function write_rows( string $table, array $encoded_rows ) : void {
		$this->write_line(
			'{"kind":"rows","table":' . self::encode( $table ) . ',"rows":[' . implode( ',', $encoded_rows ) . ']}'
		);
	}

	/**
	 * Write the footer, close the archive, and move a file into place.
	 *
	 * @param array<string, mixed> $summary Footer fields besides kind and sha256.
	 * @return string The archive checksum (SHA-256, hex).
	 * @throws BackupException When the archive cannot be completed.
	 */
	public function finish( array $summary = [] ) : string {
		$this->assert_open();

		$checksum = hash_final( $this->hash );
		$footer   = self::encode( [ 'kind' => 'footer' ] + $summary + [ 'sha256' => $checksum ] ) . "\n";

		if ( false === @fwrite( $this->handle, $footer ) || ( ! $this->owns && false === @fflush( $this->handle ) ) ) {
			$this->abort();
			throw new BackupException( 'Writing the backup footer failed.' );
		}

		$this->closed = true;

		if ( $this->owns ) {
			if ( ! @fclose( $this->handle ) ) {
				$this->discard_temp();
				throw new BackupException( 'Closing the backup file failed; the disk may be full.' );
			}

			if ( ! @rename( (string) $this->temp_path, (string) $this->final_path ) ) {
				$this->discard_temp();
				throw new BackupException( sprintf( 'Moving the backup into place at "%s" failed.', $this->final_path ) );
			}
		}

		$this->handle = null;

		return $checksum;
	}

	/**
	 * Abandon the archive: close it, and delete the temporary file of a path target.
	 *
	 * Safe to call more than once. A stream target is left open; what was
	 * written to it stays there, without a footer, so it reads as truncated.
	 *
	 * @return void
	 */
	public function abort() : void {
		if ( $this->closed ) {
			return;
		}

		$this->closed = true;

		if ( $this->owns && null !== $this->handle ) {
			@fclose( $this->handle );
			$this->discard_temp();
		}

		$this->handle = null;
	}

	/**
	 * Bytes written so far, before compression.
	 *
	 * @return int
	 */
	public function bytes_written() : int {
		return $this->bytes;
	}

	/**
	 * Abort an archive that was never finished.
	 */
	public function __destruct() {
		$this->abort();
	}

	/**
	 * Encode a value as JSON.
	 *
	 * @param mixed $value
	 * @return string
	 * @throws BackupException When the value cannot be encoded.
	 */
	public static function encode( mixed $value ) : string {
		try {
			return json_encode( $value, self::JSON_FLAGS );
		} catch ( JsonException $e ) {
			throw new BackupException( 'Encoding a backup record failed: ' . $e->getMessage(), 0, $e );
		}
	}

	/**
	 * Write one line and add it to the checksum.
	 *
	 * @param string $json The record, without the newline.
	 * @return void
	 * @throws BackupException When writing fails.
	 */
	private function write_line( string $json ) : void {
		$this->assert_open();

		$line    = $json . "\n";
		$written = @fwrite( $this->handle, $line );

		if ( false === $written || strlen( $line ) !== $written ) {
			throw new BackupException( 'Writing the backup failed; the disk may be full or the stream closed.' );
		}

		hash_update( $this->hash, $line );
		$this->bytes += $written;
	}

	/**
	 * Throw when the archive is no longer open.
	 *
	 * @return void
	 * @throws BackupException
	 */
	private function assert_open() : void {
		if ( $this->closed || null === $this->handle ) {
			throw new BackupException( 'The backup archive is already closed.' );
		}
	}

	/**
	 * Delete the temporary file, if any.
	 *
	 * @return void
	 */
	private function discard_temp() : void {
		if ( null !== $this->temp_path && is_file( $this->temp_path ) ) {
			@unlink( $this->temp_path );
		}
	}
}
