<?php
/**
 * Migration Exception
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Exceptions
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Exceptions;

use RuntimeException;

/**
 * Thrown when a migration step fails, so the failure stops the migration
 * instead of being returned as a value that up() or down() could ignore.
 *
 * @since 0.2.0
 */
class MigrationException extends RuntimeException {}