<?php
/**
 * Streaming database adapter interface file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 */

namespace Callismart\DBPrism\Adapters\Contracts;

use Callismart\DBPrism\DBResultStream;

/**
 * An adapter that can stream a query result instead of loading it into memory.
 *
 * Separate from DatabaseAdapterInterface so existing implementations keep
 * working; callers check for it before streaming:
 *
 *   if ( $adapter instanceof StreamingAdapterInterface ) {
 *       foreach ( $adapter->stream( $sql, $params ) as $row ) { ... }
 *   }
 */
interface StreamingAdapterInterface extends DatabaseAdapterInterface {

	/**
	 * Run a query and return its rows as a forward-only stream.
	 *
	 * Rows are read from the database as they are consumed. The stream
	 * releases the result when it reaches the end, is closed, or is destroyed.
	 *
	 * @param string $query  A query that returns rows, with ? placeholders.
	 * @param array  $params Values for the placeholders.
	 * @return DBResultStream|false False when the query cannot be run; see get_last_error().
	 */
	public function stream( string $query, array $params = [] ) : DBResultStream|false;
}