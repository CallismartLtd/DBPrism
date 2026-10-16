## DBPrism 2.4.2

This is a security release. All users of 2.4.0 and earlier should upgrade.

### Security

**SQL injection through operators, sort directions and connectors.** The query builder wrote these into the SQL exactly as given. An application passing request data as the operator of `where()`, `having()` or a join, as the direction of `order_by()`, or as the connector of a condition allowed an attacker to change the query. They are now checked against a fixed list:

- comparison operators: `=`, `!=`, `<>`, `<`, `<=`, `>`, `>=`, `LIKE`, `NOT LIKE`
- sort directions: `ASC`, `DESC`
- connectors: `AND`, `OR`

Case and extra spaces are accepted (`'not   like'` is written as `NOT LIKE`). Anything else throws `InvalidArgumentException` when the query is built.

**SQL injection through `insert()`, `update()` and `delete()` array keys.** The adapters wrote the table name and the keys of the data and where arrays into the SQL exactly as given. An application passing request data as the array, such as `$db->update( 'users', $_POST, [ 'id' => $id ] )`, allowed an attacker to set other columns or widen the WHERE clause to every row. The table and every key must now be a plain name: letters, digits and underscores, with one optional schema prefix for the table (`schema.table`). Valid names are quoted for the engine; anything else throws `InvalidArgumentException` before the statement is built.

### Changes you may notice

- `where( 'col', 'IS', null )` and other operators outside the list now throw. Use `where_null()` and `where_not_null()`.
- `insert()`, `update()` and `delete()` now quote table and column names, as the query builder already did. Reserved words such as `order` now work as column names. On PostgreSQL, quoted names are case-sensitive, so a mixed-case name like `UserId` no longer matches a column created unquoted (stored as `userid`).
- An expression used as a key in these three methods now throws. Use the query builder for expressions.

### Fixed

- `right_join()` called without an operator threw a PHP `TypeError` instead of `InvalidArgumentException`, unlike `join()` and `left_join()`.
- `SqliteAdapter` opened databases read-only when `flags` was malformed.

### Added

- A security test suite (`composer test:security`) covering value binding, identifier quoting, the input guards and injection against a live database.
- `SECURITY.md`, describing DBPrism's security guarantees and how to report a vulnerability.