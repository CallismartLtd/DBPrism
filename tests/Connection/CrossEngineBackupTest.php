<?php
/**
 * A backup from one engine restores into every other engine.
 */

namespace Callismart\DBPrism\Tests\Connection;

use Callismart\DBPrism\Backup\BackupManager;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\Tests\Backup\BackupFixtures;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class CrossEngineBackupTest extends TestCase {

	use BackupFixtures;

	private string $dir;

	/** @var Database[] */
	private array $open = [];

	protected function setUp() : void {
		parent::setUp();

		$this->dir = sys_get_temp_dir() . '/dbprism-cross-' . bin2hex( random_bytes( 4 ) );
		mkdir( $this->dir );
	}

	protected function tearDown() : void {
		foreach ( $this->open as $db ) {
			self::wipe( $db );
			$db->close();
		}

		array_map( 'unlink', glob( $this->dir . '/*' ) ?: [] );
		@rmdir( $this->dir );

		parent::tearDown();
	}

	public static function engine_pairs() : array {
		$pairs = [];

		foreach ( [ 'sqlite', 'mysql', 'pgsql' ] as $from ) {
			foreach ( [ 'sqlite', 'mysql', 'pgsql' ] as $to ) {
				if ( $from !== $to ) {
					$pairs[ "{$from} to {$to}" ] = [ $from, $to ];
				}
			}
		}

		return $pairs;
	}

	#[DataProvider( 'engine_pairs' )]
	public function test_backup_restores_into_another_engine( string $from, string $to ) : void {
		$source = $this->open( $from, 'source' );
		$target = $this->open( $to, 'target' );

		$avatar   = self::seed( $source, 120 );
		$expected = self::dump( $source );

		( new BackupManager( $source ) )->backup( $this->dir . '/backup.dbprism.gz', [ 'batch_size' => 50 ] );
		self::wipe( $source );

		$report = ( new BackupManager( $target ) )->restore( $this->dir . '/backup.dbprism.gz' );

		$this->assertSame( $from, $report->source_engine );
		$this->assertSame( $to, $report->target_engine );
		$this->assertSame( [ 'users' => 2, 'orders' => 120, 'tags' => 2 ], $report->tables );
		$this->assertEquals( $expected, self::dump( $target ) );
		$this->assertSame( $avatar, $target->get_var( 'SELECT avatar FROM users WHERE id = 1' ) );

		// Sequences and constraints work on the new engine.
		$this->assertSame( 121, $target->insert( 'orders', [ 'user_id' => 2, 'total' => '3.50' ] ), (string) $target->get_last_error() );
		$this->assertFalse( $target->insert( 'users', [ 'email' => "o'neil@example.com", 'active' => true ] ), 'The unique constraint holds.' );

		// Default values were restored.
		$target->insert( 'orders', [ 'user_id' => 1, 'total' => '1.00' ] );
		$this->assertSame( 'new', $target->get_var( 'SELECT status FROM orders WHERE id = 122' ) );
		$target->insert( 'users', [ 'email' => 'defaults@example.com' ] );
		$this->assertNotNull( $target->get_var( "SELECT created_at FROM users WHERE email = 'defaults@example.com'" ) );
	}

	/**
	 * Connect to an engine for this test, or skip.
	 */
	private function open( string $engine, string $role ) : Database {
		$db = self::connect_engine( $engine, 'native', $this->dir . "/{$role}.sqlite" );

		if ( null === $db ) {
			$this->markTestSkipped( "{$engine} is not available." );
		}

		// Source and target may be the same server's database: clear it between roles.
		self::wipe( $db );
		$this->open[] = $db;

		return $db;
	}
}
