<?php
/**
 * Base class for the security test suite.
 *
 * Holds the engines every builder-level test runs against, the sentinel
 * used to detect injected text, and the assertions the security tests
 * share.
 *
 * @package Callismart\DBPrism\Tests\Security
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Tests\Security;

use PHPUnit\Framework\TestCase;
use Throwable;

abstract class SecurityTestCase extends TestCase {

	/**
	 * A fragment that only a successful injection could place in the SQL.
	 */
	protected const SENTINEL = 'INJ_7F3A_SENTINEL';

	/**
	 * The engines every builder-level test runs against.
	 *
	 * @return array<string, array{string}>
	 */
	public static function engines() : array {
		return [
			'mysql'  => [ 'mysql' ],
			'pgsql'  => [ 'pgsql' ],
			'sqlite' => [ 'sqlite' ],
		];
	}

	/**
	 * The identifier quote character for an engine.
	 *
	 * @param string $engine Engine name.
	 * @return string
	 */
	protected static function quote_char( string $engine ) : string {
		return 'mysql' === $engine ? '`' : '"';
	}

	/**
	 * Assert a value is sent as a bound parameter, not written into the SQL.
	 *
	 * @param object $intent  The query intent.
	 * @param mixed  $value   The hostile value.
	 * @param string $context A label for the failure message.
	 * @return void
	 */
	protected function assertValueBound( object $intent, mixed $value, string $context ) : void {
		$sql      = $intent->build();
		$bindings = $intent->get_bindings();

		$this->assertContains( $value, $bindings, "{$context}: the value must be a bound parameter" );

		if ( is_string( $value ) ) {
			$this->assertStringNotContainsString( $value, $sql, "{$context}: the value must not appear in the SQL text" );
		}
	}

	/**
	 * Assert hostile input cannot reach the SQL text.
	 *
	 * Safe means one of two things: building threw (the input was rejected),
	 * or the sentinel never reached the SQL (the input was neutralised).
	 *
	 * @param callable $build   Builds and returns the SQL, or throws.
	 * @param string   $context A label for the failure message.
	 * @return void
	 */
	protected function assertNotInjectable( callable $build, string $context ) : void {
		try {
			$sql = $build();
		} catch ( Throwable $e ) {
			// Rejected: a thrown exception is a valid defence.
			$this->addToAssertionCount( 1 );
			return;
		}

		$this->assertStringNotContainsString(
			self::SENTINEL,
			$sql,
			"{$context}: hostile input reached the SQL text, neither bound, rejected nor neutralised"
		);
	}

	/**
	 * Assert an identifier's quote character is escaped by doubling.
	 *
	 * An identifier 'a<q>b' must be written as '<q>a<q><q>b<q>', so the inner
	 * quote cannot close the identifier. A build that throws is also safe:
	 * rejecting the identifier is a valid defence.
	 *
	 * @param callable $build   Builds and returns the SQL, or throws.
	 * @param string   $engine  Engine name.
	 * @param string   $context A label for the failure message.
	 * @return void
	 */
	protected function assertIdentifierEscaped( callable $build, string $engine, string $context ) : void {
		try {
			$sql = $build();
		} catch ( Throwable $e ) {
			$this->addToAssertionCount( 1 );
			return;
		}

		$quote = self::quote_char( $engine );

		$this->assertStringContainsString(
			$quote . 'a' . $quote . $quote . 'b' . $quote,
			$sql,
			"{$context}: the quote character in an identifier must be escaped by doubling"
		);
	}

	/**
	 * Assert a callable throws an exception of the given class.
	 *
	 * @param string   $class   The expected exception class.
	 * @param callable $action  The code that must throw.
	 * @param string   $context A label for the failure message.
	 * @return void
	 */
	protected function assertThrows( string $class, callable $action, string $context ) : void {
		try {
			$action();
		} catch ( Throwable $e ) {
			$this->assertInstanceOf( $class, $e, "{$context}: threw " . get_class( $e ) . " instead of {$class}" );
			return;
		}

		$this->fail( "{$context}: expected {$class}, nothing was thrown" );
	}
}