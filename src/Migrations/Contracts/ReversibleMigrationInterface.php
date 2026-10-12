<?php
/**
 * Reversible Migration Interface
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations\Contracts
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\Contracts;

/**
 * A migration that can be undone.
 *
 * A migration is reversible only when it implements this interface; one that
 * does not is irreversible. DBPrism never guesses how to undo a change.
 *
 * When migrations are reverted:
 *
 *  - every migration in the range is checked first; if any is irreversible,
 *    nothing is reverted;
 *  - they are reverted newest first, in the reverse of the order they were
 *    applied;
 *  - each one is removed from the ledger only after its down() succeeds.
 *
 * @since 0.2.0
 */
interface ReversibleMigrationInterface extends MigrationInterface {

    /**
     * Undo exactly what up() did.
     *
     * Describe it the same way as up(), through the operators and the query
     * builder, and write it so it is safe to run again. Any exception marks
     * the revert as failed: the migration stays recorded as applied, and no
     * earlier migration is reverted.
     *
     * @return void
     * @throws \Throwable When the change cannot be undone.
     */
    public function down() : void;
}