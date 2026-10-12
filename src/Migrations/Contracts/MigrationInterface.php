<?php
/**
 * Migration Interface
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations\Contracts
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\Contracts;

/**
 * A database migration.
 *
 * A migration is a named, one-time change to a database, written as intent,
 * applied in a fixed order, and remembered by the database it was applied to.
 *
 *  - Named: its name is its fully-qualified class name. The name is its only
 *    identity; it is never parsed or compared as a version. Renaming or moving
 *    a released migration makes it a new migration.
 *  - One-time: each database applies each migration once. Running the
 *    migrations again skips the ones already applied.
 *  - Written as intent: up() describes the change through DBPrism's operators
 *    and query builder; DBPrism turns it into the SQL of the database in use.
 *  - Fixed order: migrations run in the order the application lists them.
 *  - Remembered by the database: a ledger table in that same database records
 *    every applied migration, so its memory travels with its data.
 *
 * A migration that can be undone implements ReversibleMigrationInterface.
 *
 * @since 0.2.0
 */
interface MigrationInterface {

    /**
     * Make this migration's change.
     *
     * Describe the change through the operators (table(), column(), index(),
     * constraint()) and the query builder; DBPrism executes it. Any exception
     * marks the migration as failed: it is not recorded, and no later
     * migration runs.
     *
     * Write it so it is safe to run again. On MySQL a schema change commits
     * immediately, so a migration that fails part-way may be partly applied;
     * because it was not recorded, the next run starts it again. The operators
     * check the schema before changing it, so they already are; SQL run through
     * exec() or the query builder must be written to be as well.
     *
     * @return void
     * @throws \Throwable When the change cannot be made.
     */
    public function up() : void;

    /**
     * A short, human-readable summary of the change, for reports and logs.
     *
     * @return string E.g. "Add an index on licenses.license_key". May be empty.
     */
    public static function get_description() : string;
}