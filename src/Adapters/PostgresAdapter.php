<?php
namespace Callismart\DBPrism\Adapters;

use PDO;
use PDOException;
use PDOStatement;
use Callismart\DBPrism\DBResultStream;
use Callismart\DBPrism\DBResultStreamException;

/**
 * Postgres Adapter extending the generic PDO Adapter.
 *
 * PDO's lastInsertId() runs SELECT LASTVAL() on PostgreSQL: it fails on a
 * connection that has not used a sequence yet, which also aborts an open
 * transaction, and it returns the session's last sequence value even when
 * the INSERT used none. So this adapter never calls it: insert() asks for the
 * ID with INSERT ... RETURNING, and execute() does not capture one.
 */
class PostgresAdapter extends PdoAdapter {

    /**
     * Generated primary key column per table, as looked up; null when the
     * table has none.
     *
     * @var array<string, string|null>
     */
    protected array $generated_keys = [];

    /**
     * Rows fetched from the server per round trip while streaming.
     *
     * @var int
     */
    protected int $stream_batch_size = 1000;

    /**
     * Counter for unique cursor names on this connection.
     *
     * @var int
     */
    private int $cursor_sequence = 0;

    public function get_driver() : string {
        return 'pgsql';
    }

    /**
     * {@inheritdoc}
     *
     * RETURNING the table's generated primary key, when it has one.
     */
    protected function insert_returning( string $table ) : string {
        $column = $this->generated_key( $table );

        return null === $column ? '' : ' RETURNING "' . str_replace( '"', '""', $column ) . '"';
    }

    /**
     * {@inheritdoc}
     *
     * Read from the RETURNING clause; no ID when there was none.
     */
    protected function inserted_id( PDOStatement $stmt ) : ?int {
        if ( 0 === $stmt->columnCount() ) {
            return null;
        }

        $id = $stmt->fetchColumn();

        return is_numeric( $id ) ? (int) $id : null;
    }

    /**
     * {@inheritdoc}
     *
     * execute() runs arbitrary SQL, so the ID cannot be asked for; an INSERT
     * clears it rather than leave the previous one. Use insert() for the ID.
     */
    protected function capture_insert_id( string $query ) : void {
        if ( $this->is_insert( $query ) ) {
            $this->insert_id = null;
        }
    }

    /**
     * {@inheritdoc}
     *
     * Reads through a server-side cursor (DECLARE ... CURSOR, then FETCH in
     * batches of $stream_batch_size), so PHP holds one batch at a time. Without
     * it, libpq receives the whole result before the first row is returned.
     *
     * A cursor only lives inside a transaction:
     *
     *  - with a transaction already open, the stream uses it; the cursor ends
     *    when that transaction ends, so finish reading before committing;
     *  - otherwise the stream opens one and commits it when it is closed. The
     *    rows are read from one consistent snapshot. Queries run on this
     *    adapter before the stream is closed are part of that transaction.
     *
     * Unlike MySQL, the connection can run other queries while streaming.
     */
    public function stream( string $query, array $params = [] ) : DBResultStream|false {
        $this->last_error = null;

        if ( ! $this->ensure_connection() ) {
            return false;
        }

        $own    = ! $this->pdo->inTransaction();
        $cursor = 'dbprism_stream_' . ( ++$this->cursor_sequence );
        $query  = rtrim( $query, "; \t\n\r" );

        try {
            if ( $own ) {
                $this->pdo->beginTransaction();
            }
        } catch ( PDOException $e ) {
            $this->last_error = $e->getMessage();
            return false;
        }

        // DECLARE only accepts a query that returns rows; anything else fails here, unrun.
        if ( false === $this->query( "DECLARE {$cursor} NO SCROLL CURSOR FOR {$query}", $params ) ) {
            if ( $own ) {
                $this->end_stream_transaction( false );
            }

            return false;
        }

        $batch_size = max( 1, $this->stream_batch_size );
        $batch      = [];
        $index      = 0;
        $exhausted  = false;

        $fetcher = function () use ( $cursor, $batch_size, &$batch, &$index, &$exhausted ) : ?array {
            if ( $index >= count( $batch ) ) {
                if ( $exhausted ) {
                    return null;
                }

                $batch = []; // Release the previous batch before fetching the next.
                $index = 0;
                $stmt  = $this->pdo->query( "FETCH {$batch_size} FROM {$cursor}" );

                if ( false === $stmt ) {
                    throw new DBResultStreamException( 'Fetching rows failed: ' . ( $this->pdo->errorInfo()[2] ?? 'unknown error' ) );
                }

                $batch     = $stmt->fetchAll( PDO::FETCH_ASSOC );
                $exhausted = count( $batch ) < $batch_size;

                if ( [] === $batch ) {
                    return null;
                }
            }

            return $this->read_row( $batch[ $index++ ] );
        };

        $closer = function () use ( $cursor, $own ) : void {
            // The cursor ended with its transaction; nothing to close.
            if ( null === $this->pdo || ! $this->pdo->inTransaction() ) {
                return;
            }

            $closed = true;

            try {
                $this->pdo->exec( "CLOSE {$cursor}" );
            } catch ( PDOException $e ) {
                $closed = false; // The transaction failed (e.g. a read error).
            }

            if ( $own ) {
                $this->end_stream_transaction( $closed );
            }
        };

        return new DBResultStream( $fetcher, $closer );
    }

    /**
     * End a transaction stream() opened: commit it, or roll it back when it failed.
     *
     * @param bool $commit
     * @return void
     */
    private function end_stream_transaction( bool $commit ) : void {
        try {
            $commit ? $this->pdo->commit() : $this->pdo->rollBack();
        } catch ( PDOException $e ) {
            try {
                $this->pdo->rollBack();
            } catch ( PDOException $e ) {
                // Nothing left to end.
            }
        }
    }

    /**
     * The single-column primary key of a table, when its value is generated
     * (SERIAL / BIGSERIAL default, or an IDENTITY column).
     *
     * Looked up once per table and connection.
     *
     * @param string $table Table name, as given to insert(); quoted or schema-qualified names work.
     * @return string|null
     */
    protected function generated_key( string $table ) : ?string {
        if ( array_key_exists( $table, $this->generated_keys ) ) {
            return $this->generated_keys[ $table ];
        }

        $column = $this->get_var(
            "SELECT a.attname
               FROM pg_index i
               JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
               LEFT JOIN pg_attrdef d ON d.adrelid = a.attrelid AND d.adnum = a.attnum
              WHERE i.indrelid = CAST( ? AS regclass )
                AND i.indisprimary
                AND i.indnatts = 1
                AND ( a.attidentity <> '' OR pg_get_expr( d.adbin, d.adrelid ) LIKE 'nextval(%' )",
            [ $table ]
        );

        $column = null === $column ? null : (string) $column;

        // A failed lookup (e.g. the table does not exist yet) is not cached:
        // a migration may create the table and insert on this connection.
        if ( null === $this->get_last_error() ) {
            $this->generated_keys[ $table ] = $column;
        }

        return $column;
    }
}