<?php
/**
 * Upsert Exception
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Exceptions
 * @since 2.5.0
 */

namespace Callismart\DBPrism\Exceptions;

/**
 * Thrown when an upsert is set up in a way it cannot run.
 *
 * By on_conflict() or update_columns() given no column, by set_case(), and
 * by build() when the conflict key is missing or not inserted, an update
 * column is not inserted, or the query has WHERE conditions.
 *
 * @since 2.5.0
 */
class UpsertException extends QueryException {}