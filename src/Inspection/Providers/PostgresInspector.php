<?php
/**
 * PostgreSQL database inspector implementation
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Inspection\Providers
 */

namespace Callismart\DBPrism\Inspection\Providers;

/**
 * Inspector for PostgreSQL databases.
 * 
 * Implements schema inspection using PostgreSQL information_schema and pg_catalog queries
 * via the DatabaseAdapterInterface.
 */
class PostgresInspector extends AbstractInspector {

	/**
	 * Normalize PostgreSQL column types.
	 * 
	 * Converts types like "character varying", "integer[]" to normalized forms.
	 */
	protected function normalize_type( string $type ): string {
		$type = trim( strtolower( $type ) );

		// Remove array notation
		$type = preg_replace( '/\[\]$/', '', $type );

		// Normalize PostgreSQL-specific types
		$type_map = array(
			'character varying' => 'varchar',
			'character'         => 'char',
			'smallint'          => 'smallint',
			'integer'           => 'int',
			'bigint'            => 'bigint',
			'real'              => 'float',
			'double precision'  => 'double',
			'numeric'           => 'decimal',
			'decimal'           => 'decimal',
			'boolean'           => 'boolean',
			'text'              => 'text',
			'bytea'             => 'bytea',
			'date'              => 'date',
			'time'              => 'time',
			'timestamp'         => 'timestamp',
			'timestamp without time zone' => 'timestamp',
			'timestamp with time zone'    => 'timestamptz',
			'time without time zone'      => 'time',
			'time with time zone'         => 'timetz',
			'interval'          => 'interval',
			'uuid'              => 'uuid',
			'json'              => 'json',
			'jsonb'             => 'jsonb',
		);

		return $type_map[ $type ] ?? $type;
	}

	/**
	 * {@inheritdoc}
	 */
	public function has_index( string $table, string $index_name ) : bool {
		$sql	= "SELECT i.relname AS index_name FROM pg_class t JOIN pg_index ix 
			ON t.oid = ix.indrelid JOIN pg_class i ON i.oid = ix.indexrelid 
			WHERE t.relname = ? AND i.relname = ? LIMIT 1;";
		
		$result	= $this->dbal->get_var( $sql, [$table, $index_name ] );
		
		return $result ? true : false;
	}

	/**
	 * Get all tables in the database.
	 */
	protected function sql_all_tables(): string {
		return "SELECT table_name FROM information_schema.tables WHERE table_schema = 'public' AND table_type = 'BASE TABLE' ORDER BY table_name";
	}

	/**
	 * Check if a table exists.
	 */
	protected function sql_table_exists( string $table ): string {
		return sprintf(
			"SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = '%s' LIMIT 1",
			addslashes( $table )
		);
	}

	/**
	 * Check if a column exists.
	 */
	protected function sql_column_exists( string $table, string $column ): string {
		return sprintf(
			"SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = '%s' AND column_name = '%s' LIMIT 1",
			addslashes( $table ),
			addslashes( $column )
		);
	}

	/**
	 * Get column details.
	 */
	protected function sql_column_details( string $table ): string {
		return sprintf(
			"SELECT 
				c.column_name,
				c.udt_name as column_type,
				c.is_nullable,
				c.column_default,
				CASE WHEN pg_get_serial_sequence(t.table_name, c.column_name) IS NOT NULL THEN true ELSE false END as is_auto_increment
			FROM information_schema.columns c
			JOIN information_schema.tables t ON c.table_name = t.table_name AND c.table_schema = t.table_schema
			WHERE c.table_schema = 'public' AND c.table_name = '%s'
			ORDER BY c.ordinal_position",
			addslashes( $table )
		);
	}

	/**
	 * Get indexes.
	 */
	protected function sql_indexes( string $table ): string {
		return sprintf(
			"SELECT 
				i.indexname as index_name,
				a.attname as column_name,
				NOT ix.indisunique as is_unique,
				a.attnum as seq_in_index
			FROM pg_indexes pi
			JOIN pg_class t ON t.relname = pi.tablename
			JOIN pg_class i ON i.relname = pi.indexname
			JOIN pg_index ix ON ix.indexrelid = i.oid
			JOIN pg_attribute a ON a.attrelid = t.oid AND a.attnum = ANY(ix.indkey)
			WHERE pi.schemaname = 'public' AND pi.tablename = '%s' AND pi.indexname NOT LIKE '%%_pkey'
			ORDER BY pi.indexname, a.attnum",
			addslashes( $table )
		);
	}

	/**
	 * Get primary key.
	 */
	protected function sql_primary_key( string $table ): string {
		return sprintf(
			"SELECT 
				a.attname as column_name,
				a.attnum as seq_in_index
			FROM pg_index i
			JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = ANY(i.indkey)
			JOIN pg_class t ON t.oid = i.indrelid
			WHERE i.indisprimary AND t.relname = '%s'
			ORDER BY a.attnum",
			addslashes( $table )
		);
	}

	/**
	 * Get foreign keys.
	 */
	protected function sql_foreign_keys( string $table ): string {
		return sprintf(
			"SELECT 
				tc.constraint_name,
				kcu.column_name,
				ccu.table_name AS referenced_table,
				ccu.column_name AS referenced_column
			FROM information_schema.table_constraints AS tc
			JOIN information_schema.key_column_usage AS kcu ON tc.constraint_name = kcu.constraint_name
			JOIN information_schema.constraint_column_usage AS ccu ON ccu.constraint_name = tc.constraint_name
			WHERE tc.constraint_type = 'FOREIGN KEY' AND kcu.table_name = '%s'
			ORDER BY tc.constraint_name, kcu.ordinal_position",
			addslashes( $table )
		);
	}

	/**
	 * Get unique constraints.
	 */
	protected function sql_unique_constraints( string $table ): string {
		return sprintf(
			"SELECT 
				tc.constraint_name,
				kcu.column_name,
				kcu.ordinal_position as seq_in_index
			FROM information_schema.table_constraints tc
			JOIN information_schema.key_column_usage kcu ON tc.constraint_name = kcu.constraint_name
			WHERE tc.constraint_type = 'UNIQUE' AND kcu.table_name = '%s' AND tc.table_schema = 'public'
			ORDER BY tc.constraint_name, kcu.ordinal_position",
			addslashes( $table )
		);
	}

	/**
	 * Get check constraints.
	 */
	protected function sql_check_constraints( string $table ): string {
		return sprintf(
			"SELECT 
				constraint_name,
				check_clause as definition
			FROM information_schema.check_constraints
			WHERE constraint_schema = 'public'
			AND constraint_name IN (
				SELECT constraint_name FROM information_schema.table_constraints
				WHERE table_name = '%s' AND constraint_type = 'CHECK'
			)",
			addslashes( $table )
		);
	}

	/**
	 * Get table metadata.
	 */
	protected function sql_table_metadata( string $table ): string {
		return sprintf(
			"SELECT 
				NULL as engine,
				(SELECT datcollate FROM pg_database WHERE datname = current_database()) as charset,
				NULL as collation,
				n_live_tup as row_count,
				obj_description((SELECT oid FROM pg_class WHERE relname = '%s'), 'pg_class') as comment
			FROM pg_stat_user_tables
			WHERE relname = '%s'",
			addslashes( $table ),
			addslashes( $table )
		);
	}

	/**
	 * Override get_table_metadata to handle PostgreSQL specifics.
	 */
	public function get_table_metadata( string $table ): array {
		$rows = $this->execute_query( $this->sql_table_metadata( $table ) );

		if ( empty( $rows ) ) {
			return array();
		}

		$row = $rows[0];

		return array(
			'engine'    => null,
			'charset'   => $row['charset'] ?? null,
			'collation' => null,
			'row_count' => (int) ( $row['row_count'] ?? 0 ),
			'comment'   => $row['comment'] ?? '',
		);
	}

	/**
	 * Get protocol version.
	 */
	public function get_protocol_version() {
		return '3';
	}

	/**
	 * Get server version.
	 */
	public function get_server_version(): string {
		$rows = $this->dbal->get_results( "SHOW server_version" );
		if ( ! empty( $rows ) ) {
			return (string) $rows[0]['server_version'];
		}
		return 'unknown';
	}

	/**
	 * Get engine type.
	 */
	public function get_engine_type(): string {
		return 'pgsql';
	}

	/**
	 * Retrieve PostgreSQL information aligned with DatabaseInfoDTO keys.
	 *
	 * @return array<string, mixed>
	 */
	protected function inspect_database_info(): array {
		try {
			$row = $this->dbal->get_row(
				"
				SELECT
					version() AS full_version_string,
					current_database() AS database_name,
					current_schema() AS schema_name,
					current_setting( 'server_version' ) AS server_version,
					current_setting( 'server_encoding' ) AS charset,
					( SELECT datcollate FROM pg_database WHERE datname = current_database() ) AS collation,
					current_setting( 'TimeZone' ) AS timezone,
					current_setting( 'lc_monetary' ) AS locale,
					inet_server_addr() AS server_address,
					inet_server_port() AS server_port,
					pg_is_in_recovery() AS in_recovery,
					pg_backend_pid() AS backend_pid
				"
			);
		} catch ( \Throwable $e ) {
			$row = null;
		}

		if ( ! is_array( $row ) ) {
			try {
				$row = $this->dbal->get_row(
					'SELECT version() AS full_version_string, current_database() AS database_name, pg_backend_pid() AS backend_pid'
				);
			} catch ( \Throwable $e ) {
				$row = null;
			}
		}

		if ( ! is_array( $row ) ) {
			return array();
		}

		$full_ver    = (string) ( $row['full_version_string'] ?? '' );
		$server_os   = null;
		$server_arch = null;

		if ( preg_match( '/\(([^)]+)\)\s+on\s+([a-zA-Z0-9_\-]+)/i', $full_ver, $matches ) ) {
			$server_os   = $matches[1] ?? null;
			$server_arch = $matches[2] ?? null;
		}

		// inet_server_addr() returns NULL over a Unix socket and an address over
		// TCP — use that instead of guessing, and reuse the pid we already have
		// instead of calling pg_backend_pid() a second time.
		$transport = array_key_exists( 'server_address', $row )
			? ( ! empty( $row['server_address'] ) ? 'tcp' : 'unix_socket' )
			: null;

		$ssl = null;

		if ( ! empty( $row['backend_pid'] ) ) {
			try {
				$ssl_status = $this->dbal->get_var(
					sprintf( 'SELECT ssl FROM pg_stat_ssl WHERE pid = %d', (int) $row['backend_pid'] )
				);
				$ssl        = null !== $ssl_status ? (bool) $ssl_status : null;
			} catch ( \Throwable $e ) {
				// pg_stat_ssl doesn't exist before PostgreSQL 9.5 and may be
				// restricted by privileges — SSL status is unknown, not absent.
				$ssl = null;
			}
		}

		return array(
			'engine'              => 'pgsql',
			'product'             => 'PostgreSQL',
			'version'             => $row['server_version'] ?? null,
			'protocol_version'    => 3,
			'database'            => ! empty( $row['database_name'] ) ? $row['database_name'] : null,
			'server'              => $row['server_address'] ?? null,
			'port'                => isset( $row['server_port'] ) ? (int) $row['server_port'] : null,
			'transport'           => $transport,
			'socket'              => null,
			'path'                => null,
			'ssl'                 => $ssl,
			'charset'             => $row['charset'] ?? null,
			'collation'           => $row['collation'] ?? null,
			'timezone'            => $row['timezone'] ?? null,
			'locale'              => $row['locale'] ?? null,
			'schema'              => $row['schema_name'] ?? null,
			'server_os'           => $server_os,
			'server_architecture' => $server_arch,
			'server_hostname'     => $row['server_address'] ?? null,
			'capabilities'        => array(
				'transactions' => true,
				'foreign_keys' => true,
				'savepoints'   => true,
				'schemas'      => true,
			),
			'features'            => array(
				'in_recovery' => isset( $row['in_recovery'] ) ? (bool) $row['in_recovery'] : null,
			),
			'runtime'             => array(
				'backend_pid' => isset( $row['backend_pid'] ) ? (int) $row['backend_pid'] : null,
			),
		);
	}
}