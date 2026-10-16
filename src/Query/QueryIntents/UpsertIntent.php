<?php
/**
 * Upsert Query Intent class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\QueryIntents
 * @since 2.5.0
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\QueryIntents;

use InvalidArgumentException;

/**
 * Represents an intent to insert rows that update the existing row on a key conflict (UPSERT).
 *
 * An UPSERT is an INSERT: it takes its rows the same way (values() or
 * multi_values()) and binds the same values. A row whose conflict key
 * matches an existing row's updates that row instead of failing:
 *
 *   $builder->upsert( 'licenses' )
 *       ->values( [ 'license_key' => $key, 'status' => 'active', 'seats' => 5 ] )
 *       ->on_conflict( 'license_key' )   // the unique key, required
 *       ->update_columns( 'status' );    // optional
 *
 * Each updated column takes the value the conflicting row tried to insert.
 * The conflict key alone chooses the row to update, so an UPSERT takes no
 * WHERE conditions and no set_case().
 *
 * @since 2.5.0
 */
final class UpsertIntent extends PersistenceIntent {

    /**
     * The unique key the rows conflict on.
     *
     * @var string[]
     */
    private array $conflict_columns = [];

    /**
     * The columns updated on conflict; null for the default.
     *
     * @var string[]|null
     */
    private ?array $update_columns = null;

    /**
     * Name the unique key the rows conflict on.
     *
     * A row whose values for these columns match an existing row's updates
     * that row (see update_columns()) instead of being inserted. The columns
     * must form a primary key or unique constraint, and every row must
     * insert a value for each of them.
     *
     * MySQL and MariaDB take no conflict target: they update on a conflict
     * with any unique key of the table. Name the key anyway, so the query
     * means the same on every engine; on a table with one unique key
     * besides the primary key, it does.
     *
     * @param string ...$columns The key's columns.
     * @return static
     * @throws InvalidArgumentException When no column is given.
     */
    public function on_conflict( string ...$columns ) : static {
        if ( empty( $columns ) ) {
            throw new InvalidArgumentException( 'on_conflict() needs the columns of the unique key the rows conflict on.' );
        }

        $this->conflict_columns = array_values( array_unique( array_map( 'trim', $columns ) ) );

        return $this;
    }

    /**
     * Choose the columns updated on conflict.
     *
     * Without this call, every inserted column outside the conflict key is
     * updated; when every inserted column is in the key, a conflict leaves
     * the existing row as it is.
     *
     * @param string ...$columns Inserted columns to update.
     * @return static
     * @throws InvalidArgumentException When no column is given.
     */
    public function update_columns( string ...$columns ) : static {
        if ( empty( $columns ) ) {
            throw new InvalidArgumentException( 'update_columns() needs at least one column. To update every inserted column outside the conflict key, do not call it.' );
        }

        $this->update_columns = array_values( array_unique( array_map( 'trim', $columns ) ) );

        return $this;
    }

    /**
     * Refused: an UPSERT updates a column to the value the row tried to insert.
     *
     * @param string   $column   Unused.
     * @param callable $callback Unused.
     * @return static
     * @throws InvalidArgumentException Always.
     */
    public function set_case( string $column, callable $callback ) : static {
        throw new InvalidArgumentException( 'set_case() applies to update() queries only. An upsert updates each column to the value the row tried to insert.' );
    }

    /**
     * The unique key the rows conflict on.
     *
     * @return string[]
     */
    public function get_conflict_columns() : array {
        return $this->conflict_columns;
    }

    /**
     * The columns updated on conflict, as given to update_columns().
     *
     * @return string[]|null Null when update_columns() was not called.
     */
    public function get_update_columns() : ?array {
        return $this->update_columns;
    }
}