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
use Callismart\DBPrism\DBResultStream;
use Callismart\DBPrism\DBResultStreamException;
use Callismart\DBPrism\Adapters\Contracts\StreamingAdapterInterface;
use Callismart\DBPrism\Utils\DsnBuilder;
use Callismart\DBPrism\Utils\ParamType;
use Callismart\DBPrism\Utils\SQLStatementSplitter;

/**
 * Adapter for PDO database access.
 */
class PdoAdapter implements StreamingAdapterInterface {

    /**
     * PDO attributes applied to every connection unless the configuration's
     * `flags` sets them.
     *
     * @var array<int, mixed>
     */
    protected const DEFAULT_FLAGS = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ];

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
    public function connect() : bool {
        if ( $this->is_connected() ) {
            return true;
        }

        try {
            $dsn    = DsnBuilder::build( $this->config );

            // Configured flags override the defaults. "+" keeps the integer
            // PDO::ATTR_* keys; array_merge() would renumber them.
            $flags  = (array) ( $this->config->flags ?? [] ) + static::DEFAULT_FLAGS;

            $this->pdo = new PDO(
                $dsn,
                $this->config->username,
                $this->config->password,
                $flags
            );

            return true;
        } catch ( \Throwable $e ) {
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
    public function close() : void {
        $this->pdo = null;
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

        try {
            $this->pdo->beginTransaction();
        } catch ( PDOException $e ) {
            $this->last_error = $e->getMessage();
        }
    }

    /**
     * Commit the current transaction.
     *
     * @return void
     */
    public function commit() : void {
        if ( ! $this->pdo || ! $this->pdo->inTransaction() ) {
            return;
        }

        try {
            $this->pdo->commit();
        } catch ( PDOException $e ) {
            $this->last_error = $e->getMessage();
        }
    }

    /**
     * Roll back the current transaction.
     *
     * @return void
     */
    public function rollback() : void {
        if ( ! $this->pdo || ! $this->pdo->inTransaction() ) {
            return;
        }

        try {
            $this->pdo->rollBack();
        } catch ( PDOException $e ) {
            $this->last_error = $e->getMessage();
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
        $this->last_error = null;

        // A value that cannot be bound throws here, before anything is sent.
        $bindings = [];

        foreach ( array_values( $params ) as $param ) {
            $bindings[] = [ $this->get_bind_value( $param ), $this->get_bind_type( $param ) ];
        }

        if ( ! $this->ensure_connection() ) {
            return false;
        }

        try {
            $stmt = $this->pdo->prepare( $query );

            if ( ! $stmt ) {
                $this->last_error   = $this->pdo->errorInfo()[2] ?? sprintf( '%s prepare statement failed.', static::class );
                return false;
            }

            foreach ( $bindings as $i => [ $value, $type ] ) {
                // PDO uses 1-based indices for positional placeholders.
                $stmt->bindValue( $i + 1, $value, $type );
            }

            $stmt->execute();
            
            return $stmt;

        } catch ( PDOException $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Whether a statement is an INSERT (or MySQL's REPLACE), which sets the insert ID.
     *
     * @param string $query SQL statement.
     * @return bool
     */
    protected function is_insert( string $query ) : bool {
        return (bool) preg_match( '/^\s*(?:INSERT|REPLACE)\b/i', $query );
    }

    /**
     * SQL appended to the INSERT built by insert(), to return the generated ID.
     *
     * None here: lastInsertId() reads it. Engines without a usable
     * lastInsertId() return a RETURNING clause instead.
     *
     * @param string $table Table name, as given to insert().
     * @return string
     */
    protected function insert_returning( string $table ) : string {
        return '';
    }

    /**
     * The ID generated by the INSERT insert() just ran.
     *
     * @param PDOStatement $stmt The executed INSERT.
     * @return int|null Null when the table generates no ID.
     */
    protected function inserted_id( PDOStatement $stmt ) : ?int {
        return (int) $this->pdo->lastInsertId() ?: null;
    }

    /**
     * Record the insert ID after execute() ran a statement.
     *
     * An INSERT that generates no ID clears it instead of keeping the last one;
     * other statements leave it as it is.
     *
     * @param string $query The statement execute() ran.
     * @return void
     */
    protected function capture_insert_id( string $query ) : void {
        $last_id = $this->pdo->lastInsertId();

        if ( $last_id ) {
            $this->insert_id = (int) $last_id;
        } elseif ( $this->is_insert( $query ) ) {
            $this->insert_id = null;
        }
    }

    /**
     * The PDO::PARAM_* type for a value.
     *
     * @param mixed $value Value to bind.
     * @return int
     * @throws \InvalidArgumentException When the value cannot be bound.
     */
    protected function get_bind_type( mixed $value ) : int {
        return match ( ParamType::of( $value ) ) {
            ParamType::NULL   => PDO::PARAM_NULL,
            ParamType::BOOL   => PDO::PARAM_BOOL,
            ParamType::INT    => PDO::PARAM_INT,
            ParamType::FLOAT,
            ParamType::STRING => PDO::PARAM_STR,
            ParamType::BINARY => PDO::PARAM_LOB,
        };
    }

    /**
     * The value to pass to bindValue().
     *
     * PDO has no float type: a float is sent as the shortest string that
     * reads back as the same number, instead of PHP's 14-digit conversion.
     * A binary stream is passed as is; PDO reads it for PARAM_LOB.
     *
     * @param mixed $value Value to bind.
     * @return mixed
     * @throws \InvalidArgumentException When the value cannot be bound.
     */
    protected function get_bind_value( mixed $value ) : mixed {
        return ParamType::FLOAT === ParamType::of( $value ) ? ParamType::float_to_string( $value ) : $value;
    }

    /**
     * Convert a fetched row's stream values to strings.
     *
     * Drivers such as pdo_pgsql return binary columns (BYTEA) as streams;
     * every adapter returns them as strings.
     *
     * @param array $row Fetched row.
     * @return array
     */
    protected function read_row( array $row ) : array {
        return array_map( [ $this, 'read_value' ], $row );
    }

    /**
     * Convert a fetched stream value to a string; other values are returned as they are.
     *
     * @param mixed $value Fetched value.
     * @return mixed
     */
    protected function read_value( mixed $value ) : mixed {
        return ParamType::is_stream( $value ) ? ParamType::read_stream( $value ) : $value;
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
        return $row ? $this->read_row( $row ) : null;
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
        
        return array_map( [ $this, 'read_row' ], $stmt->fetchAll( PDO::FETCH_ASSOC ) );
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
        return $value !== false ? $this->read_value( $value ) : null;
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
        
        return array_map( [ $this, 'read_value' ], $stmt->fetchAll( PDO::FETCH_COLUMN, 0 ) );
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
        ) . $this->insert_returning( $table );

        $params = array_values( $data );
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return false;
        }

        // Store insert ID for INSERT queries
        $this->insert_id = $this->inserted_id( $stmt );

        // 0: inserted, but the table generates no ID.
        return $this->get_insert_id() ?? 0;
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
        $this->last_error = null;

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
        $stmt = $this->query( $query, $params );

        if ( ! $stmt ) {
            return 0;
        }

        try {
            $this->capture_insert_id( $query );
            return $stmt->rowCount();
        } catch ( PDOException $e ) {
            $this->last_error = $e->getMessage();
            return 0;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Reads with the driver's forward cursor. On MySQL the statement is
     * unbuffered, so rows come from the server as they are fetched; until the
     * stream is closed, this connection cannot run other queries. SQLite reads
     * row by row as it is. For PostgreSQL use PostgresAdapter, which reads
     * through a server-side cursor: here libpq would receive the whole result.
     *
     * A statement that returns no rows still runs; stream() then returns false.
     */
    public function stream( string $query, array $params = [] ) : DBResultStream|false {
        // pdo_mysql takes the buffering mode from the connection when the
        // statement is prepared (a prepare() option is ignored), so switch it
        // off for this statement only.
        $unbuffer = $this->ensure_connection() && 'mysql' === $this->pdo->getAttribute( PDO::ATTR_DRIVER_NAME );
        $buffered = $unbuffer ? $this->pdo->getAttribute( PDO::MYSQL_ATTR_USE_BUFFERED_QUERY ) : null;

        if ( $unbuffer ) {
            $this->pdo->setAttribute( PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, false );
        }

        try {
            $stmt = $this->query( $query, $params );
        } finally {
            if ( $unbuffer && $this->is_connected() ) {
                $this->pdo->setAttribute( PDO::MYSQL_ATTR_USE_BUFFERED_QUERY, $buffered );
            }
        }

        if ( false === $stmt ) {
            return false;
        }

        if ( 0 === $stmt->columnCount() ) {
            $stmt->closeCursor();
            $this->last_error = 'stream() expects a query that returns rows.';
            return false;
        }

        $fetcher = function () use ( $stmt ) : ?array {
            $row = $stmt->fetch( PDO::FETCH_ASSOC );

            if ( false !== $row ) {
                return $this->read_row( $row );
            }

            // With a non-exception error mode, false is also how a failed read looks.
            $code = (string) $stmt->errorCode();

            if ( '' !== $code && '00000' !== $code ) {
                throw new DBResultStreamException( 'Fetching a row failed: ' . ( $stmt->errorInfo()[2] ?? $code ) );
            }

            return null;
        };

        // The closer holds the adapter: it lives until the stream is closed, so the
        // connection does too, even when nothing else references the adapter
        // (its destructor would close the connection under the open result).
        $adapter = $this;

        $closer = static function () use ( $stmt, $adapter ) : void {
            try {
                $stmt->closeCursor();
            } catch ( \Throwable $e ) {
                // The connection may already be gone; nothing left to release.
            }
        };

        return new DBResultStream( $fetcher, $closer );
    }

    /**
     * Check connection state.
     */
    public function is_connected(): bool {
        return isset( $this->pdo ) && ( $this->pdo instanceof PDO );
    }

    /**
     * Get or instantiate the SQL query parser.
     *
     * @return SQLStatementSplitter
     */
    protected function get_parser(): SQLStatementSplitter {
        if ( $this->splitter === null ) {
            $this->splitter = new SQLStatementSplitter( $this->config->driver ?? SQLStatementSplitter::DIALECT_GENERIC );
        }
        return $this->splitter;
    }
}