<?php
/**
 * Migration ledger class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations;

use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\MigrationException;
use Callismart\DBPrism\Exceptions\MigrationLockedException;
use Callismart\DBPrism\Inspection\Inspector;
use Callismart\DBPrism\Migrations\Operators\TableOperator;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use InvalidArgumentException;

/**
 * The record a database keeps of the migrations applied to it.
 *
 * Two tables, named with a prefix so that several applications can share a
 * database:
 *
 *   {prefix}migrations       one row per applied migration: its class name,
 *                            the run that applied it, and when (UTC).
 *   {prefix}migrations_lock  at most one row, held while a run is in progress.
 *
 * Both are created on first use, and are safe to create again.
 *
 * @since 0.2.0
 */
class MigrationLedger {

	/**
	 * Longest migration name the ledger accepts.
	 *
	 * 191 characters keep the unique index within the 767-byte index limit
	 * of older MySQL row formats in utf8mb4.
	 *
	 * @var int
	 */
	public const MAX_NAME_LENGTH = 191;

	/**
	 * The ledger table name.
	 *
	 * @var string
	 */
	private string $table;

	/**
	 * The lock table name.
	 *
	 * @var string
	 */
	private string $lock_table;

	/**
	 * This process's lock token, while it holds the lock.
	 *
	 * @var string|null
	 */
	private ?string $token = null;

	/**
	 * Constructor.
	 *
	 * @param Database $db     The database whose migrations are recorded.
	 * @param string   $prefix Table name prefix: letters, digits and underscores.
	 * @throws InvalidArgumentException When the prefix has other characters.
	 */
	public function __construct( private Database $db, string $prefix = 'dbprism_' ) {
		if ( ! preg_match( '/^[A-Za-z0-9_]*$/', $prefix ) ) {
			throw new InvalidArgumentException( sprintf( 'Invalid migration table prefix "%s": use letters, digits and underscores.', $prefix ) );
		}

		$this->table      = $prefix . 'migrations';
		$this->lock_table = $prefix . 'migrations_lock';
	}

	/**
	 * The ledger table name.
	 *
	 * @return string
	 */
	public function get_table() : string {
		return $this->table;
	}

	/**
	 * Create the ledger and lock tables, when they do not exist.
	 *
	 * @return void
	 * @throws MigrationException When a table cannot be created.
	 */
	public function ensure() : void {
		( new TableOperator( $this->db, $this->table ) )->create( function ( $table ) {
			$table
				->add_column( Column::make( 'id' )->type( ColumnType::INTEGER )->auto_increment() )
				->add_column( Column::make( 'migration' )->type( ColumnType::STRING )->size( self::MAX_NAME_LENGTH )->required() )
				->add_column( Column::make( 'run' )->type( ColumnType::INTEGER )->required() )
				->add_column( Column::make( 'applied_at' )->type( ColumnType::STRING )->size( 19 )->required() )
				->add_constraint( Constraint::unique( 'uq_' . $this->table . '_migration' )->on( 'migration' ) );
		} );

		( new TableOperator( $this->db, $this->lock_table ) )->create( function ( $table ) {
			$table
				->add_column( Column::make( 'id' )->type( ColumnType::INTEGER )->required() )
				->add_column( Column::make( 'owner' )->type( ColumnType::STRING )->size( 64 )->required() )
				->add_column( Column::make( 'acquired_at' )->type( ColumnType::BIG_INT )->required() )
				->add_constraint( Constraint::primary()->on( 'id' ) );
		} );
	}

	/**
	 * Whether the ledger table exists.
	 *
	 * @return bool
	 * @throws MigrationException When the database cannot be asked, e.g. it is
	 *                            unreachable: that is not the same as "nothing applied".
	 */
	public function exists() : bool {
		if ( ( new Inspector( $this->db ) )->table_exists( $this->table ) ) {
			return true;
		}

		$error = $this->db->get_last_error();

		if ( null !== $error ) {
			throw new MigrationException( sprintf( 'Reading the migration ledger failed: %s', $error ) );
		}

		return false;
	}

	/**
	 * The applied migrations, oldest first.
	 *
	 * @return array<string, array{run: int, applied_at: string}> Keyed by migration name.
	 */
	public function applied() : array {
		if ( ! $this->exists() ) {
			return [];
		}

		$applied = [];

		foreach ( $this->db->get_results( 'SELECT migration, run, applied_at FROM ' . $this->table . ' ORDER BY id' ) as $row ) {
			$applied[ $row['migration'] ] = [
				'run'        => (int) $row['run'],
				'applied_at' => (string) $row['applied_at'],
			];
		}

		return $applied;
	}

	/**
	 * The number of the most recent run, or 0 when nothing is applied.
	 *
	 * @return int
	 */
	public function last_run() : int {
		$runs = array_column( $this->applied(), 'run' );

		return empty( $runs ) ? 0 : max( $runs );
	}

	/**
	 * Record a migration as applied.
	 *
	 * @param string $migration The migration's class name.
	 * @param int    $run       The run that applied it.
	 * @return void
	 * @throws MigrationException When it cannot be recorded.
	 */
	public function record( string $migration, int $run ) : void {
		$inserted = $this->db->insert( $this->table, [
			'migration'  => $migration,
			'run'        => $run,
			'applied_at' => gmdate( 'Y-m-d H:i:s' ),
		] );

		if ( false === $inserted ) {
			throw new MigrationException(
				sprintf( 'Recording migration "%s" as applied failed: %s', $migration, $this->db->get_last_error() ?? 'unknown error' )
			);
		}
	}

	/**
	 * Remove a migration's record, after it has been reverted.
	 *
	 * @param string $migration The migration's class name.
	 * @return void
	 * @throws MigrationException When the record cannot be removed.
	 */
	public function remove( string $migration ) : void {
		if ( false === $this->db->delete( $this->table, [ 'migration' => $migration ] ) ) {
			throw new MigrationException(
				sprintf( 'Removing the record of migration "%s" failed: %s', $migration, $this->db->get_last_error() ?? 'unknown error' )
			);
		}
	}

	/*
	|------
	| Lock
	|------
	*/

	/**
	 * Take the lock, so no other run starts until it is released.
	 *
	 * A lock older than $expires_after seconds is taken over: the run that
	 * held it is assumed to have died without releasing it.
	 *
	 * @param int $expires_after Seconds after which a held lock is considered abandoned.
	 * @return void
	 * @throws MigrationLockedException When another run holds the lock.
	 * @throws MigrationException       When the lock cannot be read or written.
	 */
	public function lock( int $expires_after ) : void {
		$held = $this->db->get_row( 'SELECT owner, acquired_at FROM ' . $this->lock_table . ' WHERE id = 1' );

		if ( null !== $held ) {
			$age = time() - (int) $held['acquired_at'];

			if ( $age < $expires_after ) {
				throw new MigrationLockedException(
					sprintf( 'Migrations are already running (started %d second(s) ago); try again later.', max( 0, $age ) )
				);
			}

			// Abandoned: remove exactly that lock, not one another run has just taken.
			$this->db->delete( $this->lock_table, [ 'id' => 1, 'owner' => $held['owner'] ] );
		}

		$token    = bin2hex( random_bytes( 16 ) );
		$inserted = $this->db->insert( $this->lock_table, [
			'id'          => 1,
			'owner'       => $token,
			'acquired_at' => time(),
		] );

		if ( false === $inserted ) {
			// Another run took it between the check and the insert.
			throw new MigrationLockedException( 'Migrations are already running; try again later.' );
		}

		$this->token = $token;
	}

	/**
	 * Release the lock, when this process holds it.
	 *
	 * @return void
	 */
	public function unlock() : void {
		if ( null === $this->token ) {
			return;
		}

		$this->db->delete( $this->lock_table, [ 'id' => 1, 'owner' => $this->token ] );
		$this->token = null;
	}
}