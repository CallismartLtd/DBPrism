<?php
/**
 * Persistence Data Exception
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Exceptions
 * @since 2.5.0
 */

namespace Callismart\DBPrism\Exceptions;

/**
 * Thrown when the data given to an INSERT, UPDATE or UPSERT cannot be written.
 *
 * By values() given a CaseExpression, by multi_values() given no rows, a
 * row that is not an associative array, a CaseExpression, or a row whose
 * columns differ from the first row's, and by build() when an INSERT or
 * UPSERT value is a CASE expression from set_case().
 *
 * @since 2.5.0
 */
class PersistenceDataException extends QueryException {}