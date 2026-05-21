<?php
/**
 * SQL Query Splitter
 *
 * Parses multi-statement SQL strings safely across SQL dialects.
 *
 * @package Callismart\DBPrism\Utilities
 */

declare(strict_types=1);

namespace Callismart\DBPrism\Utils;

class SQLStatementSplitter {

	public function split( string $sql ): array {
		$queries = [];
		$current = '';

		$in_string = false;
		$string_char = '';

		$in_line_comment = false;
		$in_block_comment = false;

		$length = strlen( $sql );

		for ( $i = 0; $i < $length; $i++ ) {

			$char = $sql[$i];
			$next = $i + 1 < $length ? $sql[$i + 1] : '';

			/**
			 * =========================================================
			 * LINE COMMENT HANDLING
			 * =========================================================
			 */
			if ( $in_line_comment ) {

				if ( $char === "\r" && $next === "\n" ) {
					$in_line_comment = false;
					$i++;
					continue;
				}

				if ( $char === "\n" || $char === "\r" ) {
					$in_line_comment = false;
				}

				continue;
			}

			/**
			 * =========================================================
			 * BLOCK COMMENT HANDLING
			 * =========================================================
			 */
			if ( $in_block_comment ) {

				if ( $char === '*' && $next === '/' ) {
					$in_block_comment = false;
					$i++;
				}

				continue;
			}

			/**
			 * =========================================================
			 * STRING HANDLING
			 * =========================================================
			 */
			if ( $in_string ) {

				$current .= $char;

				// escape backslash
				if ( $char === '\\' ) {
					if ( $i + 1 < $length ) {
						$current .= $sql[$i + 1];
						$i++;
					}
					continue;
				}

				// doubled quote escape
				if ( $char === $string_char && $next === $string_char ) {
					$current .= $next;
					$i++;
					continue;
				}

				if ( $char === $string_char ) {
					$in_string = false;
					$string_char = '';
				}

				continue;
			}

			/**
			 * =========================================================
			 * START COMMENTS
			 * =========================================================
			 */
			if ( $char === '-' && $next === '-' ) {
				$in_line_comment = true;
				$i++;
				continue;
			}

			if ( $char === '#' ) {
				$in_line_comment = true;
				continue;
			}

			if ( $char === '/' && $next === '*' ) {
				$in_block_comment = true;
				$i++;
				continue;
			}

			/**
			 * =========================================================
			 * STRING START
			 * =========================================================
			 */
			if ( $char === "'" || $char === '"' || $char === '`' ) {
				$in_string = true;
				$string_char = $char;
				$current .= $char;
				continue;
			}

			/**
			 * =========================================================
			 * STATEMENT TERMINATOR
			 * =========================================================
			 */
			if ( $char === ';' ) {

				$trimmed = trim( $current );

				if ( $trimmed !== '' ) {
					$queries[] = $trimmed;
				}

				$current = '';
				continue;
			}

			/**
			 * =========================================================
			 * CORE FIX: IGNORE LEADING WHITESPACE CLEANLY
			 * =========================================================
			 */
			if ( $current === '' && ctype_space( $char ) ) {
				continue;
			}

			$current .= $char;
		}

		$trimmed = trim( $current );

		if ( $trimmed !== '' ) {
			$queries[] = $trimmed;
		}

		return $queries;
	}
}