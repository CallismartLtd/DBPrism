<?php
/**
 * PDO DSN builder class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Utils
 */

declare( strict_types = 1 );

namespace Callismart\DBPrism\Utils;

use Callismart\DBPrism\DBConfigDTO;
use InvalidArgumentException;

/**
 * Builds PDO data source name (DSN) strings from a database configuration.
 *
 * Supports the drivers DBConfigDTO accepts (mysql, pgsql, sqlite). An
 * explicit `dsn` in the configuration is returned as is.
 *
 * Optional settings are included only when they hold a value: DBConfigDTO
 * keys can be present with a null value, so presence alone is not checked.
 *
 * Example:
 *
 *   $dsn = DsnBuilder::build( $config ); // "mysql:host=localhost;port=3306;dbname=app;charset=utf8mb4"
 *
 * @package Callismart\DBPrism\Utils
 */
final class DsnBuilder {

    /**
     * Default host for network drivers.
     */
    public const DEFAULT_HOST = 'localhost';

    /**
     * Extension appended to SQLite database names that have none.
     */
    public const SQLITE_EXTENSION = '.db';

    /**
     * Static utility; not instantiable.
     */
    private function __construct() {}

    /**
     * Build the DSN for the given configuration.
     *
     * @param DBConfigDTO $config Database configuration.
     * @return string
     * @throws InvalidArgumentException When a required setting is missing, a value
     *                                  would break the DSN, or the driver is unsupported.
     */
    public static function build( DBConfigDTO $config ) : string {
        $dsn = static::value( $config, 'dsn' );

        if ( null !== $dsn ) {
            return $dsn;
        }

        $driver = static::value( $config, 'driver' );

        return match ( $driver ) {
            'mysql'  => static::mysql( $config ),
            'pgsql'  => static::pgsql( $config ),
            'sqlite' => static::sqlite( $config ),
            null     => throw new InvalidArgumentException( 'Database driver was not specified.' ),
            default  => throw new InvalidArgumentException(
                sprintf( 'Cannot build a DSN for unsupported database driver "%s".', $driver )
            ),
        };
    }

    /**
     * Build a MySQL / MariaDB DSN.
     *
     * Connects through `socket` when set, otherwise through `host` and `port`.
     *
     * @param DBConfigDTO $config Database configuration.
     * @return string
     * @throws InvalidArgumentException
     */
    public static function mysql( DBConfigDTO $config ) : string {
        $socket = static::value( $config, 'socket' );
        $parts  = null !== $socket
            ? array( 'unix_socket' => $socket )
            : array(
                'host' => static::value( $config, 'host' ) ?? static::DEFAULT_HOST,
                'port' => static::value( $config, 'port' ),
            );

        $parts['dbname']  = static::required( $config, 'dbname' );
        $parts['charset'] = static::value( $config, 'charset' );

        return 'mysql:' . static::join( $parts );
    }

    /**
     * Build a PostgreSQL DSN.
     *
     * @param DBConfigDTO $config Database configuration.
     * @return string
     * @throws InvalidArgumentException
     */
    public static function pgsql( DBConfigDTO $config ) : string {
        $charset = static::value( $config, 'charset' );

        $parts = array(
            'host'    => static::value( $config, 'host' ) ?? static::DEFAULT_HOST,
            'port'    => static::value( $config, 'port' ),
            'dbname'  => static::required( $config, 'dbname' ),
            'sslmode' => static::value( $config, 'sslmode' ),
            'options' => null === $charset ? null : "'--client_encoding={$charset}'",
        );

        return 'pgsql:' . static::join( $parts );
    }

    /**
     * Build a SQLite DSN.
     *
     * `dbname` ":memory:" opens an in-memory database. With `path` set,
     * `dbname` is the file name inside that directory (".db" is appended when
     * it has no extension); without it, `dbname` is used as the file path.
     *
     * @param DBConfigDTO $config Database configuration.
     * @return string
     * @throws InvalidArgumentException
     */
    public static function sqlite( DBConfigDTO $config ) : string {
        $dbname = static::required( $config, 'dbname' );

        if ( ':memory:' === $dbname ) {
            return 'sqlite::memory:';
        }

        $path = static::value( $config, 'path' );

        if ( null === $path ) {
            return "sqlite:{$dbname}";
        }

        $filename = '' === pathinfo( $dbname, PATHINFO_EXTENSION ) ? $dbname . static::SQLITE_EXTENSION : $dbname;

        return 'sqlite:' . rtrim( $path, '/\\' ) . '/' . $filename;
    }

    /*
    |----------
    | Helpers
    |----------
    */

    /**
     * Read a setting as a non-empty string.
     *
     * @param DBConfigDTO $config Database configuration.
     * @param string      $key    Setting name.
     * @return string|null Null when unset, null or empty.
     */
    private static function value( DBConfigDTO $config, string $key ) : ?string {
        $value = $config->get( $key );

        if ( null === $value || is_array( $value ) || is_bool( $value ) ) {
            return null;
        }

        $value = trim( (string) $value );

        return '' === $value ? null : $value;
    }

    /**
     * Read a required setting.
     *
     * @param DBConfigDTO $config Database configuration.
     * @param string      $key    Setting name.
     * @return string
     * @throws InvalidArgumentException When the setting is missing or empty.
     */
    private static function required( DBConfigDTO $config, string $key ) : string {
        return static::value( $config, $key )
            ?? throw new InvalidArgumentException( sprintf( 'The "%s" database setting is required to build the DSN.', $key ) );
    }

    /**
     * Join DSN parts as key=value pairs, skipping null values.
     *
     * Values cannot be escaped in a PDO DSN, so a value containing ";" is
     * rejected rather than allowed to add or override parameters.
     *
     * @param array<string, string|null> $parts Parameter => value.
     * @return string
     * @throws InvalidArgumentException When a value contains ";".
     */
    private static function join( array $parts ) : string {
        $pairs = array();

        foreach ( $parts as $key => $value ) {
            if ( null === $value ) {
                continue;
            }

            if ( str_contains( $value, ';' ) ) {
                throw new InvalidArgumentException(
                    sprintf( 'The "%s" database setting cannot contain ";".', $key )
                );
            }

            $pairs[] = "{$key}={$value}";
        }

        return implode( ';', $pairs );
    }
}