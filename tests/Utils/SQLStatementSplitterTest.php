<?php
/**
 * SQLStatementSplitter unit tests.
 *
 * Tests the multi-statement SQL query spliter for correct handling of:
 * - String literals (single/double/backtick quotes)
 * - SQL comments (line and block)
 * - Escaped quotes
 * - Multiple statements separated by semicolons
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Utils;

use PHPUnit\Framework\TestCase;
use Callismart\DBPrism\Utils\SQLStatementSplitter;

final class SQLStatementSplitterTest extends TestCase {

	private SQLStatementSplitter $splitter;

	protected function setUp(): void {
		$this->splitter = new SQLStatementSplitter();
	}

	public function test_basic_multi_query(): void {
		$sql = 'SELECT 1; SELECT 2; SELECT 3;';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 3, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
		$this->assertSame( 'SELECT 3', $result[2] );
	}

	public function test_semicolon_in_single_quoted_string(): void {
		$sql = "SELECT 'hello;world'; SELECT 1;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( "SELECT 'hello;world'", $result[0] );
		$this->assertSame( 'SELECT 1', $result[1] );
	}

	public function test_semicolon_in_double_quoted_string(): void {
		$sql = 'SELECT "hello;world"; SELECT 2;';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT "hello;world"', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
	}

	public function test_escaped_quotes_single(): void {
		$sql = "SELECT 'it''s'; SELECT 1;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( "SELECT 'it''s'", $result[0] );
		$this->assertSame( 'SELECT 1', $result[1] );
	}

	public function test_escaped_quotes_double(): void {
		$sql = 'SELECT "he""llo"; SELECT 1;';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT "he""llo"', $result[0] );
		$this->assertSame( 'SELECT 1', $result[1] );
	}

	public function test_line_comment_with_semicolon(): void {
		$sql = "SELECT 1; -- comment; with semicolon\nSELECT 2;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( "SELECT 2", $result[1] );
	}

	public function test_mysql_hash_comment_with_semicolon(): void {
		$sql = "SELECT 1; # comment; with semicolon\nSELECT 2;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
	}

	public function test_block_comment_with_semicolon(): void {
		$sql = 'SELECT 1; /* comment; with; semicolons */ SELECT 2;';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
	}

	public function test_backtick_identifiers(): void {
		$sql = 'SELECT `col;name` FROM `t;able`; SELECT 1;';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertStringContainsString( '`col;name`', $result[0] );
		$this->assertStringContainsString( '`t;able`', $result[0] );
		$this->assertSame( 'SELECT 1', $result[1] );
	}

	public function test_statement_without_trailing_semicolon(): void {
		$sql = 'SELECT 1; SELECT 2';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
	}

	public function test_empty_statements_ignored(): void {
		$sql = "SELECT 1;;;\nSELECT 2;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
	}

	public function test_string_containing_comment_marker(): void {
		$sql = "SELECT 'this is /* not */ a comment'; SELECT 1;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertStringContainsString( '/* not */', $result[0] );
		$this->assertSame( 'SELECT 1', $result[1] );
	}

	public function test_complex_migration_like_query(): void {
		$sql = <<<SQL
		CREATE TABLE users (
			id INT PRIMARY KEY,
			name VARCHAR(100),
			email VARCHAR(100)
		);
		INSERT INTO users VALUES (1, 'John', 'john@example.com; hacker@evil.com');
		SELECT * FROM users;
		SQL;
		$result = $this->splitter->split( $sql );

		$this->assertCount( 3, $result );
		$this->assertStringContainsString( 'CREATE TABLE', $result[0] );
		$this->assertStringContainsString( 'INSERT INTO', $result[1] );
		$this->assertStringContainsString( 'john@example.com; hacker@evil.com', $result[1] );
		$this->assertStringContainsString( 'SELECT * FROM users', $result[2] );
	}

	public function test_empty_input(): void {
		$sql = '';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 0, $result );
	}

	public function test_only_semicolons(): void {
		$sql = ';;;';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 0, $result );
	}

	public function test_whitespace_handling(): void {
		$sql = "  \n\t SELECT 1  \n\t ; \n\t SELECT 2  \t;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
	}

	public function test_multiline_block_comment(): void {
		$sql = <<<SQL
		SELECT 1;
		/*
		Multi-line comment;
		with many semicolons;
		*/
		SELECT 2;
		SQL;
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
	}

	public function test_string_with_multiple_quote_styles(): void {
		$sql = <<<SQL
		INSERT INTO data VALUES ('value1', "value2", `value3`);
		SELECT * FROM table WHERE id = 1;
		SQL;
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertStringContainsString( "'value1'", $result[0] );
		$this->assertStringContainsString( '"value2"', $result[0] );
		$this->assertStringContainsString( '`value3`', $result[0] );
	}

	public function test_insert_with_semicolon_in_data(): void {
		$sql = <<<SQL
		INSERT INTO settings (key, value) VALUES 
			('welcome_msg', 'Hello; Welcome; Enjoy!'),
			('api_endpoint', 'https://api.example.com;v1');
		SELECT * FROM settings;
		SQL;
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertStringContainsString( 'Hello; Welcome; Enjoy!', $result[0] );
		$this->assertStringContainsString( 'https://api.example.com;v1', $result[0] );
	}

	public function test_update_with_semicolon_in_string(): void {
		$sql = "UPDATE users SET notes = 'Status: Active; Level: Admin' WHERE id = 1; SELECT * FROM users;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertStringContainsString( 'Status: Active; Level: Admin', $result[0] );
	}

	public function test_nested_backticks(): void {
		$sql = 'SELECT `col1`, `col2` FROM `table1`; SELECT * FROM `table2`;';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertStringContainsString( '`col1`', $result[0] );
		$this->assertStringContainsString( '`col2`', $result[0] );
	}

	public function test_line_comment_without_newline_at_end(): void {
		$sql = 'SELECT 1; -- final comment';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 1, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
	}

	public function test_carriage_return_line_endings(): void {
		$sql = "SELECT 1; -- comment\rSELECT 2;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
	}

	public function test_mixed_comment_styles(): void {
		$sql = <<<SQL
		SELECT 1; -- line comment
		SELECT 2; # hash comment
		SELECT 3; /* block comment */
		SQL;
		$result = $this->splitter->split( $sql );

		$this->assertCount( 3, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
		$this->assertSame( 'SELECT 3', $result[2] );
	}

	public function test_adjacent_block_comments(): void {
		$sql = 'SELECT 1; /* comment1 */ /* comment2 */ SELECT 2;';
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( 'SELECT 1', $result[0] );
		$this->assertSame( 'SELECT 2', $result[1] );
	}

	public function test_quote_at_end_of_string(): void {
		$sql = "INSERT INTO t VALUES ('test'); SELECT 1;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( "INSERT INTO t VALUES ('test')", $result[0] );
	}

	public function test_multiple_escaped_quotes_in_sequence(): void {
		$sql = "SELECT 'a''b''c'; SELECT 1;";
		$result = $this->splitter->split( $sql );

		$this->assertCount( 2, $result );
		$this->assertSame( "SELECT 'a''b''c'", $result[0] );
	}
}