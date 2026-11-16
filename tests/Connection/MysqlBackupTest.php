<?php
/**
 * Backup and restore on MySQL / MariaDB, through MysqliAdapter.
 */

namespace Callismart\DBPrism\Tests\Connection;

use Callismart\DBPrism\Database;
use Callismart\DBPrism\Tests\Backup\BackupRoundTripTestCase;

class MysqlBackupTest extends BackupRoundTripTestCase {

	/**
	 * The adapter kind: native or pdo.
	 */
	protected string $kind = 'native';

	protected function connect() : ?Database {
		return self::connect_engine( 'mysql', $this->kind );
	}

	protected function connect_again() : ?Database {
		return self::connect_engine( 'mysql', $this->kind );
	}
}
