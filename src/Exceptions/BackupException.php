<?php
/**
 * Backup Exception
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Exceptions
 * @since 2.6.0
 */

namespace Callismart\DBPrism\Exceptions;

use RuntimeException;

/**
 * Thrown when a backup cannot be written or a restore cannot be completed:
 * a query the database refused, an archive that is unreadable, truncated,
 * altered or of an unknown format, or a restore target that conflicts with
 * the archive.
 *
 * A restore that throws has been rolled back as far as the engine allows;
 * see BackupManager::restore().
 *
 * @since 2.6.0
 */
class BackupException extends RuntimeException {}
