<?php
/**
 * Backup and restore on MySQL / MariaDB, through PdoAdapter.
 */

namespace Callismart\DBPrism\Tests\Connection;

class PdoMysqlBackupTest extends MysqlBackupTest {

	protected string $kind = 'pdo';
}
