<?php
/**
 * Null database adapter class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Adapters
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Adapters;

use Callismart\DBPrism\Adapters\Contracts\DatabaseAdapterInterface;
use Callismart\DBPrism\DBConfigDTO;

/**
 * Placeholder adapter that never connects.
 *
 * Lets a Database instance be constructed when no database is configured
 * yet (for example during installation, before credentials exist), so
 * dependent services can still be built. Swap it for a real adapter with
 * Database::set_adapter() once a connection has been verified.
 *
 * It holds no configuration: get_config() throws, because no valid
 * DBConfigDTO exists until a database is configured. Check
 * Database::has_null_adapter() before reading the configuration.
 *
 * Every query method returns the "nothing happened" value its contract
 * allows and records an error; none of them throw. Callers must therefore
 * treat an empty result as meaningful only when is_connected() is true:
 * from this adapter, an empty result means "no database", not "no rows".
 *
 * Transactions are no-ops.
 */
final class NullDBAdapter implements DatabaseAdapterInterface {

	/**
	 * Driver name reported by this adapter.
	 */
	public const DRIVER = 'null';

	/**
	 * Default error message.
	 */
	public const DEFAULT_REASON = 'No database connection is configured.';

	/**
	 * Last recorded error.
	 *
	 * @var string|null
	 */
	private ?string $last_error = null;

	/**
	 * Class constructor.
	 *
	 * @param string $reason Message reported by get_last_error() after any operation.
	 */
	public function __construct(
		private readonly string $reason = self::DEFAULT_REASON
	) {}

	/*
	|------------
	| Connection
	|------------
	*/

	/**
	 * {@inheritdoc}
	 */
	public function connect() : bool {
		return $this->fail( false );
	}

	/**
	 * {@inheritdoc}
	 */
	public function is_connected() : bool {
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function close() : void {}

	/*
	|--------------
	| Transactions
	|--------------
	*/

	/**
	 * {@inheritdoc}
	 */
	public function begin_transaction() {}

	/**
	 * {@inheritdoc}
	 */
	public function commit() {}

	/**
	 * {@inheritdoc}
	 */
	public function rollback() {}

	/*
	|---------
	| Queries
	|---------
	*/

	/**
	 * {@inheritdoc}
	 */
	public function execute( string $query, array $params = [] ) : int {
		return $this->fail( 0 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function exec( string $query ) : bool {
		return $this->fail( false );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_row( $query, array $params = [] ) {
		return $this->fail( null );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_results( $query, array $params = [] ) {
		return $this->fail( [] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_var( $query, array $params = [] ) {
		return $this->fail( null );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_col( $query, array $params = [] ) {
		return $this->fail( [] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function insert( $table, array $data ) {
		return $this->fail( false );
	}

	/**
	 * {@inheritdoc}
	 */
	public function update( $table, array $data, array $where ) {
		return $this->fail( false );
	}

	/**
	 * {@inheritdoc}
	 */
	public function delete( $table, array $where ) {
		return $this->fail( false );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_insert_id() {
		return null;
	}

	/*
	|-------------
	| Diagnostics
	|-------------
	*/

	/**
	 * {@inheritdoc}
	 */
	public function get_last_error() {
		return $this->last_error;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_driver() : string {
		return self::DRIVER;
	}

	/**
	 * No configuration exists for the placeholder adapter.
	 *
	 * @return DBConfigDTO Never returns.
	 * @throws \LogicException Always.
	 */
	public function get_config() : DBConfigDTO {
		throw new \LogicException(
			sprintf( '%s has no database configuration: %s', self::class, $this->reason )
		);
	}

	/**
	 * Record the unavailability error and return the given fallback.
	 *
	 * @param mixed $fallback Value to return.
	 * @return mixed
	 */
	private function fail( mixed $fallback ) : mixed {
		$this->last_error = $this->reason;

		return $fallback;
	}
}