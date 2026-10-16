<?php
/**
 * Query Exception
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Exceptions
 * @since 2.5.0
 */

namespace Callismart\DBPrism\Exceptions;

use InvalidArgumentException;

/**
 * Base of the exceptions the query builder throws when it refuses a query.
 *
 * Thrown before any SQL reaches the database: by a builder method given
 * input it cannot use, or by build() when the query cannot be written. Catch
 * it to handle every refused query; catch a subclass for one context.
 *
 * It extends InvalidArgumentException, which these failures were thrown as
 * before 2.5.0, so existing catch blocks keep working.
 *
 * @since 2.5.0
 */
abstract class QueryException extends InvalidArgumentException {}