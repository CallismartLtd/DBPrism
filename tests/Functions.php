<?php
/**
 * Test functions API
 */

namespace Callismart\DBPrism\tests;

use Callismart\DBPrism\Adapters\Contracts\DatabaseAdapterInterface;
use Callismart\DBPrism\Adapters\MysqliAdapter;
use Callismart\DBPrism\Adapters\PostgresAdapter;
use Callismart\DBPrism\Adapters\SqliteAdapter;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\DBConfigDTO;
use Callismart\DBPrism\Query\SQLBuilder;
use PHPUnit\Framework\InvalidDependencyException;

/**
 * Get instance of query builder.
 */
function queryBuilder() : SQLBuilder {
    return new SQLBuilder( dbDriver() );
}

/**
 * Get the configure database driver.
 */
function dbDriver() : string {
    return getenv( 'DB_DRIVER' ) ?: 'sqlite';
}

/**
 * Get database abstraction instance
 */
function dbal() : Database {
    static $dbal;

    if ( ! isset( $dbal ) ) {
        $dbal = new Database( selectDBAdapter() );
    }

    return $dbal;
}

/**
 * Select database adapter.
 */
function selectDBAdapter() : DatabaseAdapterInterface {
    static $config;
    static $dbDriver;

    if ( ! isset( $config ) ) {
        $config = new DBConfigDTO([
            'dbname'    => \getenv( 'DB_NAME' ),
            'port'      => \getenv( 'DB_PORT' ),
            'host'      => \getenv( 'DB_HOST' ),
            'driver'    => dbDriver(),
            'password'  => 'apiv1',
            'username'  => 'apiv1'
        ]);
    }

    if ( ! isset( $dbDriver ) ) {
        $driver_class   = match( $config->driver ) {
            'mysql'     => MysqliAdapter::class,
            'pgsql'     => PostgresAdapter::class,
            'sqlite'    => SqliteAdapter::class,
            default     => throw
                new InvalidDependencyException( \sprintf(
                    'Unsupported database driver %s', $config->driver
                )
            )
        };

        $dbDriver = new $driver_class( $config );
    }

    return $dbDriver;
}