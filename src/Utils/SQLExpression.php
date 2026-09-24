<?php
/**
 * SQL Expression DTO for standard ANSI database functional expressions.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism
 * @since 0.2.0
 */
declare( strict_types=1 );

namespace Callismart\DBPrism\Utils;

/**
 * Encapsulates raw ANSI SQL functional expressions and keywords.
 */
class SQLExpression extends DefaultColumnValue {

    /**
     * Create a generic SQL function call with optional arguments.
     * All arguments are string-cast as raw tokens without internal quoting.
     *
     * @param string $function_name The name of the SQL function.
     * @param array  $args          The positional arguments for the function call.
     * @return static
     */
    public static function func( string $function_name, array $args = [] ): static {
        if ( empty( $args ) ) {
            return static::expression( strtoupper( $function_name ) . '()' );
        }

        $tokens = array_map( static fn( mixed $arg ) => (string) $arg, $args );

        return static::expression( sprintf( '%s(%s)', strtoupper( $function_name ), implode( ', ', $tokens ) ) );
    }

    /* -----------------------------------------------------------------
     | Date & Time Keywords & Functions (ANSI SQL)
     | ----------------------------------------------------------------- */

    /**
     * Get the standard ANSI SQL CURRENT_TIMESTAMP keyword expression (no parentheses).
     *
     * @return static
     */
    public static function currentTimestamp(): static {
        return static::expression( 'CURRENT_TIMESTAMP' );
    }

    /**
     * Get the standard ANSI SQL CURRENT_DATE keyword expression (no parentheses).
     *
     * @return static
     */
    public static function currentDate(): static {
        return static::expression( 'CURRENT_DATE' );
    }

    /**
     * Get the standard ANSI SQL CURRENT_TIME keyword expression (no parentheses).
     *
     * @return static
     */
    public static function currentTime(): static {
        return static::expression( 'CURRENT_TIME' );
    }

    /**
     * Create an EXTRACT(field FROM source) SQL expression.
     *
     * @param string                  $field  The date/time unit to extract (e.g., 'YEAR', 'MONTH', 'DAY').
     * @param string|DefaultColumnValue $source The column name or value expression source.
     * @return static
     */
    public static function extract( string $field, string|DefaultColumnValue $source ): static {
        return static::expression( sprintf( 'EXTRACT(%s FROM %s)', strtoupper( $field ), (string) $source ) );
    }

    /* -----------------------------------------------------------------
     | String Functions (ANSI SQL)
     | ----------------------------------------------------------------- */

    /**
     * Create an UPPER() scalar function expression.
     *
     * @param string|DefaultColumnValue $str The string target or column identifier.
     * @return static
     */
    public static function upper( string|DefaultColumnValue $str ): static {
        return static::func( 'UPPER', [ $str ] );
    }

    /**
     * Create a LOWER() scalar function expression.
     *
     * @param string|DefaultColumnValue $str The string target or column identifier.
     * @return static
     */
    public static function lower( string|DefaultColumnValue $str ): static {
        return static::func( 'LOWER', [ $str ] );
    }

    /**
     * Create a LENGTH() scalar function expression.
     *
     * @param string|DefaultColumnValue $str The string target or column identifier.
     * @return static
     */
    public static function length( string|DefaultColumnValue $str ): static {
        return static::func( 'LENGTH', [ $str ] );
    }

    /**
     * Create a TRIM() scalar function expression.
     *
     * @param string|DefaultColumnValue $str The string target or column identifier.
     * @return static
     */
    public static function trim( string|DefaultColumnValue $str ): static {
        return static::func( 'TRIM', [ $str ] );
    }

    /**
     * Create an ANSI SQL SUBSTRING(string FROM start FOR length) function expression.
     *
     * @param string|DefaultColumnValue $str    The string target or column identifier.
     * @param int                       $start  The 1-based start position index.
     * @param int|null                  $length Optional length slice of characters to extract.
     * @return static
     */
    public static function substring( string|DefaultColumnValue $str, int $start, ?int $length = null ): static {
        if ( null !== $length ) {
            return static::expression( sprintf( 'SUBSTRING(%s FROM %d FOR %d)', (string) $str, $start, $length ) );
        }
        return static::expression( sprintf( 'SUBSTRING(%s FROM %d)', (string) $str, $start ) );
    }

    /**
     * Create a REPLACE() scalar function expression.
     *
     * @param string|DefaultColumnValue $str     The target string or column identifier.
     * @param string                    $find    The search pattern token.
     * @param string                    $replace The replacement string token.
     * @return static
     */
    public static function replace( string|DefaultColumnValue $str, string $find, string $replace ): static {
        return static::func( 'REPLACE', [ $str, $find, $replace ] );
    }

    /**
     * Create a COALESCE() function expression to return the first non-null argument.
     *
     * @param mixed ...$args The variable set of expressions or column identifiers to check.
     * @return static
     */
    public static function coalesce( mixed ...$args ): static {
        return static::func( 'COALESCE', $args );
    }

    /* -----------------------------------------------------------------
     | Numeric & Aggregate Functions (ANSI SQL)
     | ----------------------------------------------------------------- */

    /**
     * Create an ABS() scalar function expression.
     *
     * @param mixed $val The target numeric column or value expression.
     * @return static
     */
    public static function abs( mixed $val ): static {
        return static::func( 'ABS', [ $val ] );
    }

    /**
     * Create a ROUND() scalar function expression.
     *
     * @param mixed $val      The target numeric column or value expression.
     * @param int   $decimals The number of decimal positions to round to. Defaults to 0.
     * @return static
     */
    public static function round( mixed $val, int $decimals = 0 ): static {
        return static::func( 'ROUND', [ $val, $decimals ] );
    }

    /**
     * Create a CEIL() scalar function expression.
     *
     * @param mixed $val The target numeric column or value expression.
     * @return static
     */
    public static function ceil( mixed $val ): static {
        return static::func( 'CEIL', [ $val ] );
    }

    /**
     * Create a FLOOR() scalar function expression.
     *
     * @param mixed $val The target numeric column or value expression.
     * @return static
     */
    public static function floor( mixed $val ): static {
        return static::func( 'FLOOR', [ $val ] );
    }

    /**
     * Create a COUNT() aggregate function expression.
     *
     * @param mixed $column The column name, expression, or wildcard target. Defaults to '*'.
     * @return static
     */
    public static function count( mixed $column = '*' ): static {
        return static::func( 'COUNT', [ $column ] );
    }

    /**
     * Create a SUM() aggregate function expression.
     *
     * @param mixed $column The target numeric column name or expression.
     * @return static
     */
    public static function sum( mixed $column ): static {
        return static::func( 'SUM', [ $column ] );
    }

    /**
     * Create an AVG() aggregate function expression.
     *
     * @param mixed $column The target numeric column name or expression.
     * @return static
     */
    public static function avg( mixed $column ): static {
        return static::func( 'AVG', [ $column ] );
    }

    /**
     * Create a MIN() aggregate function expression.
     *
     * @param mixed $column The target column name or expression.
     * @return static
     */
    public static function min( mixed $column ): static {
        return static::func( 'MIN', [ $column ] );
    }

    /**
     * Create a MAX() aggregate function expression.
     *
     * @param mixed $column The target column name or expression.
     * @return static
     */
    public static function max( mixed $column ): static {
        return static::func( 'MAX', [ $column ] );
    }

    /* -----------------------------------------------------------------
     | Special Keywords
     | ----------------------------------------------------------------- */

    /**
     * Get the raw NULL SQL keyword expression.
     *
     * @return static
     */
    public static function null(): static {
        return static::expression( 'NULL' );
    }

    /**
     * Create a NULLIF(expr1, expr2) function expression.
     *
     * @param mixed $expr1 The first target expression to compare.
     * @param mixed $expr2 The second target expression to compare.
     * @return static
     */
    public static function nullif( mixed $expr1, mixed $expr2 ): static {
        return static::func( 'NULLIF', [ $expr1, $expr2 ] );
    }
}