<?php
namespace Callismart\DBPrism\Adapters;

use PDOStatement;

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