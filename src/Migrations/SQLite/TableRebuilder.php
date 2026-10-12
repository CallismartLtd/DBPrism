<?php
/**
 * SQLite table rebuilder class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations\SQLite
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\SQLite;

use Callismart\DBPrism\Database;
use Callismart\DBPrism\Exceptions\MigrationException;
use Callismart\DBPrism\Query\SQLBuilder;
use InvalidArgumentException;
use LogicException;

/**
 * Applies a table change that SQLite's ALTER TABLE cannot make, by building
 * the table again.
 *
 * SQLite can add, rename and drop columns in place, and nothing more: adding
 * or removing a constraint means creating the table anew. The rebuild
 * follows SQLite's documented procedure (https://www.sqlite.org/lang_altertable.html#otheralter):
 *
 *  1. Turn off foreign key enforcement, when it is on.
 *  2. In one transaction: create the new table from the edited definition,
 *     copy the rows, drop the old table, give the new table its name, then
 *     restore the old table's indexes, triggers and AUTOINCREMENT counter.
 *  3. Before committing: check that every view still compiles and, when
 *     enforcement was on, the foreign keys of the table and of the tables
 *     that reference it.
 *  4. Turn enforcement back on.
 *
 * The table ends up either fully changed or exactly as it was. Everything
 * the edit does not touch is carried over as written, because the new table
 * is created from an edited copy of the old table's own CREATE TABLE
 * statement (see TableDefinition).
 *
 * Rows are copied for every column the old and new tables share by name.
 * Generated columns are computed again, not copied.
 *
 * The SQL here is SQLite's own schema maintenance (PRAGMA, sqlite_master,
 * INSERT ... SELECT, replaying stored statements), which the query builder
 * does not express; dropping and renaming the table go through the builder.
 *
 * @since 0.2.0
 */
class TableRebuilder {

	/**
	 * Suffix of the name the new table is built under, before it takes the
	 * old table's name.
	 *
	 * @var string
	 */
	public const BUILD_SUFFIX = '__dbprism_rebuild';

	/**
	 * Constructor.
	 *
	 * @param Database $db A SQLite database.
	 * @throws LogicException When the database is not SQLite.
	 */
	public function __construct( private Database $db ) {
		if ( 'sqlite' !== $db->get_driver() ) {
			throw new LogicException( sprintf( 'TableRebuilder works on SQLite only; the database is %s.', $db->get_driver() ) );
		}
	}

	/**
	 * Rebuild a table with an edited definition.
	 *
	 *   $rebuilder->rebuild( 'licenses', function ( TableDefinition $table ) {
	 *       $table->drop_constraint( 'uq_licenses_key' );
	 *   } );
	 *
	 * Cannot run inside an open transaction while foreign keys are enforced:
	 * SQLite only allows enforcement to be turned off outside a transaction,
	 * and dropping a table with enforcement on deletes or rejects the rows
	 * that reference it.
	 *
	 * @param string        $table  The table name.
	 * @param callable      $edit   fn( TableDefinition $definition ): void — makes the change.
	 * @param callable|null $verify fn( string $build_table ): void — runs after the rows are
	 *                              copied into the new table (still under its build name),
	 *                              before the old table is dropped; throw MigrationException
	 *                              to refuse the change.
	 * @return void
	 * @throws MigrationException When the table does not exist, the edit is not
	 *                            possible, or any step fails. The table is left as it was.
	 */
	public function rebuild( string $table, callable $edit, ?callable $verify = null ) : void {
		$stored = $this->db->get_var(
			"SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ? COLLATE NOCASE",
			[ $table ]
		);

		if ( ! is_string( $stored ) || '' === $stored ) {
			throw new MigrationException( sprintf( 'Cannot rebuild table "%s": it does not exist.', $table ) );
		}

		$build = $table . self::BUILD_SUFFIX;

		if ( $this->db->get_var( "SELECT COUNT(*) FROM sqlite_master WHERE name = ? COLLATE NOCASE", [ $build ] ) ) {
			throw new MigrationException( sprintf( 'Cannot rebuild table "%s": a table named "%s" already exists.', $table, $build ) );
		}

		try {
			$definition = TableDefinition::from_sql( $stored );
			$edit( $definition );
		} catch ( InvalidArgumentException $e ) {
			throw new MigrationException( sprintf( 'Cannot rebuild table "%s": %s', $table, $e->getMessage() ), 0, $e );
		}

		$enforced = (bool) $this->db->get_var( 'PRAGMA foreign_keys' );

		if ( $enforced ) {
			$this->db->exec( 'PRAGMA foreign_keys = OFF' );

			if ( $this->db->get_var( 'PRAGMA foreign_keys' ) ) {
				throw new MigrationException(
					sprintf( 'Cannot rebuild table "%s" inside an open transaction while foreign keys are enforced.', $table )
				);
			}
		}

		// Renames the new table without rewriting, or re-checking, the views
		// and triggers that refer to the old one: they refer to it by name,
		// and the name is the same once the rebuild is done.
		$legacy = (int) $this->db->get_var( 'PRAGMA legacy_alter_table' );
		$this->db->exec( 'PRAGMA legacy_alter_table = ON' );

		try {
			$this->db->transactional( function () use ( $table, $build, $definition, $enforced, $verify ) {
				$this->replace( $table, $build, $definition, $enforced, $verify );
			} );
		} finally {
			$this->db->exec( 'PRAGMA legacy_alter_table = ' . $legacy );

			if ( $enforced ) {
				$this->db->exec( 'PRAGMA foreign_keys = ON' );
			}
		}
	}

	/**
	 * Build the new table and put it in place of the old one.
	 *
	 * @param string          $table      The table name.
	 * @param string          $build      The name to build the new table under.
	 * @param TableDefinition $definition The edited definition.
	 * @param bool            $enforced   Whether foreign keys were enforced.
	 * @param callable|null   $verify     Checks the copied rows; see rebuild().
	 * @return void
	 * @throws MigrationException When a step fails.
	 */
	private function replace( string $table, string $build, TableDefinition $definition, bool $enforced, ?callable $verify ) : void {
		$dependents = $this->db->get_results(
			"SELECT name, sql FROM sqlite_master WHERE type IN ( 'index', 'trigger' ) AND tbl_name = ? COLLATE NOCASE AND sql IS NOT NULL ORDER BY type, name",
			[ $table ]
		);

		$sequence = $this->has_sequence_table()
			? $this->db->get_var( 'SELECT seq FROM sqlite_sequence WHERE name = ? COLLATE NOCASE', [ $table ] )
			: null;

		$this->run( $definition->to_sql( $build ), sprintf( 'Creating the new definition of table "%s"', $table ) );

		$columns = array_values( array_uintersect( $this->stored_columns( $table ), $this->stored_columns( $build ), 'strcasecmp' ) );

		if ( ! empty( $columns ) ) {
			$list = implode( ', ', array_map( [ $this, 'quote' ], $columns ) );

			$this->run(
				sprintf( 'INSERT INTO %s ( %s ) SELECT %s FROM %s', $this->quote( $build ), $list, $list, $this->quote( $table ) ),
				sprintf( 'Copying the rows of table "%s"', $table )
			);
		}

		if ( null !== $verify ) {
			$verify( $build );
		}

		$this->run( $this->query()->drop_table( $table )->build(), sprintf( 'Dropping the old table "%s"', $table ) );
		$this->run( $this->query()->alter_table( $build )->rename( $table )->build(), sprintf( 'Renaming the new table to "%s"', $table ) );

		foreach ( $dependents as $dependent ) {
			$this->run( $dependent['sql'], sprintf( 'Restoring "%s" on table "%s"', $dependent['name'], $table ) );
		}

		if ( null !== $sequence && $definition->has_autoincrement() ) {
			$this->restore_sequence( $table, (int) $sequence );
		}

		$this->check_views( $table );

		if ( $enforced ) {
			$this->check_foreign_keys( $table );
		}
	}

	/**
	 * Check that every view still compiles.
	 *
	 * The new table is renamed without re-checking the views that refer to
	 * it (see rebuild()), so a view that used something the rebuild removed,
	 * such as a dropped column, is found here instead.
	 *
	 * @param string $table The table name, for the error message.
	 * @return void
	 * @throws MigrationException When a view no longer compiles.
	 */
	private function check_views( string $table ) : void {
		foreach ( $this->db->get_col( "SELECT name FROM sqlite_master WHERE type = 'view'" ) as $view ) {
			$this->db->get_results( sprintf( 'SELECT * FROM %s LIMIT 0', $this->quote( $view ) ) );
			$error = $this->db->get_last_error();

			if ( null !== $error ) {
				throw new MigrationException(
					sprintf( 'Rebuilding table "%s" would break view "%s": %s', $table, $view, $error )
				);
			}
		}
	}

	/**
	 * Keep the AUTOINCREMENT counter where it was, so deleted IDs are not reused.
	 *
	 * The new table's counter only reaches the highest ID copied across;
	 * the old counter may be higher.
	 *
	 * @param string $table    The table name.
	 * @param int    $sequence The old counter.
	 * @return void
	 * @throws MigrationException When the counter cannot be written.
	 */
	private function restore_sequence( string $table, int $sequence ) : void {
		$current = $this->db->get_var( 'SELECT seq FROM sqlite_sequence WHERE name = ? COLLATE NOCASE', [ $table ] );

		if ( null === $current ) {
			$written = $this->db->execute( 'INSERT INTO sqlite_sequence ( name, seq ) VALUES ( ?, ? )', [ $table, $sequence ] );
		} elseif ( (int) $current < $sequence ) {
			$written = $this->db->execute( 'UPDATE sqlite_sequence SET seq = ? WHERE name = ? COLLATE NOCASE', [ $sequence, $table ] );
		} else {
			return;
		}

		if ( 1 !== $written ) {
			throw new MigrationException(
				sprintf( 'Restoring the AUTOINCREMENT counter of table "%s" failed: %s', $table, $this->db->get_last_error() ?? 'unknown error' )
			);
		}
	}

	/**
	 * Check the foreign keys of the table and of every table that references it.
	 *
	 * @param string $table The table name.
	 * @return void
	 * @throws MigrationException When a row breaks a foreign key, or a foreign key
	 *                            no longer matches a primary key or unique constraint.
	 */
	private function check_foreign_keys( string $table ) : void {
		$referencing = $this->db->get_col(
			"SELECT DISTINCT m.name FROM sqlite_master AS m, pragma_foreign_key_list( m.name ) AS f WHERE m.type = 'table' AND f.\"table\" = ? COLLATE NOCASE",
			[ $table ]
		);

		foreach ( array_unique( [ $table, ...$referencing ] ) as $checked ) {
			$violations = $this->db->get_results( 'SELECT * FROM pragma_foreign_key_check( ? )', [ $checked ] );
			$error      = $this->db->get_last_error();

			if ( null !== $error ) {
				throw new MigrationException( sprintf( 'Checking the foreign keys of table "%s" failed: %s', $checked, $error ) );
			}

			if ( ! empty( $violations ) ) {
				throw new MigrationException(
					sprintf( 'Rebuilding table "%s" would break %d foreign key reference(s) in table "%s".', $table, count( $violations ), $checked )
				);
			}
		}
	}

	/**
	 * The stored (non-generated) columns of a table, in order.
	 *
	 * @param string $table The table name.
	 * @return string[]
	 */
	private function stored_columns( string $table ) : array {
		return $this->db->get_col( 'SELECT name FROM pragma_table_xinfo( ? ) WHERE hidden = 0 ORDER BY cid', [ $table ] );
	}

	/**
	 * Whether sqlite_sequence exists (it does once any AUTOINCREMENT table has been created).
	 *
	 * @return bool
	 */
	private function has_sequence_table() : bool {
		return (bool) $this->db->get_var( "SELECT COUNT(*) FROM sqlite_master WHERE type = 'table' AND name = 'sqlite_sequence'" );
	}

	/**
	 * Execute a statement, throwing when it fails.
	 *
	 * @param string $sql    The statement.
	 * @param string $action What is being done, for the error message.
	 * @return void
	 * @throws MigrationException When the statement fails.
	 */
	private function run( string $sql, string $action ) : void {
		if ( ! $this->db->exec( $sql ) ) {
			throw new MigrationException( sprintf( '%s failed: %s', $action, $this->db->get_last_error() ?? 'unknown error' ) );
		}
	}

	/**
	 * Start building a SQLite query.
	 *
	 * @return SQLBuilder
	 */
	private function query() : SQLBuilder {
		return new SQLBuilder( 'sqlite' );
	}

	/**
	 * Quote an identifier for SQLite.
	 *
	 * @param string $identifier The identifier.
	 * @return string
	 */
	private function quote( string $identifier ) : string {
		return '"' . str_replace( '"', '""', $identifier ) . '"';
	}
}