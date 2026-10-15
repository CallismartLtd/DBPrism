<?php
/**
 * SQLite3 Database Adapter (Native)
 *
 * Implements the DatabaseAdapterInterface using the native SQLite3 PHP extension.
 *
 * @package Callismart\DBPrism\Adapters
 */

namespace Callismart\DBPrism\Adapters;

use SQLite3;
use Exception;
use Callismart\DBPrism\DBConfigDTO;
use Callismart\DBPrism\DBResultStream;
use Callismart\DBPrism\DBResultStreamException;
use Callismart\DBPrism\Utils\ParamType;
use Callismart\DBPrism\Utils\Identifier;
use SQLite3Result;
use Callismart\DBPrism\Adapters\Contracts\StreamingAdapterInterface;

/**
 * Adapter for native SQLite3 database access.
 */
class SqliteAdapter implements StreamingAdapterInterface {
    /**
     * The SQLite3 connection instance.
     *
     * @var SQLite3|null
     */
    protected $sqlite;

    /**
     * Configuration settings.
     *
     * @var DBConfigDTO
     */
    protected DBConfigDTO $config;

    /**
     * Last inserted ID.
     *
     * @var int|null
     */
    protected $insert_id = null;

    /**
     * Last executed error message.
     *
     * @var string|null
     */
    protected $last_error = null;

    /**
     * Constructor.
     *
     * @param DBConfigDTO $config Database configuration: 'dbname' (and optional 'path') locate the file.
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
     * Establish a database connection using the SQLite3 class.
     *
     * @return bool True on success, false on failure.
     */
    public function connect() : bool {
        if ( $this->is_connected() ) {
            return true;
        }

        try {
            $this->last_error = null;

            $filename   = $this->build_sqlite_path();
            $encryption = ! empty( $this->config->encryption_key ) ? $this->config->encryption_key : '';

            // Always read-write, creating the file when it does not exist. The
            // configuration's "flags" are connection options for PDO, not SQLite's open mode.
            $this->sqlite = new SQLite3( $filename, SQLITE3_OPEN_READWRITE | SQLITE3_OPEN_CREATE, $encryption );

            // Failures throw instead of raising PHP warnings; every call below
            // catches them and reports through get_last_error().
            $this->sqlite->enableExceptions( true );
            $this->sqlite->busyTimeout( 5000 );

            // Tuning only: a setting the database refuses (for example WAL on a
            // read-only file) does not stop the connection.
            foreach ( [ 'PRAGMA temp_store = MEMORY;', 'PRAGMA mmap_size = 268435456;', 'PRAGMA journal_mode = WAL;', 'PRAGMA synchronous = NORMAL;' ] as $pragma ) {
                try {
                    $this->sqlite->exec( $pragma );
                } catch ( Exception $e ) {
                    // Keep the engine default.
                }
            }

            return true;
        } catch ( Exception $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * Close the active SQLite3 database connection.
     *
     * @return void
     */
    public function close() : void {
        if ( $this->sqlite ) {
            try {
                $this->sqlite->close();
            } catch ( Exception $e ) {
                $this->last_error = $e->getMessage();
            }
        }

        $this->sqlite = null;
    }

    /**
     * Begin a database transaction.
     *
     * @return void
     */
    public function begin_transaction() : void {
        if ( $this->ensure_connection() ) {
            // 'BEGIN IMMEDIATE' locks the database file 
            // against concurrent writers right now until busy timesout
            // period elapses.
            $this->transaction_command( 'BEGIN IMMEDIATE TRANSACTION;' );
        }
    }

    /**
     * Commit the current transaction.
     *
     * @return void
     */
    public function commit() : void {
        $this->transaction_command( 'COMMIT' );
    }

    /**
     * Roll back the current transaction.
     *
     * @return void
     */
    public function rollback() : void {
        $this->transaction_command( 'ROLLBACK' );
    }

    /**
     * Run a transaction control statement.
     *
     * A failure is recorded rather than thrown, so a failed ROLLBACK inside
     * Database::transactional() cannot hide the exception that caused it.
     *
     * @param string $command BEGIN, COMMIT or ROLLBACK statement.
     * @return void
     */
    protected function transaction_command( string $command ) : void {
        if ( ! $this->sqlite ) {
            $this->last_error = 'No active SQLite3 connection.';
            return;
        }

        try {
            $this->sqlite->exec( $command );
        } catch ( Exception $e ) {
            $this->last_error = $e->getMessage();
        }
    }

    /**
     * Execute a raw SQL query with optional parameters.
     *
     * @param string $query  The SQL query with ? placeholders.
     * @param array  $params Optional. The bound values for placeholders.
     *
     * @return SQLite3Result|false The result object or true on success, false on failure.
     */
    protected function query( $query, array $params = [] ) : SQLite3Result|false {
        $this->last_error = null;

        // A value that cannot be bound throws here, before anything is sent
        // (the catch below records SQLite errors only).
        $bindings = [];

        foreach ( array_values( $params ) as $param ) {
            $bindings[] = [ $this->get_bind_value( $param ), $this->get_bind_type( $param ) ];
        }

        if ( ! $this->ensure_connection() ) {
            return false;
        }

        try {

            $stmt   = $this->sqlite->prepare( $query );

            if ( ! $stmt ) {
                $this->last_error = $this->sqlite->lastErrorMsg();
                return false;
            }

            foreach ( $bindings as $i => [ $value, $type ] ) {
                $stmt->bindValue( $i + 1, $value, $type );
            }

            $result = $stmt->execute();
            
            if ( ! $result ) {
                $this->last_error = $this->sqlite->lastErrorMsg();
                $stmt->close();
                return false;
            }

            // Store insert ID if this was an INSERT operation.
            if ( preg_match( '/^\s*INSERT\s+/i', $query ) ) {
                $this->insert_id = $this->sqlite->lastInsertRowID();
            }

            return $result;
        } catch ( \Exception $e ) {
            // SQLite3 throws only \Exception here; its own message, without
            // the "Unable to prepare/execute statement:" prefix.
            $this->last_error   = $this->sqlite->lastErrorMsg();
            return false;
        }
    }

    /**
     * The SQLITE3_* type for a value.
     *
     * @param mixed $value Value to bind.
     * @return int
     * @throws \InvalidArgumentException When the value cannot be bound.
     */
    protected function get_bind_type( mixed $value ) : int {
        return match ( ParamType::of( $value ) ) {
            ParamType::NULL   => SQLITE3_NULL,
            ParamType::BOOL,
            ParamType::INT    => SQLITE3_INTEGER,
            ParamType::FLOAT  => SQLITE3_FLOAT,
            ParamType::STRING => SQLITE3_TEXT,
            ParamType::BINARY => SQLITE3_BLOB,
        };
    }

    /**
     * The value to pass to bindValue().
     *
     * SQLite3 binds every type as it is, including a stream as a blob.
     *
     * @param mixed $value Value to bind.
     * @return mixed
     * @throws \InvalidArgumentException When the value cannot be bound.
     */
    protected function get_bind_value( mixed $value ) : mixed {
        ParamType::of( $value );

        return $value;
    }

    /**
     * Retrieve a single row as an associative array.
     *
     * @param string $query  The SQL query with ? placeholders.
     * @param array  $params Optional. Bound values.
     * @return array|null Associative array of the row, or null if not found.
     */
    public function get_row( $query, array $params = [] ) : ?array {
        $this->last_error = null;

        $result = $this->query( $query, $params );
        if ( ! $result ) return null;
        
        $row = $result->fetchArray( SQLITE3_ASSOC );

        $result->finalize();

        return $row ?: null;
    }

    /**
     * Retrieve multiple rows as an array of associative arrays.
     *
     * @param string $query  The SQL query with ? placeholders.
     * @param array  $params Optional. Bound values.
     * @return array List of associative arrays.
     */
    public function get_results( $query, array $params = [] ) : array {

        $this->last_error = null;

        $result = $this->query( $query, $params );
        if ( ! $result ) return [];

        $rows = [];
        while ( $row = $result->fetchArray( SQLITE3_ASSOC ) ) {
            $rows[] = $row;
        }

        $result->finalize();

        return $rows;
    }

    /**
     * Retrieve a single scalar value.
     *
     * @param string $query  The SQL query with ? placeholders.
     * @param array  $params Optional. Bound values.
     * @return mixed|null The first column of the first row, or null if none.
     */
    public function get_var( $query, array $params = [] ) : mixed {
        $this->last_error = null;

        $result = $this->query( $query, $params );
        if ( ! $result ) return null;

        $row = $result->fetchArray( SQLITE3_NUM );

        $result->finalize();

        return $row ? $row[0] : null;
    }

    /**
     * Retrieve a single column of values.
     *
     * @param string $query  The SQL query with ? placeholders.
     * @param array  $params Optional. Bound values.
     * @return array List of column values.
     */
    public function get_col( $query, array $params = [] ) : array {
        $this->last_error = null;

        $result = $this->query( $query, $params );

        if ( ! $result ) return [];

        $cols = [];
        while ( $row = $result->fetchArray( SQLITE3_NUM ) ) {
            $cols[] = $row[0];
        }

        $result->finalize();
        return $cols;
    }

    /**
     * The identifier quote character insert(), update() and delete() quote
     * table and column names with.
     *
     * Double quote, the SQL standard.
     *
     * @return string
     */
    protected function identifier_quote() : string {
        return '"';
    }

    /**
     * Insert a record into the database.
     *
     * @param string $table Table name.
     * @param array  $data  Associative array of column => value.
     * @return int|false The inserted record ID on success, false on failure.
     * @throws \InvalidArgumentException When a table or column name is not a plain name.
     */
    public function insert( $table, array $data ) : int|false {
        $this->last_error = null;

        $quote        = $this->identifier_quote();
        $columns      = Identifier::columns( $data, $quote );
        $placeholders = array_fill( 0, count( $data ), '?' );

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            Identifier::table( $table, $quote ),
            implode( ', ', $columns ),
            implode( ', ', $placeholders )
        );

        $success = $this->query( $query, array_values( $data ) );
        return $success ? $this->get_insert_id() : false;
    }

    /**
     * Update existing records.
     *
     * @param string $table Table name.
     * @param array  $data  Associative array of column => value.
     * @param array  $where Associative array for WHERE conditions.
     * @return int|false Number of affected rows, or false on failure.
     * @throws \InvalidArgumentException When a table or column name is not a plain name.
     */
    public function update( $table, array $data, array $where ) : int|false {

        $this->last_error = null;

        $quote = $this->identifier_quote();

        $set_clauses    = array_map( fn( $col ) => "$col = ?", Identifier::columns( $data, $quote ) );
        $where_clauses  = array_map( fn( $col ) => "$col = ?", Identifier::columns( $where, $quote ) );

        $query = sprintf(
            'UPDATE %s SET %s WHERE %s',
            Identifier::table( $table, $quote ),
            implode( ', ', $set_clauses ),
            implode( ' AND ', $where_clauses )
        );

        $params = array_merge( array_values( $data ), array_values( $where ) );
        $success = $this->query( $query, $params );
        
        return $success ? $this->sqlite->changes() : false;
    }

    /**
     * Delete records from the database.
     *
     * @param string $table Table name.
     * @param array  $where Associative array for WHERE conditions.
     * @return int|false Number of affected rows, or false on failure.
     * @throws \InvalidArgumentException When a table or column name is not a plain name.
     */
    public function delete( $table, array $where ) : int|false {

        $this->last_error = null;

        $quote = $this->identifier_quote();

        $where_clauses = array_map( fn( $col ) => "$col = ?", Identifier::columns( $where, $quote ) );

        $query = sprintf(
            'DELETE FROM %s WHERE %s',
            Identifier::table( $table, $quote ),
            implode( ' AND ', $where_clauses )
        );

        $success = $this->query( $query, array_values( $where ) );
        return $success ? $this->sqlite->changes() : false;
    }

    /**
     * Retrieve the last inserted ID.
     *
     * @return int|null The last inserted row ID.
     */
    public function get_insert_id() : ?int {
        return $this->insert_id;
    }

    /**
     * Retrieve the last database error message.
     *
     * @return string|null The error message, or null if none.
     */
    public function get_last_error() : ?string {
        return $this->last_error;
    }

    /**
     * {@inheritdoc}
     */
    public function get_driver() : string {
        return 'sqlite';
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
    public function exec( string $query ) : bool {
        if ( ! $this->ensure_connection() ) {
            return false;
        }

        $this->last_error = null;

        try {

            $result = $this->sqlite->exec( $query );

            if ( false === $result ) {
                $this->last_error = $this->sqlite->lastErrorMsg();
                return false;
            }

            if ( stripos( ltrim( $query ), 'INSERT' ) === 0 ) {
                $this->insert_id = $this->sqlite->lastInsertRowID();
            }

            return true;

        } catch ( \Exception $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritdoc}
     */
    public function execute( string $query, array $params = [] ) : int {
        if ( ! $this->ensure_connection() ) {
            return 0;
        }

        $result = $this->query( $query, $params );
        
        if ( ! $result ) {
            return 0;
        }

        $affected_rows = $this->sqlite->changes();
        $result->finalize();

        return $affected_rows;
    }

    /**
     * {@inheritdoc}
     *
     * SQLite steps through the result one row per fetch, so nothing more
     * than the current row is held in memory.
     *
     * A statement that returns no rows still runs; stream() then returns false.
     */
    public function stream( string $query, array $params = [] ) : DBResultStream|false {
        $result = $this->query( $query, $params );

        if ( false === $result ) {
            return false;
        }

        if ( 0 === $result->numColumns() ) {
            $result->finalize();
            $this->last_error = 'stream() expects a query that returns rows.';
            return false;
        }

        $sqlite = $this->sqlite;

        $fetcher = static function () use ( $result, $sqlite ) : ?array {
            try {
                $row = $result->fetchArray( SQLITE3_ASSOC );
            } catch ( Exception $e ) {
                throw new DBResultStreamException( 'Fetching a row failed: ' . $sqlite->lastErrorMsg(), $sqlite->lastErrorCode(), $e );
            }

            if ( false !== $row ) {
                return $row;
            }

            // false is also how a failed step looks; 100/101 are SQLITE_ROW/SQLITE_DONE.
            $code = $sqlite->lastErrorCode();

            if ( 0 !== $code && 100 !== $code && 101 !== $code ) {
                throw new DBResultStreamException( 'Fetching a row failed: ' . $sqlite->lastErrorMsg(), $code );
            }

            return null;
        };

        // The closer holds the adapter: it lives until the stream is closed, so the
        // connection does too, even when nothing else references the adapter
        // (its destructor would close the connection under the open result).
        $adapter = $this;

        $closer = static function () use ( $result, $adapter ) : void {
            try {
                $result->finalize();
            } catch ( \Throwable $e ) {
                // Already finalized with its statement; nothing left to release.
            }
        };

        return new DBResultStream( $fetcher, $closer );
    }

    /**
     * Check connection state.
     */
    public function is_connected(): bool {
        return isset( $this->sqlite ) && ( $this->sqlite instanceof SQLite3 );
    }

    protected function ensure_connection() : bool {
        if ( $this->is_connected() ) {
            return true;
        }

        $this->connect();

        if ( ! $this->is_connected() ) {
            // Keep the reason connect() recorded.
            if ( empty( $this->last_error ) ) {
                $this->last_error = 'No active SQLite3 connection.';
            }
            return false;
        }

        return true;
    }

    /**
     * Build the filesystem path or memory string for the SQLite3 instance.
     *
     * @return string Valid SQLite3 database file path or :memory:.
     * @throws Exception If database name is missing.
     */
    protected function build_sqlite_path() : string {
        $dbname = $this->config->dbname ?? '';

        if ( empty( $dbname ) ) {
            throw new \Exception( 'SQLite database name or path was not specified.' );
        }

        // Handle in-memory database target
        if ( ':memory:' === $dbname ) {
            return ':memory:';
        }

        // If path is not set, treat dbname as an explicit absolute or relative path
        if ( empty( $this->config->path ) ) {
            return $dbname;
        }

        $path     = \rtrim( $this->config->path, '/\\' );
        $filename = \str_contains( $dbname, '.' ) ? $dbname : "{$dbname}.db";

        return "{$path}" . DIRECTORY_SEPARATOR . "{$filename}";
    }
}