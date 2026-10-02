<?php
/**
 * Database configuration data transfer object file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart
 */

declare( strict_types = 1 );

namespace Callismart\DBPrism;

use LogicException;
use Callismart\DTO\DTO;

/**
 * Database configuration data transfer object.
 *
 * Sensitive credentials are automatically protected from
 * exposure through debugging or serialization.
 * 
 * @property string  $driver     The database adapter engine target (e.g., 'mysql', 'sqlite', 'pgsql').
 * @property string  $dbname     The name of the target database or schema.
 * @property ?string $host      The database server network hostname or IP address.
 * @property ?int    $port      The database server network communication port (1-65535).
 * @property ?string $username  The connection authentication identity string.
 * @property ?string $password  The connection authentication credential secret.
 * @property ?string $charset   The text character encoding layout specification.
 * @property ?string $collation The text string sorting and comparison criteria rule.
 * @property ?string $prefix    The database engine table namespace identifier prefix.
 * @property ?string $socket    The local system IPC Unix socket connection endpoint path.
 * @property ?string $path      The file-system target path for isolated file-based databases.
 * @property ?string $dsn       A raw engine connection string override used to bypass default parameterization.
 * @property ?array  $flags     Engine-specific runtime connection attributes or configuration options.
 * @property ?array  $ssl        SSL deployment options and authority certificates mapping.
 * @property ?string $sslmode    SSL transmission enforcement tier (PostgreSQL/MySQL).
 * @property ?string $encryption_key Encryption key for database-level operations (e.g., MySQL TDE, SQLite encryption).
 * @property ?bool   $strict     Enforcement behavior rule modifier for SQL execution modes.
 * @property ?bool   $persistent Connection reuse strategy persistence indicator flag.
 * @property ?int    $timeout    Temporal boundary restriction constraint for connection limits, in seconds.
 * @property ?array  $read       High-availability routing configuration metrics for read replicas.
 * @property ?array  $write      High-availability routing configuration metrics for write primaries.
 * @property ?bool   $sticky     Immediate transactional lookup mapping flag for replica routing.
 * 
 * @method void __construct( array{
 *     'driver': string,
 *     'dbname': string,
 *     'host': ?string,
 *     'port': int|string|null,
 *     'username': ?string,
 *     'password': ?string,
 *     'charset': ?string,
 *     'collation': ?string,
 *     'prefix': ?string,
 *     'socket': ?string,
 *     'path': ?string,
 *     'dsn': ?string,
 *     'flags': ?array,
 *     'ssl': ?array,
 *     'sslmode': ?string,
 *     'encryption_key': ?string,
 *     'strict': ?bool,
 *     'persistent': ?bool,
 *     'timeout': ?int,
 *     'read': ?array,
 *     'write': ?array,
 *     'sticky': ?bool,
 * } $config = [] ) Initializes the configuration DTO with an optional associative array of parameters.
 *
 * Values are cast to the types above on every write (constructor, set(),
 * merge(), magic and array access). Null always stays null. For the int,
 * bool and array fields an empty string also means null, so values read
 * from .env files or CLI options can be passed as they are; strings are
 * kept as given, so an empty password or prefix stays empty.
 *
 * Accepted input per type:
 * - string: strings, numbers and Stringable objects.
 * - int:    integers and integer strings ("3306").
 * - bool:   booleans, 0/1, and "true"/"false", "1"/"0", "yes"/"no", "on"/"off".
 * - array:  arrays, and JSON object/array strings.
 *
 * Anything else throws InvalidArgumentException naming the key.
 */
final class DBConfigDTO extends DTO {

    /**
     * Allowed configuration keys.
     *
     * Supports network databases, cluster architectures, and file-based storage.
     *
     * @return string[]
     */
    protected function allowed_keys(): array {
        return [
            'driver',      // mysql | sqlite | pgsql | etc
            'host',
            'port',
            'dbname',
            'username',
            'password',
            'charset',
            'collation',
            'prefix',
            'socket',
            'path',
            'dsn',
            'flags',
            'ssl',
            'sslmode',
            'encryption_key',
            'strict',
            'persistent',
            'timeout',     
            'read',
            'write',       
            'sticky',
        ];
    }

    /**
     * Sensitive configuration keys.
     *
     * @return string[]
     */
    protected function sensitive_keys(): array {
        return [
            'password',
            'encryption_key',
        ];
    }

    /**
     * {@inheritdoc}
     */
    protected function required_keys(): array {
        return ['driver', 'dbname'];
    }

    /**
     * Value type of every key, as documented in the class docblock.
     *
     * A leading "?" marks the key nullable.
     *
     * @var array<string, string>
     */
    protected const TYPES = [
        'driver'         => 'string',
        'dbname'         => 'string',
        'host'           => '?string',
        'port'           => '?int',
        'username'       => '?string',
        'password'       => '?string',
        'charset'        => '?string',
        'collation'      => '?string',
        'prefix'         => '?string',
        'socket'         => '?string',
        'path'           => '?string',
        'dsn'            => '?string',
        'flags'          => '?array',
        'ssl'            => '?array',
        'sslmode'        => '?string',
        'encryption_key' => '?string',
        'strict'         => '?bool',
        'persistent'     => '?bool',
        'timeout'        => '?int',
        'read'           => '?array',
        'write'          => '?array',
        'sticky'         => '?bool',
    ];

    /**
     * Supported database drivers.
     *
     * @var string[]
     */
    protected const DRIVERS = [ 'mysql', 'pgsql', 'sqlite' ];

    /**
     * Cast a value to the type declared for its key.
     *
     * @param string $key
     * @param mixed  $value
     * @return mixed
     * @throws \InvalidArgumentException When the value cannot be cast to the key's type.
     */
    protected function cast( string $key, mixed $value ): mixed {
        $type     = static::TYPES[ $key ] ?? 'mixed';
        $nullable = str_starts_with( $type, '?' );
        $type     = ltrim( $type, '?' );

        if ( null === $value || ( $nullable && '' === $value && 'string' !== $type ) ) {
            if ( ! $nullable && 'mixed' !== $type ) {
                throw new \InvalidArgumentException( sprintf( 'The "%s" database setting is required.', $key ) );
            }

            return null;
        }

        $value = match ( $type ) {
            'string' => $this->cast_string( $key, $value ),
            'int'    => $this->cast_int( $key, $value ),
            'bool'   => $this->cast_bool( $key, $value ),
            'array'  => $this->cast_array( $key, $value ),
            default  => $value,
        };

        return match ( $key ) {
            'driver' => $this->check_driver( $value ),
            'port'   => $this->check_range( $key, $value, 1, 65535 ),
            'timeout' => $this->check_range( $key, $value, 0, \PHP_INT_MAX ),
            default  => $value,
        };
    }

    /**
     * Cast to string.
     *
     * @param string $key
     * @param mixed  $value Non-null value.
     * @return string
     * @throws \InvalidArgumentException
     */
    private function cast_string( string $key, mixed $value ): string {
        if ( is_string( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_float( $value ) || $value instanceof \Stringable ) {
            return (string) $value;
        }

        throw $this->type_error( $key, 'a string', $value );
    }

    /**
     * Cast to int.
     *
     * @param string $key
     * @param mixed  $value Non-null value.
     * @return int
     * @throws \InvalidArgumentException
     */
    private function cast_int( string $key, mixed $value ): int {
        if ( is_int( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) && preg_match( '/^\s*[+-]?\d+\s*$/', $value ) ) {
            return (int) trim( $value );
        }

        if ( is_float( $value ) && floor( $value ) === $value ) {
            return (int) $value;
        }

        throw $this->type_error( $key, 'a whole number', $value );
    }

    /**
     * Cast to bool.
     *
     * @param string $key
     * @param mixed  $value Non-null value.
     * @return bool
     * @throws \InvalidArgumentException
     */
    private function cast_bool( string $key, mixed $value ): bool {
        if ( is_bool( $value ) ) {
            return $value;
        }

        if ( is_int( $value ) || is_string( $value ) ) {
            $bool = filter_var( $value, \FILTER_VALIDATE_BOOLEAN, \FILTER_NULL_ON_FAILURE );

            if ( null !== $bool ) {
                return $bool;
            }
        }

        throw $this->type_error( $key, 'true or false', $value );
    }

    /**
     * Cast to array.
     *
     * @param string $key
     * @param mixed  $value Non-null value.
     * @return array
     * @throws \InvalidArgumentException
     */
    private function cast_array( string $key, mixed $value ): array {
        if ( is_array( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );

            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        throw $this->type_error( $key, 'an array or a JSON object', $value );
    }

    /**
     * Check the driver is supported.
     *
     * @param string $driver
     * @return string
     * @throws \InvalidArgumentException
     */
    private function check_driver( string $driver ): string {
        if ( ! in_array( $driver, static::DRIVERS, true ) ) {
            throw new \InvalidArgumentException(
                sprintf( 'Unsupported database driver "%s". Supported drivers: %s.', $driver, implode( ', ', static::DRIVERS ) )
            );
        }

        return $driver;
    }

    /**
     * Check an integer lies within a range.
     *
     * @param string $key
     * @param int    $value
     * @param int    $min
     * @param int    $max
     * @return int
     * @throws \InvalidArgumentException
     */
    private function check_range( string $key, int $value, int $min, int $max ): int {
        if ( $value < $min || $value > $max ) {
            throw new \InvalidArgumentException(
                \PHP_INT_MAX === $max
                    ? sprintf( 'The "%s" database setting must be %d or more, got %d.', $key, $min, $value )
                    : sprintf( 'The "%s" database setting must be between %d and %d, got %d.', $key, $min, $max, $value )
            );
        }

        return $value;
    }

    /**
     * Build the exception for a value of the wrong type.
     *
     * Never includes the value itself, which may be a credential.
     *
     * @param string $key
     * @param string $expected
     * @param mixed  $value
     * @return \InvalidArgumentException
     */
    private function type_error( string $key, string $expected, mixed $value ): \InvalidArgumentException {
        return new \InvalidArgumentException(
            sprintf( 'The "%s" database setting must be %s, got %s.', $key, $expected, get_debug_type( $value ) )
        );
    }

    /**
     * Return a masked array representation of the configuration.
     *
     * @return array<string, mixed>
     */
    public function to_array(): array {

        $data = parent::to_array();

        foreach ( $this->sensitive_keys() as $key ) {

            if ( array_key_exists( $key, $data ) ) {
                $data[ $key ] = '******';
            }
        }

        return $data;
    }

    /**
     * JSON serialization handler.
     *
     * @return mixed
     */
    public function jsonSerialize(): mixed {
        return $this->to_array();
    }

    /**
     * Debug handler for var_dump().
     *
     * Prevents credential leakage.
     *
     * @return array<string, mixed>
     */
    public function __debugInfo(): array {

        return [
            'class' => static::class,
            'count' => $this->count(),
            'props' => $this->to_array(),
        ];
    }

    /**
     * Prevent cloning.
     *
     * @throws LogicException
     */
    public function __clone() {
        throw new LogicException(
            'Cloning DBConfigDTO is not allowed.'
        );
    }

    /**
     * Prevent serialization.
     *
     * @throws LogicException
     */
    public function __serialize(): array {
        throw new LogicException(
            'Serialization of DBConfigDTO is not allowed.'
        );
    }

    /**
     * Prevent unserialization.
     *
     * @param array<string,mixed> $data
     * @throws LogicException
     */
    public function __unserialize( array $data ): void {
        throw new LogicException(
            'Unserialization of DBConfigDTO is not allowed.'
        );
    }
}