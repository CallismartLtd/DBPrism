<?php
/**
 * Database inspection information data transfer object.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 * @since   0.2.0
 */

declare( strict_types = 1 );

namespace Callismart\DBPrism;

use LogicException;
use Callismart\DTO\DTO;

/**
 * Database inspection information DTO.
 *
 * Represents information discovered from an active database connection and
 * database engine during schema and connection inspection.
 *
 * Unlike DBConfigDTO, this DTO does not represent user-supplied connection
 * configuration. Its values describe the database system as reported by the
 * active connection or, where the engine cannot expose equivalent information,
 * a documented configuration fallback.
 *
 * Sensitive authentication credentials must never be stored in this DTO.
 *
 * @property string      $engine              Database engine/driver name.
 * @property ?string     $product             Database server product name.
 * @property ?string     $version             Database server version.
 * @property string|int|null $protocol_version Database protocol version.
 * @property ?string     $database            Currently selected database name.
 * @property ?string     $server              Database server hostname or address.
 * @property ?int        $port                 Database server port.
 * @property ?string     $transport            Connection transport such as tcp,
 *                                              unix_socket, file, or memory.
 * @property ?string     $socket              Active UNIX socket path, when applicable.
 * @property ?string     $path                Database file path, when applicable.
 * @property ?bool       $ssl                  Whether the active connection uses SSL/TLS.
 * @property ?string     $charset              Active database/session character set.
 * @property ?string     $collation            Active database/session collation.
 * @property ?string     $timezone             Active database/session timezone.
 * @property ?string     $locale               Active database/session locale.
 * @property ?string     $schema               Current/default schema, where applicable.
 * @property ?string     $server_os            Server operating system, when exposed.
 * @property ?string     $server_architecture  Server architecture, when exposed.
 * @property ?string     $server_hostname      Server-reported hostname, when exposed.
 * @property ?array      $capabilities         Engine capabilities discovered during inspection.
 * @property ?array      $features             Engine-specific feature/version information.
 * @property ?array      $runtime              Additional runtime/server information.
 *
 * @method void __construct( array{
 *     'engine': string,
 *     'product': ?string,
 *     'version': ?string,
 *     'protocol_version': string|int|null,
 *     'database': ?string,
 *     'server': ?string,
 *     'port': ?int,
 *     'transport': ?string,
 *     'socket': ?string,
 *     'path': ?string,
 *     'ssl': ?bool,
 *     'charset': ?string,
 *     'collation': ?string,
 *     'timezone': ?string,
 *     'locale': ?string,
 *     'schema': ?string,
 *     'server_os': ?string,
 *     'server_architecture': ?string,
 *     'server_hostname': ?string,
 *     'capabilities': ?array,
 *     'features': ?array,
 *     'runtime': ?array,
 * } $config = [] )
 */
final class DatabaseInfoDTO extends DTO {

	/**
	 * Allowed information keys.
	 *
	 * @return string[]
	 */
	protected function allowed_keys(): array {
		return [
			'engine',
			'product',
			'version',
			'protocol_version',
			'database',
			'server',
			'port',
			'transport',
			'socket',
			'path',
			'ssl',
			'charset',
			'collation',
			'timezone',
			'locale',
			'schema',
			'server_os',
			'server_architecture',
			'server_hostname',
			'capabilities',
			'features',
			'runtime',
		];
	}

	/**
	 * Required information keys.
	 *
	 * Engine identity is the minimum information required for a valid
	 * inspection result.
	 *
	 * @return string[]
	 */
	protected function required_keys(): array {
		return [
			'engine',
		];
	}

	/**
	 * Cast values to their expected types.
	 *
	 * @param string $key
	 * @param mixed  $value
	 * @return mixed
	 */
	protected function cast( string $key, mixed $value ): mixed {
		return match ( $key ) {

			'engine',
			'product',
			'version',
			'database',
			'server',
			'transport',
			'socket',
			'path',
			'charset',
			'collation',
			'timezone',
			'locale',
			'schema',
			'server_os',
			'server_architecture',
			'server_hostname'
				=> null === $value ? null : (string) $value,

			'port'
				=> null === $value ? null : (int) $value,

			'ssl'
				=> null === $value ? null : (bool) $value,

			'capabilities',
			'features',
			'runtime'
				=> null === $value ? null : (array) $value,

			'protocol_version'
				=> null === $value
					? null
					: ( is_int( $value ) ? $value : (string) $value ),

			default => $value,
		};
	}

	/**
	 * Prevent cloning.
	 *
	 * @throws LogicException
	 */
	public function __clone() {
		throw new LogicException(
			'Cloning DatabaseInfoDTO is not allowed.'
		);
	}

	/**
	 * Prevent serialization.
	 *
	 * @return array
	 * @throws LogicException
	 */
	public function __serialize(): array {
		throw new LogicException(
			'Serialization of DatabaseInfoDTO is not allowed.'
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
			'Unserialization of DatabaseInfoDTO is not allowed.'
		);
	}
}