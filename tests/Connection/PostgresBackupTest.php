<?php
/**
 * Backup and restore on PostgreSQL, through PostgresAdapter.
 */

namespace Callismart\DBPrism\Tests\Connection;

use Callismart\DBPrism\Database;
use Callismart\DBPrism\Tests\Backup\BackupRoundTripTestCase;

class PostgresBackupTest extends BackupRoundTripTestCase {

	protected function connect() : ?Database {
		return self::connect_engine( 'pgsql' );
	}

	protected function connect_again() : ?Database {
		return self::connect_engine( 'pgsql' );
	}
}
