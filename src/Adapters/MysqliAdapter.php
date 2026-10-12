<?php
/**
 * MySQLi Database Adapter
 *
 * Implements the DatabaseAdapterInterface for environments using the mysqli extension.
 *
 * @package Callismart\DBPrism\Adapters
 */

namespace Callismart\DBPrism\Adapters;

use mysqli;
use mysqli_stmt;
use Callismart\DBPrism\DBConfigDTO;
use Callismart\DBPrism\DBResultStream;
use Callismart\DBPrism\DBResultStreamException;
use Callismart\DBPrism\Adapters\Contracts\StreamingAdapterInterface;
use mysqli_sql_exception;
use Throwable;

/**
 * Adapter for MySQLi database access.
 */
class MysqliAdapter implements StreamingAdapterInterface {

    /**
     * The MySQLi connection instance.
     *
     * @var mysqli|null
     */
    protected ?mysqli $mysqli = null;

    /**
     * Configuration settings for the connection.
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
        try {
            if ( $this->is_connected() ) {
                return true;
            }

            // Force strict exception reporting across all PHP 8.x versions
            mysqli_report( MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT );

            $mysqli = new mysqli(
                $this->config->host,
                $this->config->username,
                $this->config->password,
                $this->config->dbname,
                $this->config->port,
                $this->config->socket
            );

            if ( $mysqli->connect_error ) {
                $this->last_error = 'Connect Error (' . $mysqli->connect_errno . ') ' . $mysqli->connect_error;
                return false;
            }

            $this->mysqli = $mysqli;
            $this->mysqli->set_charset( $this->config->charset ?? 'utf8mb4' );
            return true;
        } catch ( Throwable $th ) {
            $this->last_error = $th->getMessage();
            $this->mysqli     = null;

            return false;
        }
    }

    /**
     * Ensure database connection.
     * 
     * @return bool
     */
    protected function ensure_connection() : bool {
        if ( $this->is_connected() ) {
            return true;
        }

        $this->connect();

        if ( ! $this->is_connected() ) {
            if ( empty( $this->last_error ) ) {
                $this->last_error = 'No active MySQLi connection.';
            }
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
        if ( $this->mysqli ) {
            try {
                $this->mysqli->close();
            } catch ( Throwable $e ) {
                // Connection clean up
            }
        }

        $this->mysqli = null;
    }

    /**
     * Begin a database transaction.
     *
     * @return void
     */
    public function begin_transaction() : void {
        if ( $this->ensure_connection() ) {
            try {
                $this->mysqli->begin_transaction();
            } catch ( mysqli_sql_exception $e ) {
                $this->last_error = $e->getMessage();
            }
        }
    }

    /**
     * Commit the current transaction.
     *
     * @return void
     */
    public function commit() : void {
        if ( $this->mysqli ) {
            try {
                $this->mysqli->commit();
            } catch ( mysqli_sql_exception $e ) {
                $this->last_error = $e->getMessage();
            }
        }
    }

    /**
     * Roll back the current transaction.
     *
     * @return void
     */
    public function rollback() : void {
        if ( $this->mysqli ) {
            try {
                $this->mysqli->rollback();
            } catch ( mysqli_sql_exception $e ) {
                $this->last_error = $e->getMessage();
            }
        }
    }

    /**
     * Execute a parameterized query with ? placeholders.
     *
     * @param string $query  SQL query with ? placeholders.
     * @param array  $params Values to bind in the query.
     * @return mysqli_stmt|false Prepared statement on success, false on failure.
     */
    protected function query( string $query, array $params = [] ) : mysqli_stmt|false {
        $this->last_error = null;

        try {
            if ( ! $this->ensure_connection() ) {
                return false;
            }

            $stmt = $this->mysqli->prepare( $query );

            if ( ! $stmt ) {
                $this->last_error = 'Prepare failed: ' . $this->mysqli->error;
                return false;
            }

            if ( ! empty( $params ) ) {
                $types = $this->get_bind_types( $params );

                if ( ! $stmt->bind_param( $types, ...$params ) ) {
                    $this->last_error = 'Bind param failed: ' . $stmt->error;
                    $stmt->close();
                    return false;
                }
            }

            if ( ! $stmt->execute() ) {
                $this->last_error = 'Execute failed: ' . $stmt->error;
                $stmt->close();
                return false;
            }

            // Store insert ID for INSERT queries. An INSERT that generates none
            // (no AUTO_INCREMENT column) clears it instead of keeping the last one.
            if ( $this->mysqli->insert_id > 0 ) {
                $this->insert_id = (int) $this->mysqli->insert_id;
            } elseif ( $this->is_insert( $query ) ) {
                $this->insert_id = null;
            }

            return $stmt;
        } catch ( mysqli_sql_exception $e ) {
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
     * Generate bind_param type string from parameter values.
     * 
     * @param array $params Array of parameters.
     * @return string String of types (i = integer, d = double, s = string, b = blob).
     */
    protected function get_bind_types( array $params ) : string {
        $types = '';

        foreach ( $params as $param ) {
            if ( is_int( $param ) ) {
                $types .= 'i';
            } elseif ( is_float( $param ) ) {
                $types .= 'd';
            } else {
                $types .= 's';
            }
        }

        return $types;
    }

    /**
     * Retrieve a single row as an associative array.
     *
     * @param string $query  SQL query with ? placeholders.
     * @param array  $params Values to bind in the query.
     * @return array|null Associative array of the row, or null if not found.
     */
    public function get_row( $query, $params = [] ) : ?array {
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return null;
        }

        try {
            $result = $stmt->get_result();

            if ( ! $result ) {
                $stmt->close();
                return null;
            }

            $row = $result->fetch_assoc();
            $stmt->close();

            return $row ?: null;
        } catch ( mysqli_sql_exception $e ) {
            $this->last_error = $e->getMessage();
            $stmt->close();
            return null;
        }
    }

    /**
     * Retrieve multiple rows from the database as an associative array.
     *
     * @param string $query  SQL query with ? placeholders.
     * @param array  $params Values to bind to query placeholders.
     *
     * @return array Array of results (each row as an associative array).
     */
    public function get_results( $query, $params = [] ) : array {
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return [];
        }

        try {
            $result = $stmt->get_result();

            if ( ! $result ) {
                $stmt->close();
                return [];
            }

            $rows = [];
            while ( $row = $result->fetch_assoc() ) {
                $rows[] = $row;
            }

            $stmt->close();
            return $rows;
        } catch ( mysqli_sql_exception $e ) {
            $this->last_error = $e->getMessage();
            $stmt->close();
            return [];
        }
    }

    /**
     * Fetch a single value from the database.
     *
     * @param string $query  SQL query with ? placeholders.
     * @param array  $params Values to bind.
     * @return mixed The first column of the first row or null if not found.
     */
    public function get_var( $query, $params = [] ) : mixed {
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return null;
        }

        try {
            $result = $stmt->get_result();

            if ( ! $result ) {
                $stmt->close();
                return null;
            }

            $row = $result->fetch_array( MYSQLI_NUM );
            $stmt->close();

            return $row ? $row[0] : null;
        } catch ( mysqli_sql_exception $e ) {
            $this->last_error = $e->getMessage();
            $stmt->close();
            return null;
        }
    }

    /**
     * Fetch a single column from the database.
     *
     * @param string $query  SQL query with ? placeholders.
     * @param array  $params Values to bind.
     * @return array Array of column values.
     */
    public function get_col( $query, $params = [] ) : array {
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return [];
        }

        try {
            $result = $stmt->get_result();

            if ( ! $result ) {
                $stmt->close();
                return [];
            }

            $column = [];
            while ( $row = $result->fetch_array( MYSQLI_NUM ) ) {
                $column[] = $row[0];
            }

            $stmt->close();
            return $column;
        } catch ( mysqli_sql_exception $e ) {
            $this->last_error = $e->getMessage();
            $stmt->close();
            return [];
        }
    }

    /**
     * Insert a record into the database.
     * 
     * @param string $table The table name.
     * @param array  $data  An associative array of column_name => value.
     * @return int|false The inserted record ID on success, false on failure.
     */
    public function insert( $table, $data ) : int|false {
        if ( ! $this->ensure_connection() ) {
            return false;
        }

        if ( empty( $data ) ) {
            $this->last_error = 'Insert data cannot be empty.';
            return false;
        }

        $columns      = array_keys( $data );
        $placeholders = array_fill( 0, count( $data ), '?' );

        $query = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            implode( ', ', $columns ),
            implode( ', ', $placeholders )
        );

        $params = array_values( $data );
        $stmt   = $this->query( $query, $params );

        if ( false === $stmt ) {
            return false;
        }

        $stmt->close();

        // 0: inserted, but the table generates no ID.
        return $this->get_insert_id() ?? 0;
    }

    /**
     * Update records in the database.
     * 
     * @param string $table The table name.
     * @param array  $data  An associative array of column_name => value.
     * @param array  $where An associative array of column_name => value for WHERE clause.
     * @return int|false Number of affected rows on success, false on failure.
     */
    public function update( $table, $data, $where ) : int|false {
        if ( ! $this->ensure_connection() ) {
            return false;
        }

        if ( empty( $data ) || empty( $where ) ) {
            $this->last_error = 'Update data and WHERE condition cannot be empty.';
            return false;
        }

        $set_clauses = array_map( function( $column ) {
            return "$column = ?";
        }, array_keys( $data ) );

        $where_clauses = array_map( function( $column ) {
            return "$column = ?";
        }, array_keys( $where ) );

        $query = sprintf(
            'UPDATE %s SET %s WHERE %s',
            $table,
            implode( ', ', $set_clauses ),
            implode( ' AND ', $where_clauses )
        );

        $params = array_merge( array_values( $data ), array_values( $where ) );
        $stmt   = $this->query( $query, $params );

        if ( false === $stmt ) {
            return false;
        }

        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        return $affected_rows;
    }

    /**
     * Delete records from the database.
     * 
     * @param string $table The table name.
     * @param array  $where An associative array of column_name => value for WHERE clause.
     * @return int|false Number of affected rows on success, false on failure.
     */
    public function delete( $table, $where ) : int|false {
        if ( ! $this->ensure_connection() ) {
            return false;
        }

        if ( empty( $where ) ) {
            $this->last_error = 'Delete WHERE condition cannot be empty.';
            return false;
        }

        $where_clauses = array_map( function( $column ) {
            return "$column = ?";
        }, array_keys( $where ) );

        $query = sprintf(
            'DELETE FROM %s WHERE %s',
            $table,
            implode( ' AND ', $where_clauses )
        );

        $params = array_values( $where );
        $stmt   = $this->query( $query, $params );

        if ( false === $stmt ) {
            return false;
        }

        $affected_rows = $stmt->affected_rows;
        $stmt->close();

        return $affected_rows;
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
        return 'mysql';
    }

    /**
     * {@inheritdoc}
     */
    public function get_config() : DBConfigDTO {
        return $this->config;
    }

    /**
     * {@inheritDoc}
     */
    public function exec( string $query ) : bool {
        if ( ! $this->ensure_connection() ) {
            return false;
        }

        $this->last_error = null;

        try {
            $result = $this->mysqli->multi_query( $query );

            if ( false === $result ) {
                $this->last_error = $this->mysqli->error;
                return false;
            }

            do {
                if ( $res = $this->mysqli->store_result() ) {
                    $res->free();
                }
            } while ( $this->mysqli->more_results() && $this->mysqli->next_result() );

            return true;
        } catch ( mysqli_sql_exception $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function execute( string $query, array $params = [] ) : int {
        if ( ! $this->ensure_connection() ) {
            return 0;
        }

        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return 0;
        }

        $affected = $stmt->affected_rows;
        $stmt->close();

        return $affected;
    }

    /**
     * {@inheritdoc}
     *
     * Reads unbuffered: the prepared statement's rows are bound with
     * bind_result() and never stored, so each fetch() pulls the next row
     * from the server. Until the stream is closed, this connection cannot
     * run other queries (MySQL "Commands out of sync").
     *
     * A statement that returns no rows still runs; stream() then returns false.
     */
    public function stream( string $query, array $params = [] ) : DBResultStream|false {
        $stmt = $this->query( $query, $params );

        if ( false === $stmt ) {
            return false;
        }

        try {
            $meta = $stmt->result_metadata();
        } catch ( mysqli_sql_exception $e ) {
            $meta = false;
        }

        if ( false === $meta ) {
            $this->last_error = 'stream() expects a query that returns rows.';
            $stmt->close();
            return false;
        }

        $fields = array_map( static fn( $field ) => $field->name, $meta->fetch_fields() );
        $meta->free();

        // bind_result() writes each fetched row into these slots.
        $values = array_fill( 0, count( $fields ), null );
        $stmt->bind_result( ...$values );

        $fetcher = static function () use ( $stmt, $fields, &$values ) : ?array {
            $fetched = $stmt->fetch();

            if ( null === $fetched ) {
                return null;
            }

            if ( false === $fetched ) {
                throw new DBResultStreamException( 'Fetching a row failed: ' . $stmt->error, $stmt->errno );
            }

            // Copy the values out: the slots are references that the next fetch() overwrites.
            $row = [];

            foreach ( $fields as $i => $name ) {
                $row[ $name ] = $values[ $i ];
            }

            return $row;
        };

        $closer = static function () use ( $stmt ) : void {
            try {
                $stmt->free_result();
                $stmt->close();
            } catch ( Throwable $e ) {
                // The connection may already be gone; nothing left to release.
            }
        };

        return new DBResultStream( $fetcher, $closer );
    }

    /**
     * Check whether the database connection is alive.
     *
     * @return bool
     */
    public function is_connected(): bool {
        return isset( $this->mysqli ) && ( $this->mysqli instanceof mysqli );
    }

}