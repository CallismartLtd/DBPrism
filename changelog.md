## [2.6.0] - Unreleased

### Added
- Backup and restore: `Callismart\DBPrism\Backup\BackupManager`. `backup()` writes a database to a portable backup file (gzip-compressed when the path ends in `.gz`), and `restore()` restores one into a database on any supported engine, whichever engine the backup came from. A backup reads every table from one snapshot; a restore is all or nothing (one transaction on PostgreSQL and SQLite; staged tables moved into place with one atomic `RENAME TABLE` on MySQL and MariaDB); a truncated or altered backup is refused before anything is committed. Backs up tables with their columns, primary keys, unique constraints, indexes, foreign keys and rows; restores move auto-increment sequences past the restored IDs. Options choose tables, schema only, and what to do with tables that already exist (`'existing' => 'error' | 'replace' | 'append'`). `BackupManager::verify()` checks a backup without a database. Anything that cannot be carried over exactly is reported as a warning.
- `ColumnType::LONG_BLOB`: binary data larger than MySQL's `BLOB` (64 KB) holds. `LONGBLOB` on MySQL, `BYTEA` on PostgreSQL, `BLOB` on SQLite.
- `Callismart\DBPrism\Exceptions\BackupException`, thrown when a backup or restore fails.

### Changed
- `ColumnType::BOOLEAN` is `TINYINT(1)` on MySQL (was `TINYINT`): MySQL's own spelling of a boolean, so a boolean column can be told from a small integer when the schema is read back. The stored values are the same.

### Fixed
- On SQLite, column sizes were not written: `size()` was ignored, so `DECIMAL` columns were declared `NUMERIC(10,2)` and strings `TEXT(255)` whatever their size. They are now declared with their size, as on MySQL and PostgreSQL. (SQLite does not enforce sizes; the declared size matters to inspection and to backups restored on other engines.)

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

