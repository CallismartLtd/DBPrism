<?php
/**
 * Abstract Migration Base Class
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations;

use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\MigrationException;
use Callismart\DBPrism\Inspection\Inspector;
use Callismart\DBPrism\Migrations\Contracts\MigrationInterface;
use Callismart\DBPrism\Migrations\Operators\ColumnOperator;
use Callismart\DBPrism\Migrations\Operators\ConstraintOperator;
use Callismart\DBPrism\Migrations\Operators\IndexOperator;
use Callismart\DBPrism\Migrations\Operators\TableOperator;
use Callismart\DBPrism\Query\SQLBuilder;

/**
 * Base class for migrations.
 *
 * Gives up() and down() the tools to describe a change: the operators,
 * the schema inspector and the query builder. Everything runs through the
 * Database the Migrator passes in, so the Migrator sees every statement.
 *
 *   final class AddLicenseKeyIndex extends AbstractMigration {
 *       public function up() : void {
 *           $this->index( 'licenses' )->...;
 *       }
 *   }
 *
 * @since 0.2.0
 */
abstract class AbstractMigration implements MigrationInterface {

    /**
     * Constructor.
     *
     * Final: the Migrator builds every migration the same way. A migration
     * works through $this->db and the helpers below, never through
     * constructor arguments of its own.
     *
     * @param Database $db The database to migrate.
     */
    final public function __construct( protected Database $db ) {}

    /**
     * {@inheritDoc}
     */
    abstract public function up() : void;

    /**
     * {@inheritDoc}
     */
    public static function get_description() : string {
        return '';
    }

    /*
    |------------------
    | Operators
    |------------------
    */

    /**
     * Start a table operation.
     *
     * @param string $table The table name.
     * @return TableOperator
     */
    protected function table( string $table ) : TableOperator {
        return new TableOperator( $this->db, $table );
    }

    /**
     * Start a column operation on a table.
     *
     * @param string $table The table name.
     * @return ColumnOperator
     */
    protected function column( string $table ) : ColumnOperator {
        return new ColumnOperator( $this->db, $table );
    }

    /**
     * Start an index operation on a table.
     *
     * @param string $table The table name.
     * @return IndexOperator
     */
    protected function index( string $table ) : IndexOperator {
        return new IndexOperator( $this->db, $table );
    }

    /**
     * Start a constraint operation on a table.
     *
     * @param string $table The table name.
     * @return ConstraintOperator
     */
    protected function constraint( string $table ) : ConstraintOperator {
        return new ConstraintOperator( $this->db, $table );
    }

    /*
    |------------------
    | Other tools
    |------------------
    */

    /**
     * Inspect the current schema, e.g. to decide what a data step must do.
     *
     * @return Inspector
     */
    protected function inspect() : Inspector {
        return new Inspector( $this->db );
    }

    /**
     * Start building a query in the dialect of the database being migrated,
     * for data steps the operators do not cover.
     *
     * @return SQLBuilder
     */
    protected function query() : SQLBuilder {
        return new SQLBuilder( $this->db->get_driver() );
    }

    /**
     * Run raw SQL, when neither the operators nor the query builder can
     * express the change. The SQL is not translated: it must suit every
     * database the migration will run on.
     *
     * @param string $sql One or more SQL statements.
     * @return void
     * @throws MigrationException When the database reports a failure.
     */
    protected function exec( string $sql ) : void {
        if ( ! $this->db->exec( $sql ) ) {
            throw new MigrationException( 'SQL failed: ' . ( $this->db->get_last_error() ?? 'unknown error' ) );
        }
    }

    /**
     * The database being migrated.
     *
     * @return Database
     */
    protected function database() : Database {
        return $this->db;
    }
}