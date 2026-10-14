<?php
/**
 * Migrator class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations;

use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\MigrationException;
use Callismart\DBPrism\Exceptions\MigrationLockedException;
use Callismart\DBPrism\Migrations\Contracts\MigrationInterface;
use Callismart\DBPrism\Migrations\Contracts\ReversibleMigrationInterface;
use InvalidArgumentException;
use Throwable;

/**
 * Applies and reverts an application's migrations.
 *
 * The application registers its migrations as one ordered list of class
 * names. That list is the whole definition: a migration's name is its class
 * name, and its order is its place in the list. Nothing is discovered by
 * scanning directories or reading file names.
 *
 *   $migrator = new Migrator( $db, [
 *       CreateLicensesTable::class,
 *       AddLicenseKeyIndex::class,
 *   ] );
 *
 *   $migrator->migrate();   // applies what is not yet applied, in list order
 *   $migrator->revert();    // undoes the most recent run
 *   $migrator->status();    // what is applied and what is pending
 *   $migrator->baseline();  // on a fresh installation: records all as applied
 *
 * Rules:
 *
 *  - New migrations go at the end of the list. A pending migration listed
 *    before an applied one is refused, since it could not run in the order
 *    the list gives.
 *  - Each migration is recorded only after its up() succeeds. The first
 *    failure stops the run; the migrations before it stay applied. Running
 *    again resumes at the one that failed, whose finished steps the
 *    operators skip.
 *  - A migration is not run inside a transaction: on MySQL every schema
 *    change commits on its own, and SQLite cannot rebuild a table inside
 *    one while foreign keys are enforced. Each operator step is safe to
 *    repeat; raw exec() and data steps should be written to be too.
 *  - Every migrate() and revert() call is one run, numbered in the ledger.
 *    revert() undoes the most recent run, newest migration first, and only
 *    when every migration in it is reversible.
 *  - One run at a time: a run holds a lock, and a second one started
 *    meanwhile throws MigrationLockedException without changing anything.
 *  - A fresh installation that creates the current schema directly calls
 *    baseline() instead of migrate(): every registered migration is recorded
 *    as applied (run 0, never reverted) without running.
 *
 * @since 0.2.0
 */
class Migrator {

	/**
	 * Run number of the migrations a baseline records.
	 */
	public const BASELINE_RUN = 0;

	/**
	 * The registered migration class names, in order.
	 *
	 * @var string[]
	 */
	private array $migrations;

	/**
	 * The ledger.
	 *
	 * @var MigrationLedger
	 */
	private MigrationLedger $ledger;

	/**
	 * Builds a migration that does not extend AbstractMigration.
	 *
	 * @var callable|null
	 */
	private $factory;

	/**
	 * Constructor.
	 *
	 * @param Database      $db            The database to migrate.
	 * @param string[]      $migrations    Migration class names, in the order they apply.
	 * @param string        $prefix        Prefix of the ledger tables (letters, digits, underscores).
	 * @param callable|null $factory       fn( string $class, Database $db ): MigrationInterface —
	 *                                     builds migrations that do not extend AbstractMigration.
	 *                                     Those that do are built by their final constructor.
	 * @param int           $lock_timeout  Seconds after which a run's lock is considered
	 *                                     abandoned (its process died) and may be taken over.
	 * @throws InvalidArgumentException When the list is not a list of distinct migration classes.
	 */
	public function __construct(
		private Database $db,
		array $migrations,
		string $prefix = 'dbprism_',
		?callable $factory = null,
		private int $lock_timeout = 3600
	) {
		$this->migrations = $this->validate( $migrations );
		$this->ledger     = new MigrationLedger( $db, $prefix );
		$this->factory    = $factory;
	}

	/**
	 * Apply every registered migration that is not applied yet, in list order.
	 *
	 * @return string[] The migrations applied by this run, in order.
	 * @throws MigrationLockedException When another run is in progress; nothing was changed.
	 * @throws MigrationException       When the order is broken (nothing was changed), or a
	 *                                  migration fails (those before it stay applied).
	 */
	public function migrate() : array {
		return $this->locked( function () {
			$applied = $this->ledger->applied();
			$pending = $this->pending_in( $applied );

			if ( empty( $pending ) ) {
				return [];
			}

			$this->check_order( $applied, $pending );

			$run  = $this->ledger->last_run() + 1;
			$done = [];

			foreach ( $pending as $class ) {
				try {
					$this->build( $class )->up();
				} catch ( Throwable $e ) {
					throw new MigrationException(
						sprintf( 'Migration %s failed: %s', $class, $e->getMessage() ),
						0,
						$e
					);
				}

				$this->ledger->record( $class, $run );
				$done[] = $class;
			}

			return $done;
		} );
	}

	/**
	 * Record every registered migration as applied, without running it.
	 *
	 * For a fresh installation whose schema was created at the current
	 * release: that schema already contains every registered migration's
	 * change, so none of them may run on it later. After the baseline,
	 * migrate() runs only migrations registered by later releases.
	 *
	 * Only a database the Migrator has never touched can be baselined: one
	 * without a ledger table. The baseline creates the table even when no
	 * migration is registered, and migrate() creates it on every call, so
	 * once either has run, baseline() refuses. That keeps it from marking a
	 * migration an installation still needs as applied.
	 *
	 * The baseline is recorded as run 0, which revert() does not undo: its
	 * changes were made by the installation, not by the migrations' up().
	 *
	 * @return string[] The migrations recorded, in list order.
	 * @throws MigrationLockedException When another run is in progress; nothing was changed.
	 * @throws MigrationException       When the database has a ledger already (nothing was
	 *                                  changed), or a migration cannot be recorded.
	 */
	public function baseline() : array {
		if ( $this->ledger->exists() ) {
			throw new MigrationException(
				sprintf( 'Cannot baseline: the migration ledger %s already exists, so migrations have been managed on this database before.', $this->ledger->get_table() )
			);
		}

		return $this->locked( function () {
			// Another baseline may have recorded between the check and the lock.
			if ( ! empty( $this->ledger->applied() ) ) {
				throw new MigrationException( 'Cannot baseline: migrations were recorded on this database meanwhile.' );
			}

			foreach ( $this->migrations as $class ) {
				$this->ledger->record( $class, self::BASELINE_RUN );
			}

			return $this->migrations;
		} );
	}

	/**
	 * Undo the most recent run, newest migration first.
	 *
	 * Every migration in the run must be registered and reversible, or
	 * nothing is reverted. Each is removed from the ledger only after its
	 * down() succeeds; the first failure stops the revert, leaving that
	 * migration and the ones before it applied.
	 *
	 * @return string[] The migrations reverted, newest first.
	 * @throws MigrationLockedException When another run is in progress; nothing was changed.
	 * @throws MigrationException       When the run cannot be reverted (nothing was changed),
	 *                                  or a down() fails.
	 */
	public function revert() : array {
		return $this->locked( function () {
			$applied = $this->ledger->applied();
			$run     = $this->ledger->last_run();

			// Nothing applied, or only the baseline, which is never reverted.
			if ( self::BASELINE_RUN === $run ) {
				return [];
			}

			$targets = array_reverse( array_keys( array_filter( $applied, fn( $entry ) => $run === $entry['run'] ) ) );

			$unregistered = array_diff( $targets, $this->migrations );

			if ( ! empty( $unregistered ) ) {
				throw new MigrationException(
					sprintf( 'Cannot revert run %d: these migrations are no longer registered: %s.', $run, implode( ', ', $unregistered ) )
				);
			}

			// Build them all before reverting any: what is checked is the object
			// down() will be called on, which a factory may build as another class.
			$reversible   = [];
			$irreversible = [];

			foreach ( $targets as $class ) {
				try {
					$migration = $this->build( $class );
				} catch ( Throwable $e ) {
					throw new MigrationException(
						sprintf( 'Cannot revert run %d: building migration %s failed: %s', $run, $class, $e->getMessage() ),
						0,
						$e
					);
				}

				if ( $migration instanceof ReversibleMigrationInterface ) {
					$reversible[ $class ] = $migration;
				} else {
					$irreversible[] = $class;
				}
			}

			if ( ! empty( $irreversible ) ) {
				throw new MigrationException(
					sprintf( 'Cannot revert run %d: these migrations are not reversible: %s.', $run, implode( ', ', $irreversible ) )
				);
			}

			$done = [];

			foreach ( $reversible as $class => $migration ) {
				try {
					$migration->down();
				} catch ( Throwable $e ) {
					throw new MigrationException(
						sprintf( 'Reverting migration %s failed: %s', $class, $e->getMessage() ),
						0,
						$e
					);
				}

				$this->ledger->remove( $class );
				$done[] = $class;
			}

			return $done;
		} );
	}

	/**
	 * What is applied and what is pending. Changes nothing.
	 *
	 * Registered migrations come first, in list order, then any the ledger
	 * records that are no longer registered.
	 *
	 * @return array<string, array{registered: bool, applied: bool, run: int|null, applied_at: string|null}>
	 *         Keyed by migration name; applied_at is UTC, 'Y-m-d H:i:s'.
	 */
	public function status() : array {
		$applied = $this->ledger->applied();
		$status  = [];

		foreach ( [ ...$this->migrations, ...array_diff( array_keys( $applied ), $this->migrations ) ] as $class ) {
			$status[ $class ] = [
				'registered' => in_array( $class, $this->migrations, true ),
				'applied'    => isset( $applied[ $class ] ),
				'run'        => $applied[ $class ]['run'] ?? null,
				'applied_at' => $applied[ $class ]['applied_at'] ?? null,
			];
		}

		return $status;
	}

	/**
	 * The registered migrations not applied yet, in list order. Changes nothing.
	 *
	 * @return string[]
	 */
	public function pending() : array {
		return $this->pending_in( $this->ledger->applied() );
	}

	/*
	|---------
	| Helpers
	|---------
	*/

	/**
	 * Run a callback holding the lock, with the ledger in place.
	 *
	 * @param callable $callback fn(): mixed
	 * @return mixed What the callback returns.
	 * @throws MigrationException
	 */
	private function locked( callable $callback ) : mixed {
		$this->ledger->ensure();
		$this->ledger->lock( $this->lock_timeout );

		try {
			return $callback();
		} finally {
			$this->ledger->unlock();
		}
	}

	/**
	 * The registered migrations missing from the ledger, in list order.
	 *
	 * @param array<string, mixed> $applied The ledger's applied migrations.
	 * @return string[]
	 */
	private function pending_in( array $applied ) : array {
		return array_values( array_filter( $this->migrations, fn( $class ) => ! isset( $applied[ $class ] ) ) );
	}

	/**
	 * Refuse a pending migration listed before an applied one.
	 *
	 * @param array<string, mixed> $applied The ledger's applied migrations.
	 * @param string[]             $pending The pending migrations.
	 * @return void
	 * @throws MigrationException
	 */
	private function check_order( array $applied, array $pending ) : void {
		$last_applied = -1;

		foreach ( $this->migrations as $position => $class ) {
			if ( isset( $applied[ $class ] ) ) {
				$last_applied = $position;
			}
		}

		$first_pending = array_search( $pending[0], $this->migrations, true );

		if ( $first_pending < $last_applied ) {
			throw new MigrationException(
				sprintf(
					'Migration %s is pending but listed before %s, which is applied. Add new migrations to the end of the list.',
					$pending[0],
					$this->migrations[ $last_applied ]
				)
			);
		}
	}

	/**
	 * Build a migration.
	 *
	 * A subclass of AbstractMigration is built by its final constructor,
	 * whose only argument is the Database. Any other MigrationInterface
	 * needs the factory given to the Migrator.
	 *
	 * @param string $class The migration class.
	 * @return MigrationInterface
	 * @throws MigrationException When it cannot be built.
	 */
	private function build( string $class ) : MigrationInterface {
		if ( is_subclass_of( $class, AbstractMigration::class ) ) {
			return new $class( $this->db );
		}

		if ( null === $this->factory ) {
			throw new MigrationException(
				sprintf( 'Migration %s does not extend AbstractMigration; pass the Migrator a factory that builds it.', $class )
			);
		}

		$migration = ( $this->factory )( $class, $this->db );

		if ( ! $migration instanceof MigrationInterface ) {
			throw new MigrationException( sprintf( 'The migration factory did not return a MigrationInterface for %s.', $class ) );
		}

		return $migration;
	}

	/**
	 * Check the registered list.
	 *
	 * @param array $migrations The list.
	 * @return string[]
	 * @throws InvalidArgumentException When an entry is not a migration class, or is listed twice.
	 */
	private function validate( array $migrations ) : array {
		$seen = [];

		foreach ( $migrations as $class ) {
			if ( ! is_string( $class ) || ! class_exists( $class ) ) {
				throw new InvalidArgumentException( sprintf( 'Migration %s is not a class.', is_string( $class ) ? $class : gettype( $class ) ) );
			}

			if ( ! is_subclass_of( $class, MigrationInterface::class ) ) {
				throw new InvalidArgumentException( sprintf( 'Migration %s does not implement MigrationInterface.', $class ) );
			}

			$class = ltrim( $class, '\\' );

			if ( isset( $seen[ $class ] ) ) {
				throw new InvalidArgumentException( sprintf( 'Migration %s is listed twice.', $class ) );
			}

			if ( strlen( $class ) > MigrationLedger::MAX_NAME_LENGTH ) {
				throw new InvalidArgumentException(
					sprintf( 'Migration %s has a name longer than %d characters.', $class, MigrationLedger::MAX_NAME_LENGTH )
				);
			}

			$seen[ $class ] = true;
		}

		return array_keys( $seen );
	}
}