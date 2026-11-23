<?php
/**
 * Backup report class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup;

/**
 * What a backup wrote.
 *
 * @since 2.6.0
 */
final class BackupReport {

	/**
	 * Constructor.
	 *
	 * @param string             $engine     The engine backed up: mysql, pgsql or sqlite.
	 * @param array<string, int> $tables     Rows written, by table, in archive order.
	 * @param bool               $consistent Whether every table was read from one snapshot.
	 * @param bool               $data       Whether rows were included (false: schema only).
	 * @param string             $checksum   SHA-256 of the archive, as its footer records it.
	 * @param int                $bytes      Archive size before compression.
	 * @param float              $duration   Seconds taken.
	 * @param string[]           $warnings   What could not be backed up exactly.
	 */
	public function __construct(
		public readonly string $engine,
		public readonly array $tables,
		public readonly bool $consistent,
		public readonly bool $data,
		public readonly string $checksum,
		public readonly int $bytes,
		public readonly float $duration,
		public readonly array $warnings = [],
	) {}

	/**
	 * Total rows written.
	 *
	 * @return int
	 */
	public function total_rows() : int {
		return array_sum( $this->tables );
	}

	/**
	 * The report as an array, e.g. to log or return as JSON.
	 *
	 * @return array{engine: string, tables: array<string, int>, rows: int, consistent: bool, data: bool, checksum: string, bytes: int, duration: float, warnings: string[]}
	 */
	public function to_array() : array {
		return [
			'engine'     => $this->engine,
			'tables'     => $this->tables,
			'rows'       => $this->total_rows(),
			'consistent' => $this->consistent,
			'data'       => $this->data,
			'checksum'   => $this->checksum,
			'bytes'      => $this->bytes,
			'duration'   => $this->duration,
			'warnings'   => $this->warnings,
		];
	}
}
