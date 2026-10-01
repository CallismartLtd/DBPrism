<?php
/**
 * The Database Abstraction file.
 *
 * @package Callismart\DBPrism\Adapters
 * @since 0.2.0
 */

namespace Callismart\DBPrism;

use Callismart\DBPrism\Adapters\Contracts\DatabaseAdapterInterface;
use Callismart\DBPrism\Adapters\NullDBAdapter;

/**
 * Database abstraction API.
 *
 * Database singleton class for Smart License Server.
 *
 * Acts as a proxy to the environment-specific database adapter.
 *
 * @method bool connect() Connect to the database.
 *
 * @method array|null get_row( string $query, array $params = [] ) Retrieve a single row as an associative array.
 * @method array get_results( string $query, array $params = [] ) Retrieve multiple rows as an array of associative arrays.
 * @method mixed|null get_var( string $query, array $params = [] ) Retrieve a single scalar value.
 * @method array get_col( string $query, array $params = [] ) Retrieve a single column of values.
 * @method int|false insert( string $table, array $data ) Insert a record into the database.
 * @method int|false update( string $table, array $data, array $where ) Update existing records.
 * @method int|false delete( string $table, array $where ) Delete records from the database.
 *
 * @method void begin_transaction() Begin a database transaction.
 * @method void commit() Commit the current transaction.
 * @method void rollback() Roll back the current transaction.
 *
 * @method string|null get_last_error() Get last database error.
 * @method int|null get_insert_id() Get the last insertion ID.
 *
 * @method bool exec(string $query) Execute a raw SQL query without prepared statements.
 * @method int execute( string $query, array $params = [] ) Execute a parameterized query and return the number of affected rows.
 *
 * @method string get_driver() Get the engine type (mysql, sqlite, etc).
 * @method \Callismart\DBPrism\DBConfigDTO get_config() Get the database configuration object.
 *
 * @method bool is_connected() Check whether the database connection is alive.
 * @method void close() Close the active database connection.
 */
class Database {

    /**
     * Track active transaction nesting level depth.
     * Prevents nested code modules from firing false commits/rollbacks.
     */
    protected int $transaction_depth = 0;

    /**
     * Class constructor.
     *
     * @param DatabaseAdapterInterface $adapter Database adapter instance.
     */
    public function __construct( protected DatabaseAdapterInterface $adapter ) {}

    /**
     * Proxy calls to the adapter methods.
     *
     * @param string $method Method name.
     * @param array  $args   Method arguments.
     *
     * @return mixed
     * @throws \BadMethodCallException
     */
    public function __call( $method, $args ) {
        if ( method_exists( $this->adapter, $method ) ) {
            return call_user_func_array( [ $this->adapter, $method ], $args );
        }

        $backtrace  = \debug_backtrace( \DEBUG_BACKTRACE_IGNORE_ARGS, 3 );
        $file       = $backtrace[0]['file'] ?? null;
        $line       = $backtrace[0]['line'] ?? null;
        $message    = sprintf(
            'Method %s::%s does not exist.', 
            get_class( $this ),
            $method
        );

        throw new \ErrorException( $message, 0, 1, $file, $line );
    }

    /**
     * Execute a set of queries within a transaction block securely.
     *
     * Handles nested calls gracefully using counter gates.
     *
     * @param callable $callback A function containing the database logic.
     * @return mixed Returns the result of the callback on success.
     * @throws \Throwable
     */
    public function transactional( callable $callback ) : mixed {
        $this->transaction_depth++;

        // Only tell the driver adapter to fire a real block
        // start for the top-level request.
        if ( 1 === $this->transaction_depth ) {
            $this->begin_transaction(); 
            // Note: If this is SQLiteAdapter,
            // it runs 'BEGIN IMMEDIATE TRANSACTION' internally.
        }

        try {
            $result = $callback( $this );

            $this->transaction_depth--;
            
            // Only finalize and commit if we are exiting
            // the absolute outermost block safely.
            if ( 0 === $this->transaction_depth ) {
                $this->commit();
            }

            return $result;

        } catch ( \Throwable $th ) {
            // If any nested point breaks, force a hard rollback,
            // reset tracking, and bubbles up.
            if ( $this->transaction_depth > 0 ) {
                $this->rollback();
                $this->transaction_depth = 0;
            }

            throw $th;
        }
    }

    /**
     * Calculate query offset from page and limit.
     * 
     * @param int $page The current pagination number.
     * @param int $limit The result limit for the current request.
     * @return int Calculated offset.
     */
    public static function calculate_query_offset( int $page, int $limit ) {
        $page   = max( 1, $page );
        $limit  = $limit;
        return max( 0, ( $page - 1 ) * $limit );
    }
    
    /**
     * Get the charset and collation string for table creation.
     *
     * @return string SQL fragment for charset and collation.
     */
    public function get_charset_collate() {        
        if ( 'mysql' !== $this->get_driver() ) {
            return '';
        }

        $charset = 'utf8mb4';
        $collate = 'utf8mb4_unicode_ci';

        if ( isset( $this->adapter->config['charset'] ) ) {
            $charset = $this->adapter->config['charset'];
        }

        return sprintf( 'DEFAULT CHARSET=%s COLLATE=%s', $charset, $collate );
    }

    /**
     * Get the underly adapter.
     */
    public function get_adapter() : DatabaseAdapterInterface {
        return $this->adapter;
    }

    /**
     * Replace the active adapter for the rest of the request.
     *
     * Every holder of this Database instance uses the new adapter from the
     * next call onward. Typical use: swap a NullDBAdapter for a real adapter
     * once its credentials have been verified.
     *
     * @param DatabaseAdapterInterface $adapter       The adapter to activate.
     * @param bool                     $close_current Whether to close the outgoing adapter's connection.
     * @return static
     * @throws \LogicException When a transaction is open on the current adapter.
     */
    public function set_adapter( DatabaseAdapterInterface $adapter, bool $close_current = true ) : static {
        if ( $adapter === $this->adapter ) {
            return $this;
        }

        if ( $this->transaction_depth > 0 ) {
            throw new \LogicException(
                'Cannot switch the database adapter while a transaction is open.'
            );
        }

        if ( $close_current ) {
            $this->adapter->close();
        }

        $this->adapter = $adapter;

        return $this;
    }

    /**
     * Whether the active adapter is the NullDBAdapter placeholder.
     *
     * @return bool
     */
    public function has_null_adapter() : bool {
        return $this->adapter instanceof NullDBAdapter;
    }
}