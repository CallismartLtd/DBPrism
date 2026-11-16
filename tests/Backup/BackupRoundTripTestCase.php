<?php
/**
 * Backup and restore behaviour, run against one engine by each subclass.
 */

namespace Callismart\DBPrism\Tests\Backup;

use Callismart\DBPrism\Backup\Archive\ArchiveReader;
use Callismart\DBPrism\Backup\Archive\ArchiveWriter;
use Callismart\DBPrism\Backup\BackupManager;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Inspection\Inspector;
use PHPUnit\Framework\TestCase;

abstract class BackupRoundTripTestCase extends TestCase {

	use BackupFixtures;

	protected Database $db;

	protected string $dir;

	/**
	 * Connect to the database under test, or return null to skip.
	 */
	abstract protected function connect() : ?Database;

	/**
	 * A second, independent connection to the same database, or null when there is none.
	 */
	abstract protected function connect_again() : ?Database;

	protected function setUp() : void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/dbprism-backup-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );

		$db = $this->connect();

		if ( null === $db ) {
			$this->markTestSkipped( static::class . ': the database is not available.' );
		}

		$this->db = $db;
		self::wipe( $this->db );
	}

	protected function tearDown() : void {
		if ( isset( $this->db ) ) {
			self::wipe( $this->db );
			$this->db->close();
		}

		foreach ( glob( $this->dir . '/{,.}*', GLOB_BRACE ) ?: [] as $file ) {
			if ( is_file( $file ) ) {
				unlink( $file );
			}
		}

		@rmdir( $this->dir );

		parent::tearDown();
	}

	protected function manager() : BackupManager {
		return new BackupManager( $this->db );
	}

	protected function table_names() : array {
		$tables = ( new Inspector( $this->db ) )->get_all_tables();
		sort( $tables );

		return $tables;
	}

	/*
	|------------------------------
	| Round trips
	|------------------------------
	*/

	public function test_round_trip_restores_identical_rows_and_schema() : void {
		self::seed( $this->db );
		$expected = self::dump( $this->db );

		$backup = $this->manager()->backup( $this->dir . '/a.dbprism', [ 'batch_size' => 100 ] );

		$this->assertSame( [ 'users' => 2, 'orders' => 250, 'tags' => 2 ], $backup->tables );
		$this->assertTrue( $backup->consistent );
		$this->assertSame( [], $backup->warnings );

		self::wipe( $this->db );
		$restore = $this->manager()->restore( $this->dir . '/a.dbprism', [ 'batch_size' => 64 ] );

		$this->assertSame( $backup->tables, $restore->tables );
		$this->assertSame( $backup->checksum, $restore->checksum );
		$this->assertSame( [ 'users', 'orders', 'tags' ], $restore->created );
		$this->assertSame( [], $restore->warnings );
		$this->assertEquals( $expected, self::dump( $this->db ) );

		// Backing the restored database up again gives the same schema.
		$this->manager()->backup( $this->dir . '/b.dbprism' );
		$this->assertEquals( self::table_records( $this->dir . '/a.dbprism' ), self::table_records( $this->dir . '/b.dbprism' ) );
	}

	public function test_binary_data_is_restored_byte_for_byte() : void {
		$avatar = self::seed( $this->db, 1 );
		$this->manager()->backup( $this->dir . '/a.dbprism' );
		self::wipe( $this->db );

		$this->manager()->restore( $this->dir . '/a.dbprism' );

		$this->assertSame( $avatar, $this->db->get_var( 'SELECT avatar FROM users WHERE id = 1' ) );
		$this->assertNull( $this->db->get_var( 'SELECT avatar FROM users WHERE id = 2' ) );
	}

	public function test_gzip_archive_round_trip() : void {
		self::seed( $this->db, 20 );
		$expected = self::dump( $this->db );

		$this->manager()->backup( $this->dir . '/a.dbprism.gz' );

		$this->assertSame( "\x1f\x8b", substr( (string) file_get_contents( $this->dir . '/a.dbprism.gz' ), 0, 2 ) );

		self::wipe( $this->db );
		$this->manager()->restore( $this->dir . '/a.dbprism.gz' );

		$this->assertEquals( $expected, self::dump( $this->db ) );
	}

	public function test_backup_to_and_restore_from_streams() : void {
		self::seed( $this->db, 10 );
		$expected = self::dump( $this->db );
		$stream   = fopen( 'php://temp', 'w+b' );

		$this->manager()->backup( $stream );
		rewind( $stream );
		self::wipe( $this->db );

		$this->manager()->restore( $stream );

		$this->assertEquals( $expected, self::dump( $this->db ) );
		$this->assertTrue( is_resource( $stream ), 'A stream passed in is left open.' );
		fclose( $stream );
	}

	public function test_sequences_continue_after_the_restored_ids() : void {
		self::seed( $this->db, 30 );
		$this->manager()->backup( $this->dir . '/a.dbprism' );
		self::wipe( $this->db );
		$this->manager()->restore( $this->dir . '/a.dbprism' );

		$id = $this->db->insert( 'orders', [ 'user_id' => 1, 'total' => '1.00' ] );

		$this->assertSame( 31, $id, (string) $this->db->get_last_error() );
	}

	/*
	|------------------------------
	| Consistency
	|------------------------------
	*/

	public function test_backup_reads_one_snapshot_while_another_connection_writes() : void {
		$writer = $this->connect_again();

		if ( null === $writer ) {
			$this->markTestSkipped( 'No second connection to the same database.' );
		}

		self::seed( $this->db, 250 );
		$written = false;

		// Tables are read parents first: while users is being read, another
		// connection adds orders, which the backup must not see.
		$report = $this->manager()->backup( $this->dir . '/a.dbprism', [
			'batch_size'  => 1,
			'on_progress' => function ( string $table ) use ( $writer, &$written ) {
				if ( 'users' === $table && ! $written ) {
					$written = true;
					$this->assertNotFalse( $writer->insert( 'orders', [ 'user_id' => 1, 'total' => '9.99' ] ), (string) $writer->get_last_error() );
					$writer->execute( 'DELETE FROM tags' );
					$this->assertNull( $writer->get_last_error() );
				}
			},
		] );

		$writer->close();

		$this->assertTrue( $written );
		$this->assertSame( 251, (int) $this->db->get_var( 'SELECT COUNT(*) FROM orders' ), 'The concurrent write was committed.' );
		$this->assertSame( [ 'users' => 2, 'orders' => 250, 'tags' => 2 ], $report->tables, 'The backup holds the database as it was when it began.' );
	}

	public function test_inconsistent_backup_can_be_requested() : void {
		self::seed( $this->db, 5 );

		$report = $this->manager()->backup( $this->dir . '/a.dbprism', [ 'consistent' => false ] );

		$this->assertFalse( $report->consistent );
		$this->assertFalse( BackupManager::verify( $this->dir . '/a.dbprism' )['header']['consistent'] );
	}

	/*
	|------------------------------
	| Existing tables
	|------------------------------
	*/

	public function test_restore_refuses_existing_tables_and_changes_nothing() : void {
		self::seed( $this->db, 10 );
		$this->manager()->backup( $this->dir . '/a.dbprism' );
		$this->db->execute( 'DELETE FROM tags' );

		try {
			$this->manager()->restore( $this->dir . '/a.dbprism' );
			$this->fail( 'The restore should refuse existing tables.' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'already exist', $e->getMessage() );
			$this->assertStringContainsString( 'Nothing was changed', $e->getMessage() );
		}

		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM tags' ) );
		$this->assertSame( [ 'orders', 'tags', 'users' ], $this->table_names() );
	}

	public function test_replace_puts_the_backed_up_state_back() : void {
		self::seed( $this->db, 40 );
		$expected = self::dump( $this->db );
		$this->manager()->backup( $this->dir . '/a.dbprism' );

		$this->db->execute( 'DELETE FROM tags' );
		$this->db->execute( "UPDATE users SET name = 'changed'" );
		$this->db->insert( 'orders', [ 'user_id' => 2, 'total' => '5.00' ] );

		$report = $this->manager()->restore( $this->dir . '/a.dbprism', [ 'existing' => 'replace' ] );

		$this->assertSame( [ 'users', 'orders', 'tags' ], $report->created );
		$this->assertEquals( $expected, self::dump( $this->db ) );
		$this->assertSame( [ 'orders', 'tags', 'users' ], $this->table_names(), 'No staging or retired tables are left.' );
	}

	public function test_replace_keeps_foreign_keys_of_tables_outside_the_backup() : void {
		if ( 'sqlite' === $this->db->get_driver() ) {
			// Enforced, so replacing users could cascade into notes if the restore let it.
			$this->db->exec( 'PRAGMA foreign_keys = ON' );
		}

		self::seed( $this->db, 4 );
		$this->assertTrue( $this->db->exec(
			'CREATE TABLE notes ( id INTEGER PRIMARY KEY, user_id BIGINT NOT NULL,'
			. ' CONSTRAINT notes_user_fk FOREIGN KEY ( user_id ) REFERENCES users ( id ) ON DELETE CASCADE )'
		), (string) $this->db->get_last_error() );
		$this->db->execute( 'INSERT INTO notes ( id, user_id ) VALUES ( 1, 2 )' );

		$this->manager()->backup( $this->dir . '/a.dbprism', [ 'exclude' => [ 'notes' ] ] );
		$this->manager()->restore( $this->dir . '/a.dbprism', [ 'existing' => 'replace' ] );

		$this->assertSame( 1, (int) $this->db->get_var( 'SELECT COUNT(*) FROM notes' ), 'Rows of other tables are kept.' );

		$foreign_keys = ( new Inspector( $this->db ) )->get_foreign_keys( 'notes' );
		$this->assertSame( 'users', reset( $foreign_keys )['referenced_table'] ?? null, 'Their foreign keys still reference the restored table.' );

		$this->db->execute( 'DELETE FROM users WHERE id = 2' );
		$this->assertNull( $this->db->get_last_error() );
		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM notes' ), 'And still act on it.' );

		$this->db->exec( 'DROP TABLE notes' );
	}

	public function test_append_inserts_rows_into_existing_tables() : void {
		self::seed( $this->db, 12 );
		$expected = self::dump( $this->db );
		$this->manager()->backup( $this->dir . '/a.dbprism' );

		foreach ( array_reverse( self::$fixture_tables ) as $table ) {
			$this->db->execute( "DELETE FROM {$table}" );
		}

		$report = $this->manager()->restore( $this->dir . '/a.dbprism', [ 'existing' => 'append' ] );

		$this->assertSame( [], $report->created );
		$this->assertEquals( $expected, self::dump( $this->db ) );
	}

	/*
	|------------------------------
	| Failures roll back
	|------------------------------
	*/

	public function test_damaged_archive_is_refused_and_the_database_left_as_it_was() : void {
		self::seed( $this->db, 30 );
		$this->manager()->backup( $this->dir . '/a.dbprism' );
		$expected = self::dump( $this->db );

		// Alter one value: the archive still parses, but no longer matches its checksum.
		$lines = self::archive_lines( $this->dir . '/a.dbprism' );
		$index = array_key_last( array_filter( $lines, fn( $line ) => str_contains( $line, '"kind":"rows","table":"orders"' ) ) );
		$lines[ $index ] = str_replace( '"paid"', '"PAID"', $lines[ $index ] );
		file_put_contents( $this->dir . '/damaged.dbprism', implode( "\n", $lines ) . "\n" );

		try {
			$this->manager()->restore( $this->dir . '/damaged.dbprism', [ 'existing' => 'replace' ] );
			$this->fail( 'A damaged archive should be refused.' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'checksum', $e->getMessage() );
		}

		$this->assertEquals( $expected, self::dump( $this->db ) );
		$this->assertSame( [ 'orders', 'tags', 'users' ], $this->table_names() );
	}

	public function test_truncated_archive_is_refused_and_creates_nothing() : void {
		self::seed( $this->db, 30 );
		$this->manager()->backup( $this->dir . '/a.dbprism' );
		self::wipe( $this->db );

		$lines = self::archive_lines( $this->dir . '/a.dbprism' );
		file_put_contents( $this->dir . '/cut.dbprism', implode( "\n", array_slice( $lines, 0, -3 ) ) . "\n" );

		try {
			$this->manager()->restore( $this->dir . '/cut.dbprism' );
			$this->fail( 'A truncated archive should be refused.' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'incomplete', $e->getMessage() );
		}

		$this->assertSame( [], $this->table_names() );
	}

	public function test_rows_the_database_refuses_roll_the_restore_back() : void {
		self::seed( $this->db, 10 );
		$this->manager()->backup( $this->dir . '/a.dbprism' );
		self::wipe( $this->db );

		// A well-formed archive with a duplicate primary key in orders.
		$reader = ArchiveReader::open( $this->dir . '/a.dbprism' );
		$writer = ArchiveWriter::open( $this->dir . '/duplicate.dbprism' );
		$writer->write( $reader->header() );

		foreach ( $reader->records() as $record ) {
			if ( 'rows' === $record['kind'] && 'orders' === $record['table'] ) {
				$record['rows'][] = $record['rows'][0];
			}

			if ( 'table_end' === $record['kind'] && 'orders' === $record['table'] ) {
				$record['rows']++;
			}

			$writer->write( $record );
		}

		$writer->finish();
		$reader->close();

		try {
			$this->manager()->restore( $this->dir . '/duplicate.dbprism' );
			$this->fail( 'The duplicate key should fail the restore.' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'rolled back', $e->getMessage() );
		}

		$this->assertSame( [], $this->table_names(), 'No table, staged or final, is left.' );
	}

	public function test_failed_backup_leaves_no_file() : void {
		self::seed( $this->db, 3 );

		try {
			$this->manager()->backup( $this->dir . '/a.dbprism', [ 'tables' => [ 'users', 'missing' ] ] );
			$this->fail( 'Backing up a missing table should fail.' );
		} catch ( BackupException $e ) {
			$this->assertStringContainsString( 'missing', $e->getMessage() );
		}

		$this->assertSame( [], glob( $this->dir . '/{,.}*.dbprism*', GLOB_BRACE ) ?: [] );
	}

	/*
	|------------------------------
	| Options
	|------------------------------
	*/

	public function test_schema_only_backup_restores_empty_tables() : void {
		self::seed( $this->db, 5 );

		$report = $this->manager()->backup( $this->dir . '/a.dbprism', [ 'data' => false ] );
		$this->assertSame( [ 'users' => 0, 'orders' => 0, 'tags' => 0 ], $report->tables );

		self::wipe( $this->db );
		$this->manager()->restore( $this->dir . '/a.dbprism' );

		$this->assertSame( [ 'orders', 'tags', 'users' ], $this->table_names() );
		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM users' ) );
	}

	public function test_schema_only_restore_of_a_full_backup() : void {
		self::seed( $this->db, 5 );
		$this->manager()->backup( $this->dir . '/a.dbprism' );
		self::wipe( $this->db );

		$progress = [];
		$report   = $this->manager()->restore( $this->dir . '/a.dbprism', [
			'data'        => false,
			'on_progress' => function ( string $table, int $rows ) use ( &$progress ) {
				$progress[] = $table;
			},
		] );

		$this->assertSame( [ 'users' => 0, 'orders' => 0, 'tags' => 0 ], $report->tables );
		$this->assertSame( [], $progress );
		$this->assertSame( 0, (int) $this->db->get_var( 'SELECT COUNT(*) FROM orders' ) );
	}

	public function test_restore_reports_progress_by_rows_inserted() : void {
		self::seed( $this->db, 25 );
		$this->manager()->backup( $this->dir . '/a.dbprism' );
		self::wipe( $this->db );

		$progress = [];
		$this->manager()->restore( $this->dir . '/a.dbprism', [
			'batch_size'  => 10,
			'on_progress' => function ( string $table, int $rows ) use ( &$progress ) {
				$progress[ $table ][] = $rows;
			},
		] );

		$this->assertSame( [ 10, 20, 25 ], $progress['orders'] );
		$this->assertSame( [ 2 ], $progress['users'] );
	}

	public function test_tables_and_exclude_choose_what_is_backed_up_and_restored() : void {
		self::seed( $this->db, 5 );

		$report = $this->manager()->backup( $this->dir . '/a.dbprism', [ 'exclude' => [ 'tags' ] ] );
		$this->assertSame( [ 'users', 'orders' ], array_keys( $report->tables ) );

		self::wipe( $this->db );
		$restore = $this->manager()->restore( $this->dir . '/a.dbprism', [ 'tables' => [ 'users' ] ] );

		$this->assertSame( [ 'users' => 2 ], $restore->tables );
		$this->assertSame( [ 'users' ], $this->table_names() );
	}

	public function test_verify_reads_the_whole_archive() : void {
		self::seed( $this->db, 7 );
		$report = $this->manager()->backup( $this->dir . '/a.dbprism' );

		$verified = BackupManager::verify( $this->dir . '/a.dbprism' );

		$this->assertSame( $report->tables, $verified['tables'] );
		$this->assertSame( $report->checksum, $verified['checksum'] );
		$this->assertSame( $this->db->get_driver(), $verified['header']['source']['engine'] );
	}
}
