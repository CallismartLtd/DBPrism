## [2.5.0] - 2026-10-17

### Added
- `SQLBuilder::upsert()` and `UpsertIntent`: an INSERT that updates the existing row when a row conflicts on a unique key. `on_conflict()` names the key (required); `update_columns()` limits which columns are updated. Works with `values()` and `multi_values()`. Renders `ON CONFLICT ... DO UPDATE` on PostgreSQL and SQLite (3.24+), and `ON DUPLICATE KEY UPDATE` on MySQL and MariaDB.
- `Migrator::baseline()`: records every registered migration as applied without running it, for fresh installations that create the current schema directly. Only runs on a database without a ledger. Baselined migrations are recorded as run 0 and are never reverted.
- `Migrator::has_ledger()`: whether the database has a migration ledger yet.
- `Callismart\DBPrism\Exceptions\QueryException`, the base of exceptions the query builder throws when it refuses a query, with `UpsertException` and `PersistenceDataException`. All extend `InvalidArgumentException`.

### Changed
- `PersistenceIntent` now throws `PersistenceDataException` where it threw `InvalidArgumentException`. Existing `catch ( InvalidArgumentException )` blocks still catch it.
- `multi_values()` requires every row to have the same columns as the first row, and stores each row in the first row's column order.

### Fixed
- A `DefaultColumnValue` in `values()` or `set()` was rendered as a placeholder but never bound, so the bindings after it shifted by one place. In an UPDATE this wrote the WHERE value into the column and left the WHERE placeholder unbound. It is now written into the SQL.
- `multi_values()` silently paired values with the wrong columns when a row listed its columns in a different order than the first row.
- `set_case()` on an INSERT now throws when the query is built, as documented, instead of rendering a placeholder that did not match the bindings.

