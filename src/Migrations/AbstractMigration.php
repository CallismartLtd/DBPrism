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
use Callismart\DBPrism\Inspection\Inspector;
use Callismart\DBPrism\Migrations\Operators\ColumnOperator;
use Callismart\DBPrism\Migrations\Operators\ConstraintOperator;
use Callismart\DBPrism\Migrations\Operators\IndexOperator;
use Callismart\DBPrism\Migrations\Operators\TableOperator;
use Callismart\DBPrism\Query\SQLBuilder;

/**
 * Base class for all database migrations.
 *
 * Provides fluent helper methods for common migration operations.
 * All migrations should extend this class.
 *
 * @since 0.2.0
 */
abstract class AbstractMigration implements MigrationInterface {
    /**
     * Migration start time (for tracking duration).
     *
     * @var float
     */
    protected $start_time;

    /**
     * Constructor.
     *
     * @param Database|null $db The DBAL instance or null to use the global instance.
     */
    protected function __construct( protected Database $db ) {}

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

    /**
     * Extract version from class name.
     *
     * Migration0006 → '0.0.6'
     * Migration0011 → '0.1.1'
     * Migration0020 → '0.2.0'
     *
     * @param string $class_name The migration class name
     *
     * @return string The version string
     *
     * @throws \Exception If version cannot be extracted
     */
    public static function extract_version_from_class( string $class_name ) : string {
        // Extract class name without namespace.
        $class_short = basename( str_replace( '\\', '/', $class_name ) );

        // Match Migration + 4 digits
        if ( ! preg_match( '/Migration(\d{4})/', $class_short, $matches ) ) {
            throw new \Exception( "Cannot extract version from class name: {$class_name}" );
        }

        $version_code = $matches[1];

        // Convert 0006 to 0.0.6, 0011 to 0.1.1, 0020 to 0.2.0
        return implode( '.', str_split( $version_code ) );
    }

    /**
     * {@inheritDoc}
     */
    public static function get_version() : string {
        $version_code = preg_replace( '/.*Migration(\d{4}).*/', '$1', static::class );
        return implode( '.', str_split( $version_code ) );
    }

    /**
     * Start tracking execution time.
     *
     * @return void
     */
    public function start_tracking() : void {
        $this->start_time = microtime( true );
    }

    /**
     * Get execution time in milliseconds.
     *
     * @return int Execution time in milliseconds
     */
    public function get_execution_time() : int {
        if ( ! isset( $this->start_time ) ) {
            return 0;
        }
        return (int) ( ( microtime( true ) - $this->start_time ) * 1000 );
    }

    /*
    |---------------------------
    | FLUENT HELPER METHODS
    |---------------------------
    */

    /**
     * Get a column helper for fluent operations.
     *
     * @param string $table The table name
     *
     * @return ColumnOperator
     */
    protected function column( string $table ) : ColumnOperator {
        return new ColumnOperator( $this->db, $table );
    }

    /**
     * Get an index helper for fluent operations.
     *
     * @param string $table The table name
     *
     * @return IndexOperator
     */
    protected function index( string $table ) : IndexOperator {
        return new IndexOperator( $this->db, $this->query(), $table );
    }

    /**
     * Get a constraint helper for fluent operations.
     *
     * @param string $table The table name
     *
     * @return ConstraintOperator
     */
    protected function constraint( string $table ) : ConstraintOperator {
        return new ConstraintOperator( $this->db, $table );
    }

    /**
     * Get a schema inspector for fluent operations.
     *
     * @return Inspector
     */
    protected function inspect() : Inspector {
        return new Inspector( $this->db );
    }

    /**
     * Get a table helper for fluent operations.
     *
     * @param string $table The table name
     *
     * @return TableOperator
     */
    protected function table( string $table ) : TableOperator {
        return new TableOperator( $this->db, $table );
    }

    /**
     * Execute raw SQL directly.
     *
     * Use only when Operators don't provide what you need.
     *
     * @param string $sql The SQL statement
     *
     * @return bool True on success
     *
     * @throws \Exception On execution failure
     */
    protected function exec( string $sql ) : bool {
        return $this->db->exec( $sql );
    }

    /**
     * Get the database bstraction instance.
     *
     * @return Database
     */
    protected function database() : Database {
        return $this->db;
    }

    /**
     * Start query building.
     * 
     * @return SQLBuilder
     */
    protected function query() : SQLBuilder {
        return new SQLBuilder( $this->db->get_driver() );
    }
}