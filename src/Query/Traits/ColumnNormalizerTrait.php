<?php
/**
 * Column Normalizer Trait file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Query\Traits;

/**
 * Trait ColumnNormalizerTrait
 *
 * This trait defines the canonical parsing and normalization blueprint for all columns,
 * expressions, and literals supplied during fluent query building across the framework.
 * It ensures cross-engine compliance (MySQL, PostgreSQL, SQLite) by isolating field aliases
 * and automatically transforming double-quoted string literals into ANSI-compliant single quotes.
 *
 * @package Callismart\DBPrism\Query\Traits
 */
trait ColumnNormalizerTrait {

    /**
     * Normalize columns and expressions, extracting aliases when present.
     *
     * This is the framework's canonical parsing rule for columns. It breaks strings down
     * into structural meta components, detecting function calls or raw text values.
     *
     * @param  string $column The raw column string or expression input.
     * @return array{type: string, value: string, alias?: string} Normalized structural descriptor mapping.
     */
    protected function normalize_column( string $column ) : array {
        $column = trim( $column );

        if ( '*' === $column || '' === $column ) {
            return [
                'type'  => 'column',
                'value' => '*',
            ];
        }

        // Group 1: The core field or expression
        // Group 2: The raw alias string (if present)
        $pattern = '/^(.+?)(?:\s+(?:as\s+)?(\w+))?$/i';

        if ( ! preg_match( $pattern, $column, $matches ) ) {
            // Fallback safety net.
            return [
                'type'  => 'column',
                'value' => $column,
            ];
        }

        $value = trim( $matches[1] );
        $alias = isset( $matches[2] ) ? trim( $matches[2] ) : null;

        // Normalize double-quoted string literals to ANSI single-quoted form.
        if ( static::is_double_quoted( $value ) ) {
            $value = static::normalize_string_literal( $value );
        }

        $result = [
            'type'  => static::is_sql_expression( $value ) ? 'expression' : 'column',
            'value' => $value,
        ];

        if ( null !== $alias ) {
            $result['alias'] = $alias;
        }

        return $result;
    }

    /*
    |--------------------------------------
    | Canonical SQL expression predicates
    |--------------------------------------
    */

    /**
     * Determine whether a value string is a SQL expression (function call or
     * quoted string literal) rather than a plain column identifier.
     *
     * This is the canonical rule for expression detection across the framework.
     * Use this instead of re-deriving the check inline in other query-building code.
     *
     * @param  string $value A pre-trimmed column or expression string.
     * @return bool
     */
    protected static function is_sql_expression( string $value ) : bool {
        return static::is_single_quoted( $value ) || static::is_functional_expression( $value );
    }

    /**
     * Determine whether a value is a raw SQL literal or protected expression segment.
     * 
     * @param string $value
     * @return bool
     */
    protected static function is_sql_literal( string $value ) : bool {
        $value = trim( $value );
        $upper = \strtoupper( $value );

        // Instantly catch standalone reserved markers.
        $reserved = [ 'NULL', 'TRUE', 'FALSE', '*' ];
        if ( in_array( $upper, $reserved, true ) ) {
            return true;
        }

        // Structural Interception: Catch Aliases (e.g., "NULL AS email", "1 AS flag")
        // If the base fragment before the "AS" keyword is a 
        // literal, the whole string is a literal block.
        if ( \stripos( $value, ' AS ' ) !== false ) {
            $segments = \preg_split( '/\s+AS\s+/i', $value );
            $base_fragment = \trim( $segments[0] );
            
            return self::is_sql_literal( $base_fragment );
        }

        // 3. Catch structural clauses like "DISTINCT *"
        if ( \strpos( $upper, 'DISTINCT ' ) === 0 ) {
            $after_distinct = \trim( \substr( $value, 8 ) );
            return self::is_sql_literal( $after_distinct );
        }

        return false;
    }

    /**
     * Determine whether a value is wrapped in single quotes (ANSI string literal).
     *
     * @param  string $value
     * @return bool
     */
    protected static function is_single_quoted( string $value ) : bool {
        return str_starts_with( $value, "'" ) && str_ends_with( $value, "'" );
    }

    /**
     * Determine whether a value is wrapped in double quotes.
     *
     * @param  string $value
     * @return bool
     */
    protected static function is_double_quoted( string $value ) : bool {
        return str_starts_with( $value, '"' ) && str_ends_with( $value, '"' );
    }

    /**
     * Determine whether a value is a functional SQL expression (e.g. COUNT(*),
     * COALESCE(a, b), LOWER(name)).
     *
     * @param  string $value
     * @return bool
     */
    protected static function is_functional_expression( string $value ) : bool {
        return (bool) preg_match( '/\w+\s*\(.*\)/', $value );
    }

    /**
     * Convert a double-quoted string literal into an ANSI-compliant single-quoted
     * literal, escaping any interior single quotes to prevent injection.
     *
     * @param  string $value A double-quoted string (e.g. `"O'Brien"`).
     * @return string        ANSI single-quoted equivalent (e.g. `'O''Brien'`).
     */
    protected static function normalize_string_literal( string $value ) : string {
        $unquoted = substr( $value, 1, -1 );
        return "'" . str_replace( "'", "''", $unquoted ) . "'";
    }
}