<?php
/**
 * Backup and restore on SQLite, through PdoAdapter.
 */

namespace Callismart\DBPrism\Tests\Backup;

class PdoSqliteBackupTest extends SqliteBackupTest {

	protected string $kind = 'pdo';
}
