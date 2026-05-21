<?php
/**
 * PDO Database Adapter
 *
 * Implements the DatabaseAdapterInterface for environments using PDO (PHP Data Objects).
 * This is the preferred adapter for non-framework pure PHP environments.
 *
 * @package Callismart\DBPrism\Adapters
 */

namespace Callismart\DBPrism\Adapters;

use PDO;
use PDOException;
use PDOStatement;
use Callismart\DBPrism\DBConfigDTO;
use Callismart\DBPrism\Adapters\Contracts\DatabaseAdapterInterface;
use Callismart\DBPrism\Utils\SQLStatementSplitter;

/**
 * Adapter for PDO database access.
 */
class PdoAdapter implements DatabaseAdapterInterface {

    /**
     * The PDO connection instance.
     *
     * @var PDO|null
     */
    public ?PDO $pdo;

    /**
     * Configuration settings for the PDO connection.
     *
     * @var DBConfigDTO
     */
    protected DBConfigDTO $config;

    /**
     * Last inserted ID.
     *
     * @var int|null
     */
    protected ?int $insert_id = null;

    /**
     * Last executed error message.
     *
     * @var string|null
     */
    protected ?string $last_error = null;

    /**
     * SQL query splitter instance.
     *
     * @var SQLStatementSplitter|null
     */
    protected ?SQLStatementSplitter $splitter = null;

    /**
     * Constructor.
     *
     * @param DBConfigDTO $config Database connection configuration.
     */
    public function __construct( DBConfigDTO $config ) {
        $this->config = $config;
        $this->connect();
    }

    /**
     * Destructor.
     */
    public function __destruct() {
        $this->close();
    }

    /**
     * Establish a database connection.
     *
     * @return bool True on success, false on failure.
     */
    protected function connect() : bool {
        if ( $this->is_connected() ) {
            return true;
        }

        try {
            $dsn    = $this->build_dsn();

            $flags  = (array) $this->config->flags ?? [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES => false,
            ];

            $this->pdo = new PDO(
                $dsn,
                $this->config->username,
                $this->config->password,
                $flags
            );

            return true;
        } catch ( PDOException $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Ensure database connection
     * 
     * @return bool
     */
    protected function ensure_connection() : bool {
        if ( $this->is_connected() ) {
            return true;
        }

        $this->connect();

        if ( ! $this->is_connected() ) {
            $this->last_error = 'No active PDO connection.';
            return false;
        }

        return true;
    }

    /**
     * Close the active database connection.
     *
     * @return void
     */
    protected function close() : void {
        $this->pdo = null;
    }

    /**
     * Build PDO DSN string from configuration.
     *
     * @return string
     * @throws PDOException
     */
    protected function build_dsn() : string {

        if ( isset( $this->config->dsn ) ) {
            return $this->config->dsn;
        }

        if ( ! isset( $this->config->driver ) ) {
            throw new PDOException( 'Database driver was not specified.' );
        }

        return match ( $this->config->driver ) {

            'mysql'  => $this->build_mysql_dsn(),

            'pgsql'  => $this->build_pgsql_dsn(),

            'sqlite' => $this->build_sqlite_dsn(),

            default  => $this->build_generic_dsn(),
        };
    }

    /**
     * Build MySQL DSN string.
     *
     * @return string
     * @throws PDOException
     */
    protected function build_mysql_dsn() : string {

        if ( isset( $this->config->socket ) ) {

            $dsn = sprintf(
                'mysql:unix_socket=%s;',
                $this->config->socket
            );

        } else {

            if ( ! isset( $this->config->dbname ) ) {
                throw new PDOException( 'Database name was not specified.' );
            }

            $dsn = sprintf(
                'mysql:host=%s;dbname=%s;',
                $this->config->host ?? 'localhost',
                $this->config->dbname
            );

            if ( isset( $this->config->port ) ) {
                $dsn .= sprintf( 'port=%d;', $this->config->port );
            }
        }

        if ( isset( $this->config->charset ) ) {
            $dsn .= sprintf( 'charset=%s;', $this->config->charset );
        }

        return $dsn;
    }

    /**
     * Build PostgreSQL DSN string.
     *
     * @return string
     * @throws PDOException
     */
    protected function build_pgsql_dsn() : string {

        if ( ! isset( $this->config->dbname ) ) {
            throw new PDOException( 'Database name was not specified.' );
        }

        $dsn = sprintf(
            'pgsql:host=%s;dbname=%s;',
            $this->config->host ?? 'localhost',
            $this->config->dbname
        );

        if ( isset( $this->config->port ) ) {
            $dsn .= sprintf( 'port=%d;', $this->config->port );
        }

        if ( isset( $this->config->charset ) ) {
            $dsn .= sprintf( 'options=\'--client_encoding=%s\';', $this->config->charset );
        }

        return $dsn;
    }

    /**
     * Build SQLite DSN string.
     *
     * @return string
     * @throws PDOException
     */
    protected function build_sqlite_dsn() : string {

        if ( ! isset( $this->config->path ) ) {
            throw new PDOException( 'SQLite database path was not specified.' );
        }

        return sprintf(
            'sqlite:%s',
            $this->config->path
        );
    }

    /**
     * Build generic DSN string fallback.
     *
     * @return string
     * @throws PDOException
     */
    protected function build_generic_dsn() : string {

        $dsn = sprintf(
            '%s:',
            $this->config->driver
        );

        $parts = [];

        foreach ( [ 'host', 'port', 'dbname', 'charset' ] as $key ) {

            if ( isset( $this->config->$key ) ) {
                $parts[] = sprintf( '%s=%s', $key, $this->config->$key );
            }
        }

        if ( empty( $parts ) ) {
            throw new PDOException(
                sprintf( 'Unable to build DSN for driver "%s".', $this->config->driver )
            );
        }

        return $dsn . implode( ';', $parts ) . ';';
    }

    /**
     * Begin a database transaction.
     *
     * @return void
     */
    public function begin_transaction() : void {
        if ( ! $this->ensure_connection() || $this->pdo->inTransaction() ) {
            return;
        }

        $this->pdo->beginTransaction();
    }

    /**
     * Commit the current transaction.
     *
     * @return void
     */
    public function commit() : void {
        if ( $this->pdo && $this->pdo->inTransaction() ) {
            $this->pdo->commit();
        }
    }

    /**
     * Roll back the current transaction.
     *
     * @return void
     */
    public function rollback() : void {
        if ( $this->pdo && $this->pdo->inTransaction() ) {
            $this->pdo->rollBack();
        }
    }

    /**
     * Execute a raw SQL query with optional parameters.
     *
     * Uses positional placeholders (?) for parameter binding.
     *
     * @param string $query  The SQL query with ? placeholders.
     * @param array  $params Optional. The bound values for placeholders.
     *
     * @return PDOStatement|false The native statement object, or false on failure.
     */
    protected function query( $query, array $params = [] ) : PDOStatement|false {
        if ( ! $this->ensure_connection() ) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare( $query );

            if ( ! $stmt ) {
                $this->last_error   = $this->pdo->errorInfo()[2] ?? sprintf( '%s prepare statement failed.', static::class );
                return false;
            }

            if ( ! empty( $params ) ) {
                foreach ( $params as $i => $param ) {
                    $type = $this->get_param_type( $param );
                    // PDO uses 1-based indices for positional placeholders
                    $stmt->bindValue( $i + 1, $param, $type );
                }
            }

            $stmt->execute();
            
            return $stmt;

        } catch ( PDOException $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Determine the PDO parameter type for a value.
     *
     * @param mixed $param The parameter value.
     * @return int PDO::PARAM_* constant.
     */
    protected function get_param_type( $param ) : int {
        if ( is_null( $param ) ) {
            return PDO::PARAM_NULL;
        } elseif ( is_bool( $param ) ) {
            return PDO::PARAM_BOOL;
        } elseif ( is_int( $param ) ) {
            return PDO::PARAM_INT;
        } else {
            return PDO::PARAM_STR;
        }
    }

    /**
     * Retrieve a single row as an associative array.
     *
     * @param string $query  SQL query with ? placeholders.
     * @param array  $params Optional. Bound values for placeholders.
     *
     * @return array|null Associative array of the row, or null if not found.
     */
    public function get_row( $query, array $params = [] ) : ?array {
        $stmt = $this->query( $query, $params );
        
        if ( false === $stmt ) {
            return null;
        }
        
        $row = $stmt->fetch( PDO::FETCH_ASSOC );
        return $row ?: null;
    }

    /**
     * Retrieve multiple rows as an array of associative arrays.
     *
     * @param string $query  SQL query with ? placeholders.
     * @param array  $params Optional. Bound values for placeholders.
     *
     * @return array List of associative arrays representing result rows.
     */
    public function get_results( $query, array $params = [] ) : array {
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return [];
        }
        
        return $stmt->fetchAll( PDO::FETCH_ASSOC );
    }

    /**
     * Retrieve a single scalar value.
     *
     * @param string $query  SQL query with ? placeholders.
     * @param array  $params Optional. Bound values for placeholders.
     *
     * @return mixed|null The first column of the first row, or null if none.
     */
    public function get_var( $query, array $params = [] ) : mixed {
        $stmt = $this->query( $query, $params );
        
        if ( false === $stmt ) {
            return null;
        }
        
        $value = $stmt->fetchColumn();
        return $value !== false ? $value : null;
    }

    /**
     * Retrieve a single column of values.
     *
     * @param string $query  SQL query with ? placeholders.
     * @param array  $params Optional. Bound values for placeholders.
     *
     * @return array List of column values, or empty array if none found.
     */
    public function get_col( $query, array $params = [] ) : array {
        $stmt = $this->query( $query, $params );
        
        if ( false === $stmt ) {
            return [];
        }
        
        return $stmt->fetchAll( PDO::FETCH_COLUMN, 0 );
    }

    /**
     * Insert a record into the database.
     *
     * @param string $table Table name.
     * @param array  $data  Associative array of column => value.
     *
     * @return int|false The inserted record ID on success, false on failure.
     */
    public function insert( $table, array $data ) : int|false {
        if ( empty( $data ) ) {
            $this->last_error = 'Insert data cannot be empty.';
            return false;
        }

        $columns = array_keys( $data );
        $placeholders = array_fill( 0, count( $data ), '?' );

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode( ', ', $columns ),
            implode( ', ', $placeholders )
        );

        $params = array_values( $data );
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return false;
        }

        // Store insert ID for INSERT queries
        $this->insert_id = (int) $this->pdo->lastInsertId() ?: null;

        return $this->get_insert_id();
    }

    /**
     * Update existing records.
     *
     * @param string $table Table name.
     * @param array  $data  Associative array of column => value.
     * @param array  $where Associative array for WHERE conditions.
     *
     * @return int|false Number of affected rows, or false on failure.
     */
    public function update( $table, array $data, array $where ) : int|false {
        if ( empty( $data ) || empty( $where ) ) {
            $this->last_error = 'Update data and WHERE condition cannot be empty.';
            return false;
        }

        // Build SET clause with ? placeholders
        $set_clauses = array_map( function( $column ) {
            return "$column = ?";
        }, array_keys( $data ) );

        // Build WHERE clause with ? placeholders
        $where_clauses = array_map( function( $column ) {
            return "$column = ?";
        }, array_keys( $where ) );

        $query = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode( ', ', $set_clauses ),
            implode( ' AND ', $where_clauses )
        );

        // Merge data and where values for positional binding
        $params = array_merge( array_values( $data ), array_values( $where ) );
        
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return false;
        }

        return $stmt->rowCount();
    }

    /**
     * Delete records from the database.
     *
     * @param string $table Table name.
     * @param array  $where Associative array for WHERE conditions.
     *
     * @return int|false Number of affected rows, or false on failure.
     */
    public function delete( $table, array $where ) : int|false {
        if ( empty( $where ) ) {
            $this->last_error = 'Delete WHERE condition cannot be empty.';
            return false;
        }

        // Build WHERE clause with ? placeholders
        $where_clauses = array_map( function( $column ) {
            return "$column = ?";
        }, array_keys( $where ) );

        $query = sprintf(
            'DELETE FROM %s WHERE %s',
            $table,
            implode( ' AND ', $where_clauses )
        );

        $params = array_values( $where );
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return false;
        }

        return $stmt->rowCount();
    }

    /**
     * Retrieve the last inserted ID.
     *
     * @return int|null The last inserted ID, or null if not available.
     */
    public function get_insert_id() : ?int {
        return $this->insert_id;
    }

    /**
     * Retrieve the last database error.
     *
     * @return string|null The last error message, or null if none.
     */
    public function get_last_error() : ?string {
        return $this->last_error;
    }

    /**
     * {@inheritdoc}
     */
    public function get_driver() : string {
        $this->ensure_connection();
        return $this->is_connected() ? 
        (string) $this->pdo->getAttribute( PDO::ATTR_DRIVER_NAME ) :
        $this->config->driver ?? 'unknown';
    }

    /**
     * {@inheritdoc}
     */
    public function get_config() : DBConfigDTO {
        return $this->config;
    }

    /**
     * {@inheritdoc}
     */
    public function exec( string $query ): bool {
        if ( ! $this->ensure_connection() ) {
            return false;
        }
    
        try {
            // Parse the query string into individual statements
            $splitter = $this->get_parser();
            $queries = $splitter->split( $query );
    
            if ( empty( $queries ) ) {
                return true; // Empty input is not an error
            }
    
            // Execute each statement
            foreach ( $queries as $stmt ) {
                $result = $this->pdo->exec( $stmt );
                if ( false === $result ) {
                    $this->last_error = 'PDO exec failed for statement: ' . substr( $stmt, 0, 50 );
                    return false;
                }
            }
    
            return true;
    
        } catch ( \PDOException $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function execute( string $query, array $params = [] ) : int {
        $stmt   = $this->query( $query, $params );

        if ( ! $stmt ) {
            return 0;
        }

        if ( $this->pdo->lastInsertId() ) {
            $this->insert_id    = (int) $this->pdo->lastInsertId();
        }

        return $stmt->rowCount();

    }

    /**
     * Check connection state.
     */
    public function is_connected(): bool {
        return isset( $this->pdo ) && $this->pdo instanceof \PDO;
    }

    /**
     * Get or instantiate the SQL query parser.
     *
     * @return SQLStatementSplitter
     */
    protected function get_parser(): SQLStatementSplitter {
        if ( $this->splitter === null ) {
            $this->splitter = new SQLStatementSplitter();
        }
        return $this->splitter;
    }
}