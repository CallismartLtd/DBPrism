<?php
/**
 * SQLite table definition class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Migrations\SQLite
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Migrations\SQLite;

use InvalidArgumentException;

/**
 * An editable copy of the CREATE TABLE statement SQLite stored for a table.
 *
 * SQLite keeps the statement that created each table, as written, in
 * sqlite_master. It is the only complete record of the table: PRAGMA
 * table_info cannot describe AUTOINCREMENT, collations, CHECK expressions,
 * generated columns or constraint names. A change is therefore made by
 * editing that statement, so everything not being changed is carried over
 * exactly as it was written.
 *
 * The statement is read as a header (CREATE TABLE name), a list of
 * definitions (columns, then table constraints) and a suffix (table options
 * such as WITHOUT ROWID or STRICT). Edits add or remove whole definitions,
 * or remove a column's PRIMARY KEY clause; the text of every other
 * definition is kept as it is.
 *
 * Names are compared the way SQLite compares identifiers: ignoring ASCII case.
 *
 * @since 0.2.0
 */
class TableDefinition {

	/**
	 * Keywords that begin a table constraint, mapped to its type.
	 *
	 * @var array<string, string>
	 */
	private const CONSTRAINT_KEYWORDS = [
		'PRIMARY' => 'primary',
		'UNIQUE'  => 'unique',
		'CHECK'   => 'check',
		'FOREIGN' => 'foreign',
	];

	/**
	 * The definitions between the outer parentheses, as written.
	 *
	 * Each entry keeps the whitespace and comments around it, so joining
	 * them with commas gives back the original text.
	 *
	 * @var string[]
	 */
	private array $definitions;

	/**
	 * Constructor.
	 *
	 * @param string   $suffix      Text after the closing parenthesis, e.g. ' WITHOUT ROWID'.
	 * @param string[] $definitions The definitions, as written.
	 */
	private function __construct( private string $suffix, array $definitions ) {
		$this->definitions = $definitions;
	}

	/**
	 * Read a stored CREATE TABLE statement.
	 *
	 * @param string $sql The statement, as stored in sqlite_master.sql.
	 * @return static
	 * @throws InvalidArgumentException When the statement is not a CREATE TABLE with a definition list.
	 */
	public static function from_sql( string $sql ) : static {
		$tokens = self::tokenize( $sql );

		if ( count( $tokens ) < 2 || 'CREATE' !== self::keyword( $tokens[0] ) || 'TABLE' !== self::keyword( $tokens[1] ) ) {
			throw new InvalidArgumentException( 'Not a CREATE TABLE statement.' );
		}

		$open   = null;
		$close  = null;
		$commas = [];
		$depth  = 0;

		foreach ( $tokens as $token ) {
			if ( '(' === $token['text'] ) {
				if ( 0 === $depth && null === $open ) {
					$open = $token['offset'];
				}
				$depth++;
			} elseif ( ')' === $token['text'] ) {
				$depth--;
				if ( 0 === $depth && null === $close ) {
					$close = $token['offset'];
				}
			} elseif ( ',' === $token['text'] && 1 === $depth && null === $close ) {
				$commas[] = $token['offset'];
			}
		}

		if ( null === $open || null === $close ) {
			throw new InvalidArgumentException( 'The CREATE TABLE statement has no definition list.' );
		}

		$definitions = [];
		$start       = $open + 1;

		foreach ( [ ...$commas, $close ] as $end ) {
			$definitions[] = substr( $sql, $start, $end - $start );
			$start         = $end + 1;
		}

		return new static( substr( $sql, $close + 1 ), $definitions );
	}

	/**
	 * The statement, creating a table with the given name.
	 *
	 * @param string $table The table name.
	 * @return string
	 */
	public function to_sql( string $table ) : string {
		return sprintf( 'CREATE TABLE %s (%s)%s', self::quote( $table ), implode( ',', $this->definitions ), $this->suffix );
	}

	/**
	 * The table constraints: those written as their own definition, not as
	 * part of a column.
	 *
	 * @return array<int, array{name: ?string, type: string, columns: string[], references_table: ?string, references_columns: string[], sql: string}>
	 *         In order. 'type' is primary, unique, check or foreign;
	 *         'columns' are the constrained columns (empty for check);
	 *         'references_table' and 'references_columns' are set for a
	 *         foreign key (the columns are empty when the key references
	 *         the other table's primary key implicitly); 'sql' is the
	 *         definition as written, trimmed.
	 */
	public function get_constraints() : array {
		$constraints = [];

		foreach ( $this->definitions as $definition ) {
			$constraint = self::read_constraint( $definition );

			if ( null !== $constraint ) {
				$constraints[] = $constraint;
			}
		}

		return $constraints;
	}

	/**
	 * A table constraint, by name.
	 *
	 * @param string $name The constraint name.
	 * @return array{name: ?string, type: string, columns: string[], references_table: ?string, references_columns: string[], sql: string}|null
	 */
	public function get_constraint( string $name ) : ?array {
		foreach ( $this->get_constraints() as $constraint ) {
			if ( null !== $constraint['name'] && self::same_name( $constraint['name'], $name ) ) {
				return $constraint;
			}
		}

		return null;
	}

	/**
	 * The name of the primary key, when it was declared with one.
	 *
	 * Reads a PRIMARY KEY table constraint, or a column's
	 * CONSTRAINT name PRIMARY KEY clause.
	 *
	 * @return string|null Null when the table has no primary key or it is unnamed.
	 */
	public function get_primary_key_name() : ?string {
		foreach ( $this->get_constraints() as $constraint ) {
			if ( 'primary' === $constraint['type'] ) {
				return $constraint['name'];
			}
		}

		foreach ( $this->definitions as $definition ) {
			if ( null !== self::read_constraint( $definition ) ) {
				continue;
			}

			$tokens = self::tokenize( $definition );

			foreach ( $tokens as $j => $token ) {
				if ( 'PRIMARY' === self::keyword( $token ) && 'KEY' === self::keyword( $tokens[ $j + 1 ] ?? [ 'text' => '', 'quoted' => true ] ) ) {
					return $j >= 3 && 'CONSTRAINT' === self::keyword( $tokens[ $j - 2 ] ) ? self::unquote( $tokens[ $j - 1 ] ) : null;
				}
			}
		}

		return null;
	}

	/**
	 * Whether the table uses AUTOINCREMENT.
	 *
	 * @return bool
	 */
	public function has_autoincrement() : bool {
		foreach ( $this->definitions as $definition ) {
			foreach ( self::tokenize( $definition ) as $token ) {
				if ( 'AUTOINCREMENT' === self::keyword( $token ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Add a table constraint, after the existing definitions.
	 *
	 * @param string $sql The constraint definition, e.g. 'CONSTRAINT "uq" UNIQUE ("code")'.
	 * @return static
	 * @throws InvalidArgumentException When $sql is not a table constraint.
	 */
	public function add_constraint( string $sql ) : static {
		if ( null === self::read_constraint( $sql ) ) {
			throw new InvalidArgumentException( sprintf( 'Not a table constraint: %s', $sql ) );
		}

		$last      = array_key_last( $this->definitions );
		$text      = $this->definitions[ $last ];
		$trailing  = substr( $text, strlen( rtrim( $text ) ) );
		$separator = str_contains( implode( ',', $this->definitions ), "\n" ) ? "\n\t" : ' ';

		$this->definitions[ $last ] = self::trim_end( $text );
		$this->definitions[]        = $separator . trim( $sql ) . $trailing;

		return $this;
	}

	/**
	 * Remove a table constraint, by name.
	 *
	 * @param string $name The constraint name.
	 * @return static
	 * @throws InvalidArgumentException When no table constraint has this name, or the name
	 *                                  belongs to a constraint written inside a column definition.
	 */
	public function drop_constraint( string $name ) : static {
		foreach ( $this->definitions as $i => $definition ) {
			$constraint = self::read_constraint( $definition );

			if ( null !== $constraint && null !== $constraint['name'] && self::same_name( $constraint['name'], $name ) ) {
				return $this->remove_definition( $i );
			}
		}

		foreach ( $this->definitions as $definition ) {
			$tokens = self::tokenize( $definition );

			foreach ( $tokens as $j => $token ) {
				if ( 'CONSTRAINT' === self::keyword( $token ) && isset( $tokens[ $j + 1 ] ) && self::same_name( self::unquote( $tokens[ $j + 1 ] ), $name ) ) {
					throw new InvalidArgumentException(
						sprintf( 'Constraint "%s" is written inside the definition of column "%s"; only table constraints can be removed.', $name, self::unquote( $tokens[0] ) )
					);
				}
			}
		}

		throw new InvalidArgumentException( sprintf( 'No table constraint is named "%s".', $name ) );
	}

	/**
	 * Remove the primary key.
	 *
	 * Removes a PRIMARY KEY table constraint, or the PRIMARY KEY clause of the
	 * column that declares it (with that clause's CONSTRAINT name, sort order
	 * and ON CONFLICT clause).
	 *
	 * @return static
	 * @throws InvalidArgumentException When the table has no primary key, or its
	 *                                  primary key column uses AUTOINCREMENT, which
	 *                                  SQLite only allows on a primary key.
	 */
	public function drop_primary_key() : static {
		foreach ( $this->definitions as $i => $definition ) {
			$constraint = self::read_constraint( $definition );

			if ( null !== $constraint && 'primary' === $constraint['type'] ) {
				return $this->remove_definition( $i );
			}
		}

		foreach ( $this->definitions as $i => $definition ) {
			if ( null !== self::read_constraint( $definition ) ) {
				continue;
			}

			$tokens = self::tokenize( $definition );
			$count  = count( $tokens );

			for ( $j = 1; $j < $count - 1; $j++ ) {
				if ( 'PRIMARY' !== self::keyword( $tokens[ $j ] ) || 'KEY' !== self::keyword( $tokens[ $j + 1 ] ) ) {
					continue;
				}

				$start = $j;
				if ( $j >= 3 && 'CONSTRAINT' === self::keyword( $tokens[ $j - 2 ] ) ) {
					$start = $j - 2;
				}

				$end = $j + 1;
				if ( isset( $tokens[ $end + 1 ] ) && in_array( self::keyword( $tokens[ $end + 1 ] ), [ 'ASC', 'DESC' ], true ) ) {
					$end++;
				}
				if ( isset( $tokens[ $end + 2 ] ) && 'ON' === self::keyword( $tokens[ $end + 1 ] ) && 'CONFLICT' === self::keyword( $tokens[ $end + 2 ] ) ) {
					$end += isset( $tokens[ $end + 3 ] ) ? 3 : 2;
				}
				if ( isset( $tokens[ $end + 1 ] ) && 'AUTOINCREMENT' === self::keyword( $tokens[ $end + 1 ] ) ) {
					throw new InvalidArgumentException(
						sprintf( 'The primary key of column "%s" cannot be removed: the column uses AUTOINCREMENT, which SQLite only allows on a primary key.', self::unquote( $tokens[0] ) )
					);
				}

				$from = $tokens[ $start ]['offset'];
				$to   = $tokens[ $end ]['offset'] + strlen( $tokens[ $end ]['text'] );

				$this->definitions[ $i ] = rtrim( substr( $definition, 0, $from ) ) . substr( $definition, $to );

				return $this;
			}
		}

		throw new InvalidArgumentException( 'The table has no primary key.' );
	}

	/*
	|---------
	| Columns
	|---------
	*/

	/**
	 * Whether the table has a column with the given name.
	 *
	 * @param string $name The column name.
	 * @return bool
	 */
	public function has_column( string $name ) : bool {
		return null !== $this->find_column( $name );
	}

	/**
	 * A column's declared type, as written (e.g. 'VARCHAR(20)'), or '' when none was declared.
	 *
	 * @param string $name The column name.
	 * @return string|null Null when there is no such column.
	 */
	public function get_column_type( string $name ) : ?string {
		$index = $this->find_column( $name );

		if ( null === $index ) {
			return null;
		}

		$definition = $this->definitions[ $index ];
		$tokens     = self::tokenize( $definition );
		$clauses    = self::column_clauses( $definition );
		$end        = empty( $clauses ) ? count( $tokens ) : $clauses[0]['start'];

		if ( $end <= 1 ) {
			return '';
		}

		$from = $tokens[1]['offset'];
		$to   = $tokens[ $end - 1 ]['offset'] + strlen( $tokens[ $end - 1 ]['text'] );

		return substr( $definition, $from, $to - $from );
	}

	/**
	 * A column's definition, as written, trimmed.
	 *
	 * @param string $name The column name.
	 * @return string|null Null when there is no such column.
	 */
	public function get_column( string $name ) : ?string {
		$index = $this->find_column( $name );

		return null === $index ? null : trim( $this->definitions[ $index ] );
	}

	/**
	 * Whether a column's own definition declares a constraint: PRIMARY KEY,
	 * UNIQUE, CHECK or REFERENCES.
	 *
	 * @param string $name The column name.
	 * @return bool
	 */
	public function column_has_constraints( string $name ) : bool {
		$definition = $this->get_column( $name );

		if ( null === $definition ) {
			return false;
		}

		foreach ( self::column_clauses( $definition ) as $clause ) {
			if ( in_array( $clause['kind'], [ 'primary', 'unique', 'check', 'foreign' ], true ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether SQLite's ALTER TABLE ADD COLUMN accepts a column definition.
	 *
	 * It does not accept a PRIMARY KEY or UNIQUE column, a generated
	 * column, a default that is not a literal (a number, a string, NULL,
	 * TRUE or FALSE), or NOT NULL without a non-NULL default. Anything not
	 * plainly accepted is reported as not accepted.
	 *
	 * @param string $sql The column definition.
	 * @return bool
	 */
	public static function can_add_column_in_place( string $sql ) : bool {
		$not_null = false;
		$default  = null;

		foreach ( self::column_clauses( $sql ) as $clause ) {
			switch ( $clause['kind'] ) {
				case 'primary':
				case 'unique':
				case 'generated':
					return false;

				case 'null':
					$not_null = $not_null || 0 === stripos( $clause['sql'], 'NOT' );
					break;

				case 'default':
					$default = trim( substr( $clause['sql'], strlen( 'DEFAULT' ) ) );
					break;
			}
		}

		if ( null !== $default && ! preg_match( "/^(?:[+-]?\\s*\\d[\\d.]*(?:e[+-]?\\d+)?|'(?:[^']|'')*'|NULL|TRUE|FALSE)$/i", $default ) ) {
			return false;
		}

		return ! ( $not_null && ( null === $default || 0 === strcasecmp( $default, 'NULL' ) ) );
	}

	/**
	 * Whether a column is declared AUTOINCREMENT.
	 *
	 * @param string $name The column name.
	 * @return bool
	 */
	public function is_autoincrement_column( string $name ) : bool {
		$index = $this->find_column( $name );

		if ( null === $index ) {
			return false;
		}

		foreach ( self::tokenize( $this->definitions[ $index ] ) as $token ) {
			if ( 'AUTOINCREMENT' === self::keyword( $token ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Add a column, after the existing columns and before the table constraints.
	 *
	 * @param string $sql The column definition, e.g. '"code" TEXT NOT NULL DEFAULT \'\''.
	 * @return static
	 * @throws InvalidArgumentException When $sql is a table constraint, or the column exists.
	 */
	public function add_column( string $sql ) : static {
		$tokens = self::tokenize( $sql );

		if ( empty( $tokens ) || null !== self::read_constraint( $sql ) ) {
			throw new InvalidArgumentException( sprintf( 'Not a column definition: %s', $sql ) );
		}

		$name = self::unquote( $tokens[0] );

		if ( $this->has_column( $name ) ) {
			throw new InvalidArgumentException( sprintf( 'Column "%s" already exists.', $name ) );
		}

		$last_column = null;

		foreach ( $this->definitions as $i => $definition ) {
			if ( null === self::read_constraint( $definition ) ) {
				$last_column = $i;
			}
		}

		if ( null === $last_column || array_key_last( $this->definitions ) === $last_column ) {
			$last      = array_key_last( $this->definitions );
			$text      = $this->definitions[ $last ];
			$trailing  = substr( $text, strlen( rtrim( $text ) ) );

			$this->definitions[ $last ] = self::trim_end( $text );
			$this->definitions[]        = $this->separator() . trim( $sql ) . $trailing;

			return $this;
		}

		array_splice( $this->definitions, $last_column + 1, 0, [ $this->separator() . trim( $sql ) ] );

		return $this;
	}

	/**
	 * Remove a column's definition, with the constraints written inside it.
	 *
	 * @param string $name The column name.
	 * @return static
	 * @throws InvalidArgumentException When there is no such column.
	 */
	public function drop_column( string $name ) : static {
		$index = $this->find_column( $name );

		if ( null === $index ) {
			throw new InvalidArgumentException( sprintf( 'No column is named "%s".', $name ) );
		}

		return $this->remove_definition( $index );
	}

	/**
	 * Replace a column's definition: its name, type, nullability and default.
	 *
	 * The constraints written inside the old definition (PRIMARY KEY with
	 * AUTOINCREMENT, UNIQUE, CHECK, REFERENCES, each with its CONSTRAINT
	 * name) are kept, unless the new definition declares one of the same
	 * kind: on other engines they are separate objects that changing a
	 * column leaves alone. NOT NULL, DEFAULT and COLLATE are replaced.
	 *
	 * @param string $name The column name.
	 * @param string $sql  The new definition, e.g. '"status" TEXT NOT NULL'.
	 * @return static
	 * @throws InvalidArgumentException When there is no such column, or it is a generated column.
	 */
	public function replace_column( string $name, string $sql ) : static {
		$index = $this->find_column( $name );

		if ( null === $index ) {
			throw new InvalidArgumentException( sprintf( 'No column is named "%s".', $name ) );
		}

		$definition = $this->definitions[ $index ];
		$tokens     = self::tokenize( $definition );
		$clauses    = self::column_clauses( $definition );

		foreach ( $clauses as $clause ) {
			if ( 'generated' === $clause['kind'] ) {
				throw new InvalidArgumentException( sprintf( 'Column "%s" is a generated column; its definition cannot be replaced.', $name ) );
			}
		}

		$new_kinds = array_column( self::column_clauses( $sql ), 'kind' );
		$kept      = '';

		foreach ( $clauses as $clause ) {
			if ( in_array( $clause['kind'], [ 'primary', 'unique', 'check', 'foreign' ], true ) && ! in_array( $clause['kind'], $new_kinds, true ) ) {
				$kept .= ' ' . $clause['sql'];
			}
		}

		$last   = end( $tokens );
		$prefix = substr( $definition, 0, $tokens[0]['offset'] );
		$suffix = substr( $definition, $last['offset'] + strlen( $last['text'] ) );

		$this->definitions[ $index ] = $prefix . trim( $sql ) . $kept . $suffix;

		return $this;
	}

	/*
	|----------
	| Helpers
	|----------
	*/

	/**
	 * The position of a column's definition.
	 *
	 * @param string $name The column name.
	 * @return int|null
	 */
	private function find_column( string $name ) : ?int {
		foreach ( $this->definitions as $i => $definition ) {
			if ( null !== self::read_constraint( $definition ) ) {
				continue;
			}

			$tokens = self::tokenize( $definition );

			if ( ! empty( $tokens ) && self::same_name( self::unquote( $tokens[0] ), $name ) ) {
				return $i;
			}
		}

		return null;
	}

	/**
	 * The separator to put before an added definition: a new line when the
	 * statement is written over several lines, a space otherwise.
	 *
	 * @return string
	 */
	private function separator() : string {
		return str_contains( implode( ',', $this->definitions ), "\n" ) ? "\n\t" : ' ';
	}

	/**
	 * Split a column definition's tokens into its constraint clauses.
	 *
	 * Everything between the column name and the first clause is the type.
	 * A clause starts at CONSTRAINT, PRIMARY, UNIQUE, CHECK, REFERENCES,
	 * DEFAULT, COLLATE, GENERATED, AS, NULL or NOT NULL outside parentheses,
	 * except where those words belong to the clause before (DEFAULT NULL,
	 * ON DELETE SET NULL, SET DEFAULT). A CONSTRAINT name belongs to the
	 * clause that follows it.
	 *
	 * @param string $definition One column definition, as written.
	 * @return array<int, array{kind: string, start: int, sql: string}>
	 *         'kind' is primary, unique, check, foreign, default, collate, generated or null;
	 *         'start' is the clause's first token; 'sql' its text, from that token to the next clause.
	 */
	private static function column_clauses( string $definition ) : array {
		$tokens   = self::tokenize( $definition );
		$count    = count( $tokens );
		$clauses  = [];
		$depth    = 0;
		$naming   = 0; // 2: the next token is a CONSTRAINT name; 1: the next keyword gives the named clause its kind.
		$previous = '';

		for ( $j = 1; $j < $count; $j++ ) {
			$text = $tokens[ $j ]['text'];

			if ( '(' === $text ) {
				$depth++;
			} elseif ( ')' === $text ) {
				$depth--;
			}

			if ( 0 !== $depth || '(' === $text || ')' === $text ) {
				$previous = '';
				continue;
			}

			$word = self::keyword( $tokens[ $j ] );

			if ( 2 === $naming ) {
				$naming   = 1;
				$previous = '';
				continue;
			}

			$kind = match ( true ) {
				'CONSTRAINT' === $word                                         => 'constraint',
				'PRIMARY' === $word                                            => 'primary',
				'UNIQUE' === $word                                             => 'unique',
				'CHECK' === $word                                              => 'check',
				'REFERENCES' === $word                                         => 'foreign',
				'DEFAULT' === $word && 'SET' !== $previous                     => 'default',
				'COLLATE' === $word                                            => 'collate',
				'GENERATED' === $word, 'AS' === $word                          => 'generated',
				'NOT' === $word && 'NULL' === self::keyword( $tokens[ $j + 1 ] ?? [ 'text' => '', 'quoted' => true ] ) => 'null',
				'NULL' === $word && ! in_array( $previous, [ 'NOT', 'DEFAULT', 'SET' ], true ) => 'null',
				default                                                        => null,
			};

			$previous = $word;

			if ( null === $kind ) {
				continue;
			}

			if ( 1 === $naming ) {
				$clauses[ array_key_last( $clauses ) ]['kind'] = $kind;
				$naming = 0;
				continue;
			}

			if ( 'generated' === $kind && ! empty( $clauses ) && 'generated' === end( $clauses )['kind'] ) {
				continue; // GENERATED ALWAYS AS: one clause.
			}

			$clauses[] = [ 'kind' => $kind, 'start' => $j ];

			if ( 'constraint' === $kind ) {
				$naming = 2;
			}
		}

		$last_end = $count > 0 ? $tokens[ $count - 1 ]['offset'] + strlen( $tokens[ $count - 1 ]['text'] ) : 0;

		foreach ( $clauses as $i => $clause ) {
			$from = $tokens[ $clause['start'] ]['offset'];
			$to   = isset( $clauses[ $i + 1 ] ) ? $tokens[ $clauses[ $i + 1 ]['start'] ]['offset'] : $last_end;

			$clauses[ $i ]['sql'] = rtrim( substr( $definition, $from, $to - $from ) );
		}

		return $clauses;
	}

	/**
	 * Remove one definition, keeping the text around the list intact.
	 *
	 * @param int $index The definition's position.
	 * @return static
	 */
	private function remove_definition( int $index ) : static {
		$count = count( $this->definitions );

		if ( $count < 2 ) {
			throw new InvalidArgumentException( 'A table must keep at least one definition.' );
		}

		// The last definition carries the whitespace before ")"; hand it to the new last one.
		if ( $index === $count - 1 ) {
			$text     = $this->definitions[ $index ];
			$trailing = substr( $text, strlen( rtrim( $text ) ) );

			$this->definitions[ $index - 1 ] = self::trim_end( $this->definitions[ $index - 1 ] ) . $trailing;
		}

		array_splice( $this->definitions, $index, 1 );

		return $this;
	}

	/**
	 * Remove trailing whitespace from a definition.
	 *
	 * When the definition ends with a -- comment, the line break that closes
	 * the comment is kept, so the comma or parenthesis that follows is not
	 * swallowed by the comment.
	 *
	 * @param string $text The definition, as written.
	 * @return string
	 */
	private static function trim_end( string $text ) : string {
		$trimmed = rtrim( $text );
		$tokens  = self::tokenize( $trimmed );
		$last    = end( $tokens );
		$after   = false === $last ? $trimmed : substr( $trimmed, $last['offset'] + strlen( $last['text'] ) );

		return str_contains( $after, '--' ) ? $trimmed . "\n" : $trimmed;
	}

	/**
	 * Read a definition as a table constraint.
	 *
	 * @param string $definition One definition, as written.
	 * @return array{name: ?string, type: string, columns: string[], references_table: ?string, references_columns: string[], sql: string}|null Null for a column definition.
	 */
	private static function read_constraint( string $definition ) : ?array {
		$tokens = self::tokenize( $definition );

		if ( empty( $tokens ) ) {
			return null;
		}

		$name  = null;
		$first = 0;

		if ( 'CONSTRAINT' === self::keyword( $tokens[0] ) ) {
			if ( ! isset( $tokens[1], $tokens[2] ) ) {
				return null;
			}

			$name  = self::unquote( $tokens[1] );
			$first = 2;
		}

		$type = self::CONSTRAINT_KEYWORDS[ self::keyword( $tokens[ $first ] ) ] ?? null;

		if ( null === $type ) {
			return null;
		}

		$columns            = [];
		$references_table   = null;
		$references_columns = [];

		if ( 'check' !== $type ) {
			[ $columns, $next ] = self::read_column_list( $tokens, $first + 1 );

			if ( 'foreign' === $type ) {
				for ( $i = $next; isset( $tokens[ $i ] ); $i++ ) {
					if ( 'REFERENCES' === self::keyword( $tokens[ $i ] ) && isset( $tokens[ $i + 1 ] ) ) {
						$references_table        = self::unquote( $tokens[ $i + 1 ] );
						[ $references_columns ]  = '(' === ( $tokens[ $i + 2 ]['text'] ?? '' ) ? self::read_column_list( $tokens, $i + 2 ) : [ [] ];
						break;
					}
				}
			}
		}

		return [
			'name'               => $name,
			'type'               => $type,
			'columns'            => $columns,
			'references_table'   => $references_table,
			'references_columns' => $references_columns,
			'sql'                => trim( $definition ),
		];
	}

	/**
	 * Read the parenthesised column list that starts at or after a token.
	 *
	 * Each entry's name is its first token, so COLLATE, ASC and DESC after a
	 * column name are skipped.
	 *
	 * @param array<int, array{text: string, offset: int, quoted: bool}> $tokens The tokens.
	 * @param int                                                       $from   Where to start looking for "(".
	 * @return array{0: string[], 1: int} The column names, and the position after ")".
	 */
	private static function read_column_list( array $tokens, int $from ) : array {
		$count = count( $tokens );
		$i     = $from;

		while ( $i < $count && '(' !== $tokens[ $i ]['text'] ) {
			$i++;
		}

		$columns    = [];
		$depth      = 0;
		$expect_new = true;

		for ( ; $i < $count; $i++ ) {
			$text = $tokens[ $i ]['text'];

			if ( '(' === $text ) {
				$depth++;
				continue;
			}

			if ( ')' === $text ) {
				$depth--;
				if ( 0 === $depth ) {
					return [ $columns, $i + 1 ];
				}
				continue;
			}

			if ( 1 === $depth && ',' === $text ) {
				$expect_new = true;
				continue;
			}

			if ( 1 === $depth && $expect_new ) {
				$columns[]  = self::unquote( $tokens[ $i ] );
				$expect_new = false;
			}
		}

		return [ $columns, $count ];
	}

	/**
	 * Split SQL into tokens, skipping whitespace and comments.
	 *
	 * A token is a word, a quoted identifier ("x", `x`, [x]), a string ('x')
	 * or a single other character. Each keeps its byte offset in $sql.
	 *
	 * @param string $sql The SQL.
	 * @return array<int, array{text: string, offset: int, quoted: bool}>
	 */
	private static function tokenize( string $sql ) : array {
		$tokens = [];
		$length = strlen( $sql );
		$i      = 0;

		while ( $i < $length ) {
			$char = $sql[ $i ];

			if ( ctype_space( $char ) ) {
				$i++;
				continue;
			}

			if ( '-' === $char && '-' === ( $sql[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $sql, "\n", $i );
				$i   = false === $end ? $length : $end + 1;
				continue;
			}

			if ( '/' === $char && '*' === ( $sql[ $i + 1 ] ?? '' ) ) {
				$end = strpos( $sql, '*/', $i + 2 );
				$i   = false === $end ? $length : $end + 2;
				continue;
			}

			$close = match ( $char ) {
				'"'     => '"',
				'`'     => '`',
				"'"     => "'",
				'['     => ']',
				default => null,
			};

			if ( null !== $close ) {
				$end = $i + 1;

				while ( $end < $length ) {
					if ( $sql[ $end ] === $close ) {
						// A doubled quote is an escaped quote, except inside [...].
						if ( ']' !== $close && ( $sql[ $end + 1 ] ?? '' ) === $close ) {
							$end += 2;
							continue;
						}
						break;
					}
					$end++;
				}

				$tokens[] = [ 'text' => substr( $sql, $i, $end - $i + 1 ), 'offset' => $i, 'quoted' => "'" !== $char ];
				$i        = $end + 1;
				continue;
			}

			if ( ctype_alnum( $char ) || '_' === $char || '$' === $char || ord( $char ) > 127 ) {
				$end = $i;

				while ( $end < $length && ( ctype_alnum( $sql[ $end ] ) || '_' === $sql[ $end ] || '$' === $sql[ $end ] || ord( $sql[ $end ] ) > 127 ) ) {
					$end++;
				}

				$tokens[] = [ 'text' => substr( $sql, $i, $end - $i ), 'offset' => $i, 'quoted' => false ];
				$i        = $end;
				continue;
			}

			$tokens[] = [ 'text' => $char, 'offset' => $i, 'quoted' => false ];
			$i++;
		}

		return $tokens;
	}

	/**
	 * A token as an upper-case keyword, or '' when it is quoted.
	 *
	 * @param array{text: string, quoted: bool} $token
	 * @return string
	 */
	private static function keyword( array $token ) : string {
		return $token['quoted'] ? '' : strtoupper( $token['text'] );
	}

	/**
	 * An identifier token's name, without quotes.
	 *
	 * @param array{text: string, quoted: bool} $token
	 * @return string
	 */
	private static function unquote( array $token ) : string {
		$text = $token['text'];

		if ( ! $token['quoted'] ) {
			return $text;
		}

		$open  = $text[0];
		$inner = substr( $text, 1, -1 );

		return '[' === $open ? $inner : str_replace( $open . $open, $open, $inner );
	}

	/**
	 * Quote an identifier for SQLite.
	 *
	 * @param string $identifier The identifier.
	 * @return string
	 */
	private static function quote( string $identifier ) : string {
		return '"' . str_replace( '"', '""', $identifier ) . '"';
	}

	/**
	 * Whether two identifiers name the same object, ignoring ASCII case as SQLite does.
	 *
	 * @param string $a
	 * @param string $b
	 * @return bool
	 */
	private static function same_name( string $a, string $b ) : bool {
		return 0 === strcasecmp( $a, $b );
	}
}