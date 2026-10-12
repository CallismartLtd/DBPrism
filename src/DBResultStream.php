<?php
/**
 * Database result stream class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 */

declare( strict_types=1 );

namespace Callismart\DBPrism;

use Iterator;
use LogicException;
use Throwable;

/**
 * A forward-only cursor over a query result, read from the database as rows
 * are consumed instead of being loaded into memory first.
 *
 * The stream does not know the driver: the adapter that opens it passes a
 * fetcher, which returns the next row from its native result (or null at the
 * end), and a closer, which releases that result. Memory use stays at one
 * row (or one driver batch) however large the result is.
 *
 * Use it with foreach, or pull rows with fetch():
 *
 *   $stream = $adapter->stream( 'SELECT * FROM licenses WHERE status = ?', [ 'active' ] );
 *
 *   foreach ( $stream as $row ) {
 *       fputcsv( $out, $row );
 *   }
 *
 * Rules:
 *
 *  - Forward-only and single-pass. A foreach on a stream that is still open
 *    continues with the next unread row (so fetch() can read a first row and
 *    foreach the rest); a foreach on a stream that has ended or was closed
 *    throws LogicException, so a second pass is never silently empty.
 *  - It closes itself at the end of the result, when it is destroyed, and on
 *    error. close() releases it early; it is safe to call more than once.
 *  - A failure while reading throws DBResultStreamException, so a broken
 *    connection is never mistaken for the end of the data.
 *  - On MySQL the connection cannot run other queries until the stream is
 *    closed (an unbuffered result holds the connection). Read it to the end,
 *    or close() it, before using the adapter again.
 *
 * @package Callismart\DBPrism
 */
final class DBResultStream implements Iterator {

	/**
	 * Returns the next row as an associative array, or null at the end.
	 *
	 * @var callable(): (array<string, mixed>|null)
	 */
	private $fetcher;

	/**
	 * Releases the native result. Called once.
	 *
	 * @var (callable(): void)|null
	 */
	private $closer;

	/**
	 * The row at the cursor; null before the first read and after the end.
	 *
	 * @var array<string, mixed>|null
	 */
	private ?array $row = null;

	/**
	 * Zero-based index of the row at the cursor.
	 *
	 * @var int
	 */
	private int $position = -1;

	/**
	 * Number of rows read so far.
	 *
	 * @var int
	 */
	private int $count = 0;

	/**
	 * Whether a row has been read (the stream can no longer be rewound).
	 *
	 * @var bool
	 */
	private bool $started = false;

	/**
	 * Whether the native result has been released.
	 *
	 * @var bool
	 */
	private bool $closed = false;

	/**
	 * Constructor.
	 *
	 * @param callable      $fetcher fn(): ?array — the next row, or null at the end.
	 * @param callable|null $closer  fn(): void — releases the native result.
	 */
	public function __construct( callable $fetcher, ?callable $closer = null ) {
		$this->fetcher = $fetcher;
		$this->closer  = $closer;
	}

	/**
	 * Release the native result when the stream is dropped.
	 */
	public function __destruct() {
		try {
			$this->close();
		} catch ( Throwable $e ) {
			// A destructor must not throw; the result is gone either way.
		}
	}

	/*
	|---------------
	| Reading
	|---------------
	*/

	/**
	 * Read the next row.
	 *
	 * @return array<string, mixed>|null The row, or null at the end.
	 * @throws DBResultStreamException When the database fails while reading.
	 */
	public function fetch() : ?array {
		$this->started = true;

		if ( $this->closed ) {
			$this->row = null;
			return null;
		}

		try {
			$row = ( $this->fetcher )();
		} catch ( Throwable $e ) {
			$this->row = null;
			$this->close_quietly();

			throw $e instanceof DBResultStreamException
				? $e
				: new DBResultStreamException( 'Reading the result stream failed: ' . $e->getMessage(), (int) $e->getCode(), $e );
		}

		if ( null === $row ) {
			$this->row = null;
			$this->close();

			return null;
		}

		$this->row = $row;
		++$this->position;
		++$this->count;

		return $row;
	}

	/**
	 * Number of rows read so far.
	 *
	 * @return int
	 */
	public function rows_read() : int {
		return $this->count;
	}

	/*
	|---------------
	| Lifecycle
	|---------------
	*/

	/**
	 * Release the native result. Safe to call more than once.
	 *
	 * @return void
	 */
	public function close() : void {
		if ( $this->closed ) {
			return;
		}

		$this->closed = true;
		$closer       = $this->closer;
		$this->closer = null;

		if ( null !== $closer ) {
			$closer();
		}
	}

	/**
	 * Whether the native result has been released.
	 *
	 * @return bool
	 */
	public function is_closed() : bool {
		return $this->closed;
	}

	/**
	 * Close after a read error without masking that error.
	 *
	 * @return void
	 */
	private function close_quietly() : void {
		try {
			$this->close();
		} catch ( Throwable $e ) {
			// The read error is the one to report.
		}
	}

	/*
	|---------------
	| Iterator
	|---------------
	*/

	/**
	 * Start iterating: read the first row, or, when rows were already read,
	 * continue with the next unread one.
	 *
	 * @return void
	 * @throws LogicException When the stream has ended or was closed: it cannot be read again.
	 */
	public function rewind() : void {
		if ( $this->started && $this->closed ) {
			throw new LogicException( 'DBResultStream is forward-only: it has ended or was closed and cannot be read again.' );
		}

		$this->fetch();
	}

	/**
	 * Whether the cursor is on a row.
	 *
	 * @return bool
	 */
	public function valid() : bool {
		return null !== $this->row;
	}

	/**
	 * The row at the cursor.
	 *
	 * @return array<string, mixed>|null
	 */
	public function current() : ?array {
		return $this->row;
	}

	/**
	 * Zero-based index of the row at the cursor.
	 *
	 * @return int
	 */
	public function key() : int {
		return $this->position;
	}

	/**
	 * Move to the next row.
	 *
	 * @return void
	 */
	public function next() : void {
		$this->fetch();
	}
}