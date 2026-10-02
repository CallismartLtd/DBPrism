<?php
/**
 * SQL statement splitter class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Utils
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Utils;

use InvalidArgumentException;

/**
 * Splits a multi-statement SQL string into individual statements.
 *
 * The splitter follows the lexical rules of the chosen dialect, so statement
 * terminators inside strings, quoted identifiers, comments and routine bodies
 * are never treated as statement boundaries:
 *
 * | Rule                                  | generic | mysql | pgsql | sqlite |
 * |---------------------------------------|:-------:|:-----:|:-----:|:------:|
 * | '...' and "..." with doubled-quote escape |  yes  |  yes  |  yes  |  yes   |
 * | Backslash escapes in '...' and "..."  |    -    |  yes  | E'...' only | - |
 * | `...` quoted identifiers              |   yes   |  yes  |   -   |  yes   |
 * | [...] quoted identifiers              |    -    |   -   |   -   |  yes   |
 * | $tag$...$tag$ dollar-quoted strings   |    -    |   -   |  yes  |   -    |
 * | -- line comments                      |   yes   | "-- " + whitespace | yes | yes |
 * | # line comments                       |    -    |  yes  |   -   |   -    |
 * | Nested /* ... *\/ comments            |    -    |   -   |  yes  |   -    |
 * | /*! ... *\/ and /*+ ... *\/ kept as SQL |  -    |  yes  |   -   |   -    |
 * | DELIMITER directive                   |    -    |  yes  |   -   |   -    |
 * | BEGIN ... END bodies of CREATE TRIGGER |  yes   |  yes  |   -   |  yes   |
 * | BEGIN ... END bodies of CREATE PROCEDURE / FUNCTION / EVENT | - | yes | - | - |
 *
 * PostgreSQL routine bodies are dollar-quoted, so they need no block tracking;
 * a top-level `BEGIN;` there is a transaction statement.
 *
 * Comments are removed from the output (replaced by a single space so the
 * tokens on either side stay apart), except MySQL executable comments and
 * optimizer hints, which carry SQL. Statements are trimmed; empty
 * statements are dropped; the terminator is not included.
 *
 * Example:
 *
 *   $statements = ( new SQLStatementSplitter( 'sqlite' ) )->split( $sql );
 *
 * @package Callismart\DBPrism\Utils
 */
final class SQLStatementSplitter {

	/**
	 * Dialect identifiers.
	 */
	public const DIALECT_GENERIC = 'generic';
	public const DIALECT_MYSQL   = 'mysql';
	public const DIALECT_PGSQL   = 'pgsql';
	public const DIALECT_SQLITE  = 'sqlite';

	/**
	 * Accepted dialect names and aliases, mapped to their dialect.
	 *
	 * @var array<string, string>
	 */
	public const DIALECTS = array(
		'generic'    => self::DIALECT_GENERIC,
		'mysql'      => self::DIALECT_MYSQL,
		'mariadb'    => self::DIALECT_MYSQL,
		'pgsql'      => self::DIALECT_PGSQL,
		'postgres'   => self::DIALECT_PGSQL,
		'postgresql' => self::DIALECT_PGSQL,
		'sqlite'     => self::DIALECT_SQLITE,
		'sqlite3'    => self::DIALECT_SQLITE,
	);

	/**
	 * Default statement terminator.
	 */
	public const DEFAULT_DELIMITER = ';';

	/**
	 * Words that open a block inside a routine or trigger body, per dialect.
	 *
	 * @var array<string, string[]>
	 */
	private const BLOCK_OPENERS = array(
		self::DIALECT_GENERIC => array( 'BEGIN', 'CASE' ),
		self::DIALECT_MYSQL   => array( 'BEGIN', 'CASE', 'IF', 'LOOP', 'WHILE', 'REPEAT' ),
		self::DIALECT_PGSQL   => array(),
		self::DIALECT_SQLITE  => array( 'BEGIN', 'CASE' ),
	);

	/**
	 * Objects whose CREATE statement can carry a BEGIN ... END body, per dialect.
	 *
	 * @var array<string, string[]>
	 */
	private const BLOCK_OBJECTS = array(
		self::DIALECT_GENERIC => array( 'TRIGGER' ),
		self::DIALECT_MYSQL   => array( 'TRIGGER', 'PROCEDURE', 'FUNCTION', 'EVENT' ),
		self::DIALECT_PGSQL   => array(),
		self::DIALECT_SQLITE  => array( 'TRIGGER' ),
	);

	/**
	 * How many leading words of a statement are inspected to find the CREATE target.
	 */
	private const HEAD_WORDS = 8;

	/**
	 * The dialect in use.
	 *
	 * @var string
	 */
	private string $dialect;

	/*
	|------------------------------
	| Per-split state
	|------------------------------
	*/

	/**
	 * SQL being split.
	 *
	 * @var string
	 */
	private string $sql = '';

	/**
	 * Length of the SQL in bytes.
	 *
	 * @var int
	 */
	private int $length = 0;

	/**
	 * Statements found so far.
	 *
	 * @var string[]
	 */
	private array $statements = array();

	/**
	 * The statement being collected.
	 *
	 * @var string
	 */
	private string $buffer = '';

	/**
	 * Current statement terminator.
	 *
	 * @var string
	 */
	private string $delimiter = self::DEFAULT_DELIMITER;

	/**
	 * Leading words of the current statement, upper-cased.
	 *
	 * @var string[]
	 */
	private array $head = array();

	/**
	 * Whether the current statement can carry a BEGIN ... END body.
	 *
	 * @var bool
	 */
	private bool $has_body = false;

	/**
	 * Nesting depth of BEGIN/CASE/... blocks in the current statement.
	 *
	 * @var int
	 */
	private int $depth = 0;

	/**
	 * Whether the previous word was END (so "END IF" does not open a block).
	 *
	 * @var bool
	 */
	private bool $after_end = false;

	/**
	 * Class constructor.
	 *
	 * @param string $dialect One of the DIALECT_* constants or an alias in DIALECTS (case-insensitive).
	 * @throws InvalidArgumentException When the dialect is not supported.
	 */
	public function __construct( string $dialect = self::DIALECT_GENERIC ) {
		$key = strtolower( trim( $dialect ) );

		if ( ! isset( self::DIALECTS[ $key ] ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Unsupported SQL dialect "%s". Supported: %s.', $dialect, implode( ', ', array_keys( self::DIALECTS ) ) )
			);
		}

		$this->dialect = self::DIALECTS[ $key ];
	}

	/**
	 * The dialect this splitter follows.
	 *
	 * @return string One of the DIALECT_* constants.
	 */
	public function get_dialect() : string {
		return $this->dialect;
	}

	/**
	 * Split SQL into individual statements.
	 *
	 * @param string $sql One or more SQL statements.
	 * @return list<string> Trimmed statements without their terminators.
	 */
	public function split( string $sql ) : array {
		$this->sql        = $sql;
		$this->length     = strlen( $sql );
		$this->statements = array();
		$this->delimiter  = self::DEFAULT_DELIMITER;
		$this->reset_statement();

		$i = 0;

		while ( $i < $this->length ) {
			$i = $this->step( $i );
		}

		$this->flush();

		$statements = $this->statements;

		// Release the input; the instance may be reused.
		$this->sql        = '';
		$this->statements = array();

		return $statements;
	}

	/*
	|-----------
	| Scanning
	|-----------
	*/

	/**
	 * Consume one token starting at the given offset.
	 *
	 * @param int $i Offset.
	 * @return int Offset after the token.
	 */
	private function step( int $i ) : int {
		$char = $this->sql[ $i ];
		$next = $this->sql[ $i + 1 ] ?? '';

		if ( self::DIALECT_MYSQL === $this->dialect && '' === trim( $this->buffer ) ) {
			$end = $this->delimiter_directive( $i );

			if ( null !== $end ) {
				return $end;
			}
		}

		// Line comments.
		if ( '-' === $char && '-' === $next && $this->is_dash_comment( $i ) ) {
			return $this->skip_line_comment( $i );
		}

		if ( '#' === $char && self::DIALECT_MYSQL === $this->dialect ) {
			return $this->skip_line_comment( $i );
		}

		// Block comments.
		if ( '/' === $char && '*' === $next ) {
			return $this->block_comment( $i );
		}

		// Strings and quoted identifiers.
		$close = $this->quote_close( $char );

		if ( null !== $close ) {
			$end = $this->scan_quoted( $i, $close, $this->backslash_escapes( $i, $char ) );
			$this->buffer .= substr( $this->sql, $i, $end - $i );

			return $end;
		}

		// PostgreSQL dollar-quoted strings.
		if ( '$' === $char && self::DIALECT_PGSQL === $this->dialect ) {
			$end = $this->dollar_quoted( $i );

			if ( null !== $end ) {
				$this->buffer .= substr( $this->sql, $i, $end - $i );
				return $end;
			}
		}

		// Statement terminator, outside any BEGIN ... END body.
		if ( 0 === $this->depth && 0 === substr_compare( $this->sql, $this->delimiter, $i, strlen( $this->delimiter ) ) ) {
			$this->flush();
			return $i + strlen( $this->delimiter );
		}

		// Words: needed to recognize routine and trigger bodies.
		if ( ctype_alpha( $char ) || '_' === $char ) {
			return $this->word( $i );
		}

		// Skip leading whitespace of a statement.
		if ( '' === $this->buffer && ctype_space( $char ) ) {
			return $i + 1;
		}

		$this->buffer .= $char;

		return $i + 1;
	}

	/**
	 * Handle a MySQL DELIMITER directive at the start of a statement.
	 *
	 * @param int $i Offset.
	 * @return int|null Offset after the directive line, or null when there is none.
	 */
	private function delimiter_directive( int $i ) : ?int {
		if ( ! preg_match( '/\GDELIMITER[ \t]+(\S+)[^\r\n]*(?:\r\n|\r|\n|$)/i', $this->sql, $match, 0, $i ) ) {
			return null;
		}

		$this->delimiter = $match[1];
		$this->buffer    = '';

		return $i + strlen( $match[0] );
	}

	/**
	 * Whether "--" at this offset starts a comment.
	 *
	 * MySQL requires whitespace or a control character after "--"; elsewhere
	 * "--" always starts a comment.
	 *
	 * @param int $i Offset of the first "-".
	 * @return bool
	 */
	private function is_dash_comment( int $i ) : bool {
		if ( self::DIALECT_MYSQL !== $this->dialect ) {
			return true;
		}

		$after = $this->sql[ $i + 2 ] ?? '';

		return '' === $after || ctype_space( $after ) || ctype_cntrl( $after );
	}

	/**
	 * Skip a line comment, leaving the line break in place.
	 *
	 * @param int $i Offset of the comment start.
	 * @return int Offset of the line break, or the end of the input.
	 */
	private function skip_line_comment( int $i ) : int {
		$end = strcspn( $this->sql, "\r\n", $i );

		return $i + $end;
	}

	/**
	 * Handle a block comment.
	 *
	 * MySQL executable comments and optimizer hints are kept as SQL; other
	 * comments become a single space. PostgreSQL comments nest.
	 *
	 * @param int $i Offset of "/*".
	 * @return int Offset after the comment.
	 */
	private function block_comment( int $i ) : int {
		$marker = $this->sql[ $i + 2 ] ?? '';

		if ( self::DIALECT_MYSQL === $this->dialect && ( '!' === $marker || '+' === $marker ) ) {
			$close = strpos( $this->sql, '*/', $i + 3 );
			$end   = false === $close ? $this->length : $close + 2;

			$this->buffer .= substr( $this->sql, $i, $end - $i );

			return $end;
		}

		$end = self::DIALECT_PGSQL === $this->dialect
			? $this->nested_comment_end( $i )
			: ( false === ( $close = strpos( $this->sql, '*/', $i + 2 ) ) ? $this->length : $close + 2 );

		if ( '' !== $this->buffer && ! ctype_space( substr( $this->buffer, -1 ) ) ) {
			$this->buffer .= ' ';
		}

		return $end;
	}

	/**
	 * Find the end of a nested block comment.
	 *
	 * @param int $i Offset of the opening "/*".
	 * @return int Offset after the matching "*\/", or the end of the input.
	 */
	private function nested_comment_end( int $i ) : int {
		$depth = 0;

		while ( $i < $this->length ) {
			$pair = substr( $this->sql, $i, 2 );

			if ( '/*' === $pair ) {
				++$depth;
				$i += 2;
			} elseif ( '*/' === $pair ) {
				$i += 2;

				if ( 0 === --$depth ) {
					return $i;
				}
			} else {
				++$i;
			}
		}

		return $this->length;
	}

	/**
	 * The closing character for a quote that opens a string or identifier here.
	 *
	 * @param string $char The current character.
	 * @return string|null Null when the character does not open a quoted token.
	 */
	private function quote_close( string $char ) : ?string {
		return match ( true ) {
			"'" === $char, '"' === $char => $char,
			'`' === $char && self::DIALECT_PGSQL !== $this->dialect => '`',
			'[' === $char && self::DIALECT_SQLITE === $this->dialect => ']',
			default => null,
		};
	}

	/**
	 * Whether backslash escapes apply to the quoted token opening at this offset.
	 *
	 * @param int    $i     Offset of the opening quote.
	 * @param string $quote The opening quote.
	 * @return bool
	 */
	private function backslash_escapes( int $i, string $quote ) : bool {
		if ( self::DIALECT_MYSQL === $this->dialect ) {
			return "'" === $quote || '"' === $quote;
		}

		// PostgreSQL escape strings: E'...' (the E must not end a longer word).
		if ( self::DIALECT_PGSQL === $this->dialect && "'" === $quote && $i > 0 ) {
			$prefix = $this->sql[ $i - 1 ];
			$before = $i > 1 ? $this->sql[ $i - 2 ] : '';

			return ( 'e' === $prefix || 'E' === $prefix ) && ! $this->is_word_char( $before );
		}

		return false;
	}

	/**
	 * Find the end of a quoted string or identifier.
	 *
	 * A doubled closing quote is an escaped quote. An unterminated token runs
	 * to the end of the input and is left for the database to report.
	 *
	 * @param int    $i         Offset of the opening quote.
	 * @param string $close     Closing quote.
	 * @param bool   $backslash Whether a backslash escapes the next character.
	 * @return int Offset after the closing quote.
	 */
	private function scan_quoted( int $i, string $close, bool $backslash ) : int {
		$open = $this->sql[ $i ];
		$j    = $i + 1;

		while ( $j < $this->length ) {
			$char = $this->sql[ $j ];

			if ( $backslash && '\\' === $char ) {
				$j += 2;
				continue;
			}

			if ( $close === $char ) {
				if ( $open === $close && ( $this->sql[ $j + 1 ] ?? '' ) === $close ) {
					$j += 2;
					continue;
				}

				return $j + 1;
			}

			++$j;
		}

		return $this->length;
	}

	/**
	 * Find the end of a PostgreSQL dollar-quoted string starting here.
	 *
	 * @param int $i Offset of "$".
	 * @return int|null Offset after the closing tag, or null when no dollar quote starts here.
	 */
	private function dollar_quoted( int $i ) : ?int {
		// "$1" parameters and identifiers containing "$" are not dollar quotes.
		if ( $i > 0 && $this->is_word_char( $this->sql[ $i - 1 ] ) ) {
			return null;
		}

		if ( ! preg_match( '/\G\$(?:[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)?\$/', $this->sql, $match, 0, $i ) ) {
			return null;
		}

		$tag   = $match[0];
		$close = strpos( $this->sql, $tag, $i + strlen( $tag ) );

		return false === $close ? $this->length : $close + strlen( $tag );
	}

	/**
	 * Consume a word and track BEGIN ... END bodies.
	 *
	 * @param int $i Offset of the first character.
	 * @return int Offset after the word.
	 */
	private function word( int $i ) : int {
		$length = strspn( $this->sql, 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQRSTUVWXYZ0123456789_$', $i );
		$word   = substr( $this->sql, $i, $length );
		$upper  = strtoupper( $word );
		$end    = $i + $length;

		$this->buffer .= $word;

		if ( count( $this->head ) < self::HEAD_WORDS ) {
			$this->head[] = $upper;

			if ( 'CREATE' === $this->head[0] && in_array( $upper, self::BLOCK_OBJECTS[ $this->dialect ], true ) ) {
				$this->has_body = true;
			}
		}

		if ( ! $this->has_body ) {
			return $end;
		}

		if ( 'END' === $upper ) {
			$this->depth     = max( 0, $this->depth - 1 );
			$this->after_end = true;

			return $end;
		}

		$opens = ! $this->after_end
			&& in_array( $upper, self::BLOCK_OPENERS[ $this->dialect ], true )
			&& ( 'BEGIN' === $upper || 'CASE' === $upper || ! $this->followed_by_parenthesis( $end ) );

		if ( $opens ) {
			++$this->depth;
		}

		$this->after_end = false;

		return $end;
	}

	/**
	 * Whether the next non-space character is "(" (a function call such as IF(...)).
	 *
	 * @param int $i Offset after a word.
	 * @return bool
	 */
	private function followed_by_parenthesis( int $i ) : bool {
		$i += strspn( $this->sql, " \t\r\n", $i );

		return '(' === ( $this->sql[ $i ] ?? '' );
	}

	/**
	 * Whether a character can be part of an identifier.
	 *
	 * @param string $char Single character.
	 * @return bool
	 */
	private function is_word_char( string $char ) : bool {
		return '' !== $char && ( ctype_alnum( $char ) || '_' === $char || '$' === $char || ord( $char ) >= 0x80 );
	}

	/*
	|--------------
	| Statements
	|--------------
	*/

	/**
	 * Store the current statement, if any, and start a new one.
	 *
	 * @return void
	 */
	private function flush() : void {
		$statement = trim( $this->buffer );

		if ( '' !== $statement ) {
			$this->statements[] = $statement;
		}

		$this->reset_statement();
	}

	/**
	 * Reset the per-statement state.
	 *
	 * @return void
	 */
	private function reset_statement() : void {
		$this->buffer    = '';
		$this->head      = array();
		$this->has_body  = false;
		$this->depth     = 0;
		$this->after_end = false;
	}
}