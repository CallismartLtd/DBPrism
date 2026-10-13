# Security Policy

## Supported Versions

Security fixes are released for the latest minor version.

| Version | Supported |
|---|---|
| 2.4.x | Yes |
| Earlier | No |

## Reporting a Vulnerability

Please do not report security problems in public issues.

Report them privately, either through GitHub's "Report a vulnerability" button on the repository's Security tab, or by email to admin@callismart.com.ng.

Include what you can of:

- the DBPrism version, PHP version, database engine and adapter;
- the smallest code that shows the problem;
- what an attacker can do with it.

### What happens next

- You will get a reply within 3 working days confirming the report was received.
- Within 10 working days you will hear whether it is accepted as a vulnerability, and what happens next.
- Accepted reports are fixed in a new release, followed by a published security advisory. You will be credited in the advisory unless you ask not to be.

Please keep the details private until the fix is released.

## What DBPrism Protects Against

These are DBPrism's security guarantees. A way around any of them is a vulnerability, and should be reported.

- **Values are always bound.** Values passed to the query builder (`where()` values, `insert()` and `update()` data) and to the `Database` query methods are sent as bound parameters, never written into the SQL text.
- **Identifiers are quoted.** Table and column names are quoted for the engine, with quote characters inside them escaped.
- **Operators and sort directions are checked.** Comparison operators and `ASC` / `DESC` are checked against a fixed list; anything else throws.
- **Search terms match literally.** `where_contains()`, `where_starts_with()`, `where_ends_with()` and `where_like()` escape `%` and `_` inside the term.
- **Connection settings cannot rewrite the DSN.** `DsnBuilder` rejects a `;` in any value it writes into a DSN.
- **Migration table names are checked.** The `Migrator`'s table prefix must be letters, digits and underscores.

## Trusted Input (Out of Scope)

Some input is treated as SQL, on purpose. Problems that come from passing untrusted input into it are not vulnerabilities in DBPrism:

- **Table and column names.** A name that looks like an SQL expression (`COUNT(*)`, `orders.total * 2`) is written as given. Never build names from user input; map the user's choice to a fixed list of names instead.
- **Raw SQL.** `select_raw()`, `where_raw()`, `exec()`, `DefaultColumnValue::expression()` and `SQLExpression` are written as given. Only their bound values (`where_raw( $sql, $bindings )`) are safe for user input.
- **`build_raw()`.** It writes the bindings into the SQL for reading and logging. Its output is not escaped for execution and must never be run.
- **Configuration.** Database credentials, DSNs and adapter settings are trusted.
- **Your own adapters.** An adapter you write is responsible for binding values safely.
- **PHP, database drivers and engines.** Report problems in those to their own projects.