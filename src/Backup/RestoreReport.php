<?php
/**
 * Restore report class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup;

/**
 * What a restore did.
 *
 * @since 2.6.0
 */
final class RestoreReport {

	/**
	 * Constructor.
	 *
	 * @param string             $source_engine The engine the backup was taken from.
	 * @param string             $target_engine The engine restored into.
	 * @param array<string, int> $tables        Rows restored, by table.
	 * @param string[]           $created       Tables the restore created (or replaced).
	 * @param string             $checksum      The archive checksum, verified.
	 * @param float              $duration      Seconds taken.
	 * @param string[]           $warnings      What could not be restored exactly.
	 */
	public function __construct(
		public readonly string $source_engine,
		public readonly string $target_engine,
		public readonly array $tables,
		public readonly array $created,
		public readonly string $checksum,
		public readonly float $duration,
		public readonly array $warnings = [],
	) {}

	/**
	 * Total rows restored.
	 *
	 * @return int
	 */
	public function total_rows() : int {
		return array_sum( $this->tables );
	}

	/**
	 * The report as an array, e.g. to log or return as JSON.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array() : array {
		return [
			'source_engine' => $this->source_engine,
			'target_engine' => $this->target_engine,
			'tables'        => $this->tables,
			'rows'          => $this->total_rows(),
			'created'       => $this->created,
			'checksum'      => $this->checksum,
			'duration'      => $this->duration,
			'warnings'      => $this->warnings,
		];
	}
}
