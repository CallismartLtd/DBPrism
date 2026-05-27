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
 * * This trait defines the canonical parsing and normalization blueprint for all columns,
 * expressions, and literals supplied during fluent query building across the framework.
 * It ensures cross-engine compliance (MySQL, PostgreSQL, SQLite) by isolating field aliases
 * and automatically transforming double-quoted string literals into ANSI-compliant single quotes.
 * 
 * @package Smliser\Query\Traits
 */
trait ColumnNormalizerTrait {

    /**
     * Normalize columns and expressions, extracting aliases when present.
     * * This is the framework's canonical parsing rule for columns. It breaks strings down
     * into structural meta components, detecting function calls or raw text values.
     * 
     * @param string $column The raw column string or expression input.
     * @return array{type: string, value: string, alias?: string} Normalized structural descriptor mapping.
     */
    protected function normalize_column( string $column ) : array {
        $column = trim( $column );

        if ( '*' === $column || '' === $column ) {
            return [
                'type'  => 'column',
                'value' => '*'
            ];
        }

        // Match: [Anything] followed optionally by (whitespace + optional 'AS' + whitespace) and an [Alias]
        // Group 1: The core field or expression
        // Group 2: The raw alias string (if it exists)
        $pattern = '/^(.+?)(?:\s+(?:as\s+)?(\w+))?$/i';

        if ( preg_match( $pattern, $column, $matches ) ) {
            $value = trim( $matches[1] );
            $alias = isset( $matches[2] ) ? trim( $matches[2] ) : null;

            // Check if the value is wrapped in single or double quotes
            $is_single_quoted = str_starts_with( $value, "'" ) && str_ends_with( $value, "'" );
            $is_double_quoted = str_starts_with( $value, '"' ) && str_ends_with( $value, '"' );

            // AUTOMATIC NORMALIZATION: If double-quoted, convert it to a safe ANSI single-quoted string literal
            if ( $is_double_quoted ) {
                $unquoted_string = substr( $value, 1, -1 );
                // Escape single quotes inside to prevent SQL injection vulnerabilities
                $value = "'" . str_replace( "'", "''", $unquoted_string ) . "'";
                $is_single_quoted = true; 
            }

            // Determine if the base value is a functional SQL expression
            $is_functional = (bool) preg_match( '/\w+\s*\(.*\)/', $value );

            // Mark as expression if it is an ANSI string literal OR a functional call
            $is_expression = $is_single_quoted || $is_functional;

            $result = [
                'type'  => $is_expression ? 'expression' : 'column',
                'value' => $value,
            ];

            if ( null !== $alias ) {
                $result['alias'] = $alias;
            }

            return $result;
        }

        // Fallback safety net
        return [
            'type'  => 'column', 
            'value' => $column
        ];
    }
}