<?php
/**
 * MySQL database inspector implementation
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Inspection\Providers
 */

namespace Callismart\DBPrism\Inspection\Providers;

/**
 * Inspector for MySQL/MariaDB databases.
 * 
 * Implements schema inspection using MySQL-specific information_schema queries
 * via the DatabaseAdapterInterface.
 */
class MysqlInspector extends AbstractInspector {

	/**
	 * Whether the server is MariaDB, once known.
	 *
	 * @var bool|null
	 */
	private ?bool $is_mariadb = null;

	/**
	 * Normalize MySQL column types.
	 * 
	 * Converts types like "int(11)", "varchar(255)" to normalized forms.
	 */
	protected function normalize_type( string $type ): string {
		// Remove length specifications and trim whitespace
		$type = preg_replace( '/\(\d+(?:,\d+)?\)/', '', $type );
		$type = trim( strtolower( $type ) );

		// Normalize MySQL-specific types
		$type_map = array(
			'integer'      => 'int',
			'smallint'     => 'smallint',
			'tinyint'      => 'tinyint',
			'bigint'       => 'bigint',
			'float'        => 'float',
			'double'       => 'double',
			'decimal'      => 'decimal',
			'char'         => 'char',
			'varchar'      => 'varchar',
			'text'         => 'text',
			'tinytext'     => 'tinytext',
			'mediumtext'   => 'mediumtext',
			'longtext'     => 'longtext',
			'blob'         => 'blob',
			'tinyblob'     => 'tinyblob',
			'mediumblob'   => 'mediumblob',
			'longblob'     => 'longblob',
			'date'         => 'date',
			'time'         => 'time',
			'datetime'     => 'datetime',
			'timestamp'    => 'timestamp',
			'year'         => 'year',
			'enum'         => 'enum',
			'set'          => 'set',
			'json'         => 'json',
			'boolean'      => 'tinyint',
			'bool'         => 'tinyint',
		);

		return $type_map[ $type ] ?? $type;
	}

	/**
	 * Get all tables in the database.
	 */
	protected function sql_all_tables(): string {
		return "SELECT TABLE_NAME FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'";
	}

	/**
	 * Check if a table exists.
	 */
	protected function sql_table_exists( string $table ): string {
		return "SELECT 1 FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? LIMIT 1";
	}

	/**
	 * Check if a column exists.
	 */
	protected function sql_column_exists( string $table, string $column ): string {
		return "SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1";
	}

	/**
	 * Get column details.
	 */
	protected function sql_column_details( string $table ): string {
		return "SELECT 
				COLUMN_NAME as column_name,
				COLUMN_TYPE as column_type,
				IS_NULLABLE as is_nullable,
				COLUMN_DEFAULT as column_default,
				EXTRA as extra
			FROM INFORMATION_SCHEMA.COLUMNS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
			ORDER BY ORDINAL_POSITION";
	}

	/**
	 * Override of InspectionInterface::is_column_nullable()
	 */
	public function is_column_nullable( string $table, string $column ): ?bool {
		$rows = $this->execute_query( $this->sql_column_details( $table ), [ $table ] );

		foreach ( $rows as $row ) {
			if ( $row['column_name'] === $column ) {
				return $row['is_nullable'] === 'YES';
			}
		}

		return null;
	}
	/**
	 * Get column details with auto_increment detection.
	 * 
	 * Override to detect auto_increment from EXTRA column.
	 */
	public function get_column_details( string $table ): array {
		$rows    = $this->execute_query( $this->sql_column_details( $table ), [ $table ] );
		$details = array();

		foreach ( $rows as $row ) {
			$column_name = $row['column_name'];
			$details[ $column_name ] = array(
				'type'           => $this->normalize_type( $row['column_type'] ),
				'nullable'       => $row['is_nullable'] === 'YES',
				'default'        => $this->normalize_default( $row['column_default'] ?? null ),
				'auto_increment' => strpos( $row['extra'], 'auto_increment' ) !== false,
			);
		}

		return $details;
	}

	/**
	 * {@inheritdoc}
	 */
	public function has_index( string $table, string $index_name ) : bool {
		$sql	= "SELECT INDEX_NAME FROM information_schema.statistics
		WHERE table_schema = DATABASE() AND table_name = ? AND index_name = ? LIMIT 1;";
		
		$result	= $this->dbal->get_var( $sql, [$table, $index_name ] );
		
		return $result ? true : false;
	}

	/**
	 * Get indexes.
	 */
	protected function sql_indexes( string $table ): string {
		return "SELECT 
				INDEX_NAME as index_name,
				COLUMN_NAME as column_name,
				NOT NON_UNIQUE as is_unique,
				SEQ_IN_INDEX as seq_in_index
			FROM INFORMATION_SCHEMA.STATISTICS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME != 'PRIMARY'
			ORDER BY INDEX_NAME, SEQ_IN_INDEX";
	}

	/**
	 * Get primary key.
	 */
	protected function sql_primary_key( string $table ): string {
		return "SELECT 
				COLUMN_NAME as column_name,
				SEQ_IN_INDEX as seq_in_index
			FROM INFORMATION_SCHEMA.STATISTICS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = 'PRIMARY'
			ORDER BY SEQ_IN_INDEX";
	}

	/**
	 * {@inheritdoc}
	 *
	 * MySQL always names the primary key PRIMARY.
	 */
	public function get_primary_key_name( string $table ): ?string {
		return null === $this->get_primary_key( $table ) ? null : 'PRIMARY';
	}

	/**
	 * Get foreign keys.
	 */
	protected function sql_foreign_keys( string $table ): string {
		return "SELECT 
				CONSTRAINT_NAME as constraint_name,
				COLUMN_NAME as column_name,
				REFERENCED_TABLE_NAME as referenced_table,
				REFERENCED_COLUMN_NAME as referenced_column
			FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND REFERENCED_TABLE_NAME IS NOT NULL
			ORDER BY CONSTRAINT_NAME, ORDINAL_POSITION";
	}

	/**
	 * Get unique constraints.
	 * 
	 * MySQL doesn't distinguish unique constraints from unique indexes, so we query indexes.
	 */
	protected function sql_unique_constraints( string $table ): string {
		return "SELECT 
				INDEX_NAME as constraint_name,
				COLUMN_NAME as column_name,
				SEQ_IN_INDEX as seq_in_index
			FROM INFORMATION_SCHEMA.STATISTICS
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND NON_UNIQUE = 0 AND INDEX_NAME != 'PRIMARY'
			ORDER BY INDEX_NAME, SEQ_IN_INDEX";
	}

	/**
	 * Get check constraints.
	 *
	 * MySQL 8.0.16+ and MariaDB 10.2+ list CHECK constraints in
	 * information_schema.CHECK_CONSTRAINTS. MySQL's view has no TABLE_NAME
	 * column, so the table comes from TABLE_CONSTRAINTS; MariaDB's has one,
	 * and names a CHECK constraint per table, so it is matched there too.
	 */
	protected function sql_check_constraints( string $table ): string {
		if ( $this->is_mariadb() ) {
			return "SELECT 
					CONSTRAINT_NAME as constraint_name,
					CHECK_CLAUSE as definition
				FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS
				WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?";
		}

		return "SELECT 
				cc.CONSTRAINT_NAME as constraint_name,
				cc.CHECK_CLAUSE as definition
			FROM INFORMATION_SCHEMA.CHECK_CONSTRAINTS cc
			JOIN INFORMATION_SCHEMA.TABLE_CONSTRAINTS tc
				ON tc.CONSTRAINT_SCHEMA = cc.CONSTRAINT_SCHEMA AND tc.CONSTRAINT_NAME = cc.CONSTRAINT_NAME
			WHERE tc.CONSTRAINT_TYPE = 'CHECK' AND tc.TABLE_SCHEMA = DATABASE() AND tc.TABLE_NAME = ?";
	}

	/**
	 * Whether the server is MariaDB.
	 *
	 * @return bool
	 */
	protected function is_mariadb(): bool {
		return $this->is_mariadb ??= false !== stripos( (string) $this->dbal->get_var( 'SELECT VERSION()' ), 'MariaDB' );
	}

	/**
	 * A column default as information_schema reports it, the same on MySQL and MariaDB.
	 *
	 * MariaDB 10.2.7+ reports a literal default quoted ('x') and no default
	 * as the text NULL; MySQL reports the bare value and SQL NULL. A default
	 * that is an expression (e.g. current_timestamp()) is left as reported.
	 *
	 * @param mixed $default The reported COLUMN_DEFAULT.
	 * @return mixed
	 */
	protected function normalize_default( mixed $default ): mixed {
		if ( ! is_string( $default ) || ! $this->is_mariadb() ) {
			return $default;
		}

		if ( 'NULL' === $default ) {
			return null;
		}

		if ( strlen( $default ) >= 2 && "'" === $default[0] && "'" === substr( $default, -1 ) ) {
			return str_replace( array( "''", '\\\\' ), array( "'", '\\' ), substr( $default, 1, -1 ) );
		}

		return $default;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_column_default( string $table, string $column ) {
		return $this->normalize_default( parent::get_column_default( $table, $column ) );
	}

	/**
	 * Get table metadata.
	 */
	protected function sql_table_metadata( string $table ): string {
		return "SELECT 
				ENGINE as engine,
				TABLE_COLLATION as collation,
				TABLE_COMMENT as comment,
				TABLE_ROWS as row_count
			FROM INFORMATION_SCHEMA.TABLES
			WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?";
	}

	/**
	 * Get table metadata with charset extraction.
	 * 
	 * Override to extract charset from collation.
	 */
	public function get_table_metadata( string $table ): array {
		$rows = $this->execute_query( $this->sql_table_metadata( $table ), [ $table ] );

		if ( empty( $rows ) ) {
			return array();
		}

		$row = $rows[0];

		// Extract charset from collation (e.g., "utf8mb4_unicode_ci" -> "utf8mb4")
		$charset = null;
		if ( ! empty( $row['collation'] ) ) {
			$parts   = explode( '_', $row['collation'] );
			$charset = $parts[0];
		}

		return array(
			'engine'    => $row['engine'] ?? null,
			'charset'   => $charset,
			'collation' => $row['collation'] ?? null,
			'row_count' => (int) ( $row['row_count'] ?? 0 ),
			'comment'   => $row['comment'] ?? '',
		);
	}

	/**
	 * Get protocol version.
	 */
	public function get_protocol_version() {
		$version = $this->dbal->get_row( 'SHOW VARIABLES LIKE "protocol_version"' );
		if ( ! empty( $version ) ) {
			return (int) $version['Value'];
		}
		return null;
	}

	/**
	 * Get server version.
	 */
	public function get_server_version(): string {
		$version = $this->dbal->get_var( 'SELECT VERSION() as version' );
		if ( ! empty( $version ) ) {
			if ( preg_match( '/^\d+\.\d+\.\d+/', $version, $matches ) ) {
				return $matches[0];
			}
			
			return (string) $version;
		}
		return 'unknown';
	}

	/**
	 * Get engine type.
	 */
	public function get_engine_type(): string {
		return 'mysql';
	}

	/**
	 * Retrieve MySQL/MariaDB information aligned with DatabaseInfoDTO keys.
	 *
	 * @return array<string, mixed>
	 */
	protected function inspect_database_info(): array {
		$core_query = "
			SELECT
				DATABASE() AS database_name,
				VERSION() AS server_version,
				@@version_comment AS version_comment,
				@@version_compile_os AS server_os,
				@@version_compile_machine AS server_architecture,
				@@hostname AS server_hostname,
				@@port AS server_port,
				@@character_set_connection AS charset,
				@@collation_connection AS collation,
				@@time_zone AS timezone,
				@@lc_time_names AS locale,
				@@default_storage_engine AS default_engine,
				@@sql_mode AS sql_mode,
				@@max_connections AS max_connections,
				@@wait_timeout AS wait_timeout,
				@@interactive_timeout AS interactive_timeout,
				CONNECTION_ID() AS connection_id
			";

		try {
			$row = $this->dbal->get_row( $core_query );
		} catch ( \Throwable $e ) {
			$row = null;
		}

		// Some session variables above are restricted on managed MySQL (e.g. RDS)
		// or absent on certain forks. If the full query failed, fall back to the
		// minimum needed to identify the engine rather than returning nothing.
		if ( ! is_array( $row ) ) {
			try {
				$row = $this->dbal->get_row(
					'SELECT DATABASE() AS database_name, VERSION() AS server_version, CONNECTION_ID() AS connection_id'
				);
			} catch ( \Throwable $e ) {
				$row = null;
			}
		}

		if ( ! is_array( $row ) ) {
			return array();
		}

		try {
			$ssl_status = $this->dbal->get_row( "SHOW SESSION STATUS LIKE 'Ssl_cipher'" );
			$cipher_val = is_array( $ssl_status ) ? ( $ssl_status['Value'] ?? $ssl_status['value'] ?? '' ) : '';
			$is_ssl     = ! empty( $cipher_val ) && 'NONE' !== strtoupper( (string) $cipher_val );
		} catch ( \Throwable $e ) {
			// A failed status query means "unknown", not "confirmed no SSL".
			$is_ssl = null;
		}

		$version_str = (string) ( $row['server_version'] ?? '' );
		$comment_str = (string) ( $row['version_comment'] ?? '' );

		$is_mariadb = false !== stripos( $version_str, 'MariaDB' )
					|| false !== stripos( $comment_str, 'MariaDB' );

		// Transaction/FK/savepoint support depends on the storage engine, not
		// MySQL/MariaDB itself. This reflects only the *default* engine — a
		// database can still mix engines per table.
		$default_engine = strtolower( (string) ( $row['default_engine'] ?? '' ) );
		$transactional  = in_array( $default_engine, array( 'innodb', 'ndbcluster', 'ndb', 'rocksdb', 'tokudb' ), true );

		$hostname = ! empty( $row['server_hostname'] ) ? (string) $row['server_hostname'] : null;

		return array(
			'engine'              => 'mysql',
			'product'             => $is_mariadb ? 'MariaDB' : 'MySQL',
			'version'             => $row['server_version'] ?? null,
			'protocol_version'    => null,
			'database'            => ! empty( $row['database_name'] ) ? $row['database_name'] : null,
			'server'              => $hostname,
			'port'                => isset( $row['server_port'] ) ? (int) $row['server_port'] : null,
			'transport'           => null,
			'socket'              => null,
			'path'                => null,
			'ssl'                 => $is_ssl,
			'charset'             => $row['charset'] ?? null,
			'collation'           => $row['collation'] ?? null,
			'timezone'            => $row['timezone'] ?? null,
			'locale'              => $row['locale'] ?? null,
			'schema'              => ! empty( $row['database_name'] ) ? $row['database_name'] : null,
			'server_os'           => $row['server_os'] ?? null,
			'server_architecture' => $row['server_architecture'] ?? null,
			'server_hostname'     => $hostname,
			'capabilities'        => array(
				'transactions' => $transactional,
				'foreign_keys' => $transactional,
				'savepoints'   => $transactional,
			),
			'features'            => array(
				'default_engine' => $row['default_engine'] ?? null,
				'sql_mode'       => $row['sql_mode'] ?? null,
			),
			'runtime'             => array(
				'connection_id'       => isset( $row['connection_id'] ) ? (int) $row['connection_id'] : null,
				'max_connections'     => isset( $row['max_connections'] ) ? (int) $row['max_connections'] : null,
				'wait_timeout'        => isset( $row['wait_timeout'] ) ? (int) $row['wait_timeout'] : null,
				'interactive_timeout' => isset( $row['interactive_timeout'] ) ? (int) $row['interactive_timeout'] : null,
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Sums data_length + index_length across every table in the
	 * current schema, via information_schema.TABLES. These figures
	 * are InnoDB/MyISAM statistics estimates, not an exact byte count
	 * (MySQL itself documents them as approximate for InnoDB), but are
	 * the standard way to report database size for MySQL/MariaDB.
	 */
	public function get_database_size(): ?int {
		try {
			$size = $this->dbal->get_var(
"SELECT SUM(DATA_LENGTH + INDEX_LENGTH) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE()"
			);
		} catch ( \Throwable $e ) {
			return null;
		}

		return null !== $size ? (int) $size : null;
	}

	/**
	 * {@inheritdoc}
	 *
	 * Computed from the InnoDB buffer pool's cumulative read counters:
	 * (1 − physical_reads / logical_read_requests) × 100. These are
	 * server-wide cumulative counters since the last restart, not
	 * scoped to this database alone — MySQL has one shared buffer
	 * pool across all schemas, so a per-database figure isn't
	 * meaningful here the way it is for Postgres.
	 */
	public function get_cache_hit_ratio(): ?float {
		try {
			$reads    = $this->dbal->get_row( "SHOW STATUS LIKE 'Innodb_buffer_pool_reads'" );
			$requests = $this->dbal->get_row( "SHOW STATUS LIKE 'Innodb_buffer_pool_read_requests'" );
		} catch ( \Throwable $e ) {
			return null;
		}

		$reads_val    = is_array( $reads ) ? (int) ( $reads['Value'] ?? 0 ) : null;
		$requests_val = is_array( $requests ) ? (int) ( $requests['Value'] ?? 0 ) : null;

		if ( null === $reads_val || null === $requests_val || $requests_val <= 0 ) {
			// No read activity recorded yet — the ratio is undefined,
			// not zero.
			return null;
		}

		$ratio = ( 1 - ( $reads_val / $requests_val ) ) * 100;

		return round( max( 0, min( 100, $ratio ) ), 2 );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_connection_stats(): array {
		try {
			$active_row = $this->dbal->get_row( "SHOW STATUS LIKE 'Threads_connected'" );
			$max_value  = $this->dbal->get_var( 'SELECT @@max_connections' );
		} catch ( \Throwable $e ) {
			return array( 'active' => null, 'max' => null );
		}

		$active = is_array( $active_row ) && isset( $active_row['Value'] ) ? (int) $active_row['Value'] : null;
		$max    = null !== $max_value ? (int) $max_value : null;

		return array( 'active' => $active, 'max' => $max );
	}
}