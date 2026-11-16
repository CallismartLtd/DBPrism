<?php
/**
 * Backup and restore on SQLite, through the native SQLite3 adapter.
 */

namespace Callismart\DBPrism\Tests\Backup;

use Callismart\DBPrism\Database;

class SqliteBackupTest extends BackupRoundTripTestCase {

	/**
	 * The adapter kind: native or pdo.
	 */
	protected string $kind = 'native';

	/**
	 * A file database in WAL mode, so a second connection can write while a backup reads.
	 */
	protected function connect() : ?Database {
		$db = self::connect_engine( 'sqlite', $this->kind, $this->dir . '/test.sqlite' );
		$db?->exec( 'PRAGMA journal_mode = WAL' );

		return $db;
	}

	protected function connect_again() : ?Database {
		return self::connect_engine( 'sqlite', $this->kind, $this->dir . '/test.sqlite' );
	}
}
