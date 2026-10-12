<?php
/**
 * Database result stream exception class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 */

declare( strict_types=1 );

namespace Callismart\DBPrism;

use RuntimeException;

/**
 * Thrown when the database fails while a DBResultStream is being read, so a
 * broken connection or aborted cursor is never mistaken for the end of the data.
 *
 * The driver's own exception, when there was one, is available as getPrevious().
 *
 * @package Callismart\DBPrism
 */
class DBResultStreamException extends RuntimeException {}