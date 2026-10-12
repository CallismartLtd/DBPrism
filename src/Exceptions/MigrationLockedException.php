<?php
/**
 * Migration Locked Exception
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Exceptions
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Exceptions;

/**
 * Thrown when migrations cannot run because another run holds the lock.
 *
 * Nothing was changed. Catch it to skip this run quietly, e.g. when two
 * requests start an update at the same moment.
 *
 * @since 0.2.0
 */
class MigrationLockedException extends MigrationException {}