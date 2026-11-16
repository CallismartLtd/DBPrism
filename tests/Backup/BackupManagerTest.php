<?php
/**
 * BackupManager options and the portable table record.
 */

namespace Callismart\DBPrism\Tests\Backup;

use Callismart\DBPrism\Adapters\NullDBAdapter;
use Callismart\DBPrism\Backup\BackupManager;
use Callismart\DBPrism\Backup\TableSchema;
use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\DefaultColumnValue;
use Callismart\DBPrism\Utils\Table;
use PHPUnit\Framework\TestCase;

class BackupManagerTest extends TestCase {

	use BackupFixtures;

	public function test_unknown_options_are_refused() : void {
		$db = self::connect_engine( 'sqlite' ) ?? $this->markTestSkipped( 'sqlite3 is not available.' );

		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'Unknown option(s): tabels' );

		( new BackupManager( $db ) )->backup( 'php://memory', [ 'tabels' => [ 'users' ] ] );
	}

	public function test_existing_must_be_a_known_mode() : void {
		$db = self::connect_engine( 'sqlite' ) ?? $this->markTestSkipped( 'sqlite3 is not available.' );

		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( '"existing"' );

		( new BackupManager( $db ) )->restore( 'php://memory', [ 'existing' => 'overwrite' ] );
	}

	public function test_a_null_adapter_cannot_be_backed_up() : void {
		$this->expectException( BackupException::class );
		$this->expectExceptionMessage( 'NullDBAdapter' );

		( new BackupManager( new Database( new NullDBAdapter() ) ) )->backup( 'php://memory' );
	}

	public function test_a_table_record_round_trips() : void {
		$table = Table::make( 'items' )
			->add_columns( [
				Column::make( 'id' )->type( ColumnType::BIG_INT )->unsigned()->auto_increment()->required(),
				Column::make( 'code' )->type( ColumnType::CHAR )->size( 3 )->required()->comment( 'ISO code' ),
				Column::make( 'price' )->type( ColumnType::DECIMAL )->size( 8, 3 )->default( DefaultColumnValue::make( '0.000' ) ),
				Column::make( 'seen' )->type( ColumnType::DATETIME )->default( DefaultColumnValue::expression( 'CURRENT_TIMESTAMP' ) ),
			] )
			->add_constraints( [
				Constraint::primary()->on( 'id' ),
				Constraint::unique( 'items_code_uq' )->on( 'code' ),
				Constraint::foreign_key( 'items_code_fk' )->on( 'code' )->references( 'codes', 'code' )->on_delete( 'SET NULL' ),
			] );

		$schema = new TableSchema( $table, 'mysql', [ 'code' => 'uuid()' ], [ [ 'name' => 'positive', 'definition' => 'price >= 0' ] ] );
		$record = json_decode( json_encode( $schema->to_record() ), true );
		$again  = TableSchema::from_record( $record );

		$this->assertSame( $record, $again->to_record() );
		$this->assertSame( 'BIG_INT', $record['columns'][0]['type'] );
		$this->assertSame( [ 'expression' => 'CURRENT_TIMESTAMP' ], $record['columns'][3]['default'] );
		$this->assertSame( [ 'code' => 'uuid()' ], $again->native_defaults );
		$this->assertSame( 'SET NULL', $again->foreign_keys()[0]->on_delete );
	}

	public function test_a_malformed_table_record_is_refused() : void {
		$this->expectException( BackupException::class );

		TableSchema::from_record( [ 'kind' => 'table', 'name' => 't', 'columns' => [ [ 'name' => 'c', 'type' => 'NOPE' ] ] ] );
	}
}
