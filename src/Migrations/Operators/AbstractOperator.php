<?php
/**
 * Abstract migration operator class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations\Operators
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\Operators;

use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\MigrationException;
use Callismart\DBPrism\Inspection\Inspector;
use Callismart\DBPrism\Query\SQLBuilder;

/**
 * Base class for migration operators.
 *
 * An operator makes one kind of change to one table, and every operation
 * follows the same three rules:
 *
 *  - Check first: it inspects the schema, and does nothing when the change
 *    is already there. Running a migration again is therefore safe.
 *  - Throw on failure: a failed statement throws MigrationException, so the
 *    migration stops and is not recorded as applied.
 *  - Intent only: SQL comes from the query builder, in the dialect of the
 *    database being migrated.
 *
 * @since 0.2.0
 */
abstract class AbstractOperator {

	/**
	 * Schema inspector, created on first use.
	 *
	 * @var Inspector|null
	 */
	private ?Inspector $inspector = null;

	/**
	 * Constructor.
	 *
	 * @param Database $db    The database being migrated.
	 * @param string   $table The table this operator works on.
	 */
	public function __construct(
		protected Database $db,
		protected string $table
	) {}

	/**
	 * Start building a query in the dialect of the database being migrated.
	 *
	 * @return SQLBuilder
	 */
	protected function query() : SQLBuilder {
		return new SQLBuilder( $this->db->get_driver() );
	}

	/**
	 * The schema inspector.
	 *
	 * @return Inspector
	 */
	protected function inspect() : Inspector {
		return $this->inspector ??= new Inspector( $this->db );
	}

	/**
	 * Whether the database being migrated is SQLite.
	 *
	 * @return bool
	 */
	protected function is_sqlite() : bool {
		return 'sqlite' === $this->db->get_driver();
	}

	/**
	 * Execute SQL, throwing when the database reports a failure.
	 *
	 * @param string $sql    The statement(s) to run.
	 * @param string $action What is being done, for the error message (e.g. 'Renaming table "a" to "b"').
	 * @return void
	 * @throws MigrationException When the database reports a failure.
	 */
	protected function run( string $sql, string $action ) : void {
		if ( ! $this->db->exec( $sql ) ) {
			throw new MigrationException(
				sprintf( '%s failed: %s', $action, $this->db->get_last_error() ?? 'unknown error' )
			);
		}
	}
}