<?php
/**
 * Abstract Query Renderer - Abstract Base Class
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\Renderers
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Query\Renderers;

use Callismart\DBPrism\Query\QueryIntents\AlterTableIntent;
use Callismart\DBPrism\Query\QueryIntents\CompoundQueryIntent;
use Callismart\DBPrism\Query\QueryIntents\CreateIndexIntent;
use Callismart\DBPrism\Query\QueryIntents\CreateTableIntent;
use Callismart\DBPrism\Query\QueryIntents\DeleteIntent;
use Callismart\DBPrism\Query\QueryIntents\PersistenceIntent;
use Callismart\DBPrism\Query\QueryIntents\QueryIntentInterface;
use Callismart\DBPrism\Query\QueryIntents\SelectionIntent;
use Callismart\DBPrism\Query\QueryIntents\TruncateTableIntent;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\DefaultColumnValue;
use Callismart\DBPrism\Utils\LockMode;

/**
 * Provides a blueprint for engine-specific SQL renderers.
 */
abstract class AbstractQueryRenderer {

    /**
     * The database engine identifier (e.g., 'mysql', 'sqlite').
     * 
     * @var string
     */
    protected string $engine = '';

    /**
     * Engine-specific quoting for a single identifier unit.
     * 
     * @param string $identifier The raw segment.
     * @return string
     */
    abstract protected function quote_single_identifier( string $identifier ) : string;

    /**
     * Tells whether engine supports native boolean value
     * 
     * @return bool
     */
    abstract protected function supports_native_booleans() : bool;

    /**
     * Render lock mode string
     * 
     * @param LockMode $mode
     */
    abstract protected function render_lock_mode( LockMode $mode ) : string;

    /*
    |--------------------------
    | Schema Rendering (DDL)
    |--------------------------
    */

    /**
     * Render a CREATE TABLE SQL statement.
     * 
     * @param CreateTableIntent $intent
     * @return string
     */
    abstract public function render_create_table( CreateTableIntent $intent ) : string;

    /**
     * Render an ALTER TABLE SQL statement.
     * 
     * @param AlterTableIntent $intent
     * @return string
     */
    abstract public function render_alter_table( AlterTableIntent $intent ) : string;

    /**
     * Render an TRUNCATE TABLE SQL statement.
     * 
     * @param TruncateTableIntent $intent
     * @return string
     */
    abstract public function render_truncate_table( TruncateTableIntent $intent ) : string;

    /**
     * Render a standalone CREATE INDEX SQL statement.
     * 
     * @param CreateIndexIntent $intent
     * @return string
     */
    abstract public function render_create_index( CreateIndexIntent $intent ) : string;

    /**
     * Render a table constraint or index definition for CREATE/ALTER context.
     * 
     * @param Constraint $constraint
     * @return string
     */
    abstract protected function render_constraint( Constraint $constraint ) : string;

    /**
     * Render a DROP TABLE statement.
     * 
     * @param string $table   The table name.
     * @param array  $options Configuration like ['if_exists' => true].
     * @return string
     */
    public function render_drop_table( string $table, array $options = [] ) : string {
        $table_quoted = $this->quote_identifier( $table );
        $if_exists    = ! empty( $options['if_exists'] ) ? ' IF EXISTS' : '';
        return "DROP TABLE{$if_exists} {$table_quoted};";
    }

    /*
    |--------------------------------------------------------------------------
    | Data Manipulation (DML)
    |--------------------------------------------------------------------------
    */

    /**
     * Render a SELECT statement.
     * 
     * @param SelectionIntent $intent
     * @return string
     */
    public function render_select( SelectionIntent $intent ) : string {
        $sql = "SELECT ";

        if ( $intent->is_distinct() ) {
            $sql .= "DISTINCT ";
        }

        $sql .= sprintf(
            "%s FROM %s",
            $this->render_columns( $intent->get_columns() ),
            $this->quote_identifier( $intent->get_table_name() )
        );

        $sql .= $this->render_joins( $intent->get_joins() );

        $conditions = $intent->get_conditions();

        if ( ! empty( $conditions ) ) {
            $sql .= " WHERE " . $this->render_where_clauses( $conditions );
        }

        $sql .= $this->render_grouping( $intent->get_groups() );

        $sql .= $this->render_ordering( $intent->get_orders() );

        $sql .= $this->render_limit_offset( $intent->get_limit(), $intent->get_offset() );

        $sql .= $this->render_lock_mode( $intent->get_lock_mode() );

        return $sql . ";";
    }

    /*
    |-----------------------------------------
    | Advanced Composite Selection (CTEs)
    |-----------------------------------------
    */

    /**
     * Render a stacked composite set statement.
     * Safe across MySQL, PostgreSQL, SQLite, and future relational engines.
     * 
     * @param CompoundQueryIntent $compound
     * @return string
     */
    public function render_compound_select( CompoundQueryIntent $compound ) : string {
        $sql_parts = [];

        // Compile the primary master dataset block segment.
        $sql_parts[] = rtrim( $this->render_select( $compound->get_primary() ), ';' );

        // Stack trailing elements horizontally using a 
        // single inline space separation scheme.
        foreach ( $compound->get_unions() as $union ) {
            $compiled = rtrim( $this->render_select( $union->intent ), ';' );
            $sql_parts[] = "{$union->operator} {$compiled}";
        }

        // Standardize joining blocks with an active spacing separator.
        $compound_sql = implode( ' ', $sql_parts );
        
        // Resolve the dynamic subquery wrapper identifier name.
        $alias_name    = $compound->get_wrapper_alias() ?: 'compound_dataset';
        $wrapper_alias = $this->quote_identifier( $alias_name );

        // Process the pre-normalized outer column projection list.
        $outer_select_fields = [];
        $selections          = $compound->get_outer_selections();

        if ( empty( $selections ) ) {
            $outer_select_fields[] = '*';
        } else {
            foreach ( $selections as $normalized ) {
                // Formulate identifiers versus functional
                // string expressions cleanly.
                if ( 'expression' === $normalized['type'] ) {
                    $field_sql = $normalized['value'];
                } else {
                    $field_sql = $this->quote_identifier( $normalized['value'] );
                }

                // Append custom column projection naming markers if tracked
                if ( isset( $normalized['alias'] ) ) {
                    $field_sql .= ' AS ' . $this->quote_identifier( $normalized['alias'] );
                }

                $outer_select_fields[] = $field_sql;
            }
        }

        $outer_select_clause = implode( ', ', $outer_select_fields );

        // Assemble the dynamic outer wrapping structural layout.
        $sql = "SELECT {$outer_select_clause} FROM (\n{$compound_sql}\n) AS {$wrapper_alias}";

        // Compile and append global sorting requirements onto the trailing edge.
        if ( ! empty( $compound->get_orders() ) ) {
            $sql .= $this->render_ordering( $compound->get_orders() );
        }

        // Compile and append global pagination window boundaries onto the trailing edge.
        if ( $compound->get_limit() !== null ) {
            $sql .= $this->render_limit_offset( $compound->get_limit(), $compound->get_offset() );
        }

        return $sql . ';';
    }

    /**
     * Render a Selection Query using a standard Common Table Expression (CTE).
     * * Supported out-of-the-box in MySQL 8.0+, PostgreSQL, and SQLite 3.8.3+.
     *
     * @param string                $cte_name     The temporary reference name for the expression dataset.
     * @param QueryIntentInterface  $cte_intent    The subquery dataset intent (SelectionIntent or CompoundQueryIntent).
     * @param SelectionIntent       $main_intent  The primary selection execution view reading from the CTE.
     * @return string The fully compiled CTE query string.
     */
    public function render_cte_select( string $cte_name, QueryIntentInterface $cte_intent, SelectionIntent $main_intent ) : string {
        $cte_name_quoted = $this->quote_identifier( $cte_name );
        
        // Strip trailing statement markers from both the sub-intent and master intent blocks
        $compiled_cte  = rtrim( $cte_intent->build(), ';' );
        $compiled_main = $this->render_select( $main_intent );
        
        return "WITH {$cte_name_quoted} AS (\n{$compiled_cte}\n)\n{$compiled_main}";
    }

    /**
     * Render a self-referential recursive hierarchy traversal statement.
     * * Ideal for infinite graph data trees like nested comments, menus, or directories.
     *
     * @param string          $cte_name          The temporary reference name for the looping dataset table.
     * @param SelectionIntent $anchor_intent     The initial anchor query mapping the tree roots.
     * @param SelectionIntent $recursive_intent  The self-referential subquery looping back against the active CTE.
     * @param SelectionIntent $final_intent      The final statement drawing out the accumulated tree properties.
     * @return string The fully compiled recursive statement.
     */
    public function render_recursive_cte( 
        string $cte_name, 
        SelectionIntent $anchor_intent, 
        SelectionIntent $recursive_intent, 
        SelectionIntent $final_intent 
    ) : string {
        $name_quoted = $this->quote_identifier( $cte_name );
        
        $anchor_sql    = rtrim( $this->render_select( $anchor_intent ), ';' );
        $recursive_sql = rtrim( $this->render_select( $recursive_intent ), ';' );
        $final_sql     = $this->render_select( $final_intent );
        
        // Default ANSI SQL layout containing the standard "RECURSIVE" modifier
        return "WITH RECURSIVE {$name_quoted} AS (\n" .
               "    {$anchor_sql}\n" .
               "    UNION ALL\n" .
               "    {$recursive_sql}\n" .
               ")\n{$final_sql}";
    }

    /**
     * Render an INSERT statement, supporting single or multiple rows.
     * 
     * @param PersistenceIntent $intent
     * @throws \RuntimeException If no data is provided.
     * @return string
     */
    public function render_insert( PersistenceIntent $intent ) : string {
        $table = $this->quote_identifier( $intent->get_table_name() );
        $data  = $intent->get_data();

        if ( empty( $data ) ) {
            throw new \RuntimeException( "Cannot render INSERT: No data provided." );
        }

        $first_row = $intent->is_multi() ? $data[0] : $data;
        $columns   = array_keys( $first_row );
        $quoted_cols = implode( ', ', array_map( [ $this, 'quote_identifier' ], $columns ) );

        if ( $intent->is_multi() ) {
            $row_placeholders = [];
            foreach ( $data as $row ) {
                $row_placeholders[] = '(' . implode( ', ', array_fill( 0, count( $row ), '?' ) ) . ')';
            }
            $values_clause = implode( ', ', $row_placeholders );
        } else {
            $values_clause = '(' . implode( ', ', array_fill( 0, count( $data ), '?' ) ) . ')';
        }

        return sprintf( "INSERT INTO %s (%s) VALUES %s;", $table, $quoted_cols, $values_clause );
    }

    /**
     * Render an UPDATE statement with conditions.
     * 
     * @param PersistenceIntent $intent
     * @return string
     */
    public function render_update( PersistenceIntent $intent ) : string {
        $table = $this->quote_identifier( $intent->get_table_name() );
        $data  = $intent->get_data();

        $set_parts = [];
        foreach ( array_keys( $data ) as $column ) {
            $set_parts[] = $this->quote_identifier( $column ) . " = ?";
        }

        $sql = sprintf( "UPDATE %s SET %s", $table, implode( ', ', $set_parts ) );
        $conditions = $intent->get_conditions();

        if ( ! empty( $conditions ) ) {
            $sql .= " WHERE " . $this->render_where_clauses( $conditions );
        }

        return $sql . ";";
    }

    /**
     * Render a cross-engine compile-safe DELETE statement with conditions.
     * 
     * @param DeleteIntent $intent
     * @return string
     */
    public function render_delete( DeleteIntent $intent ) : string {
        $quoted_table = $this->quote_identifier( $intent->get_table_name() );

        $conditions   = $intent->get_conditions();

        $sql = "DELETE FROM {$quoted_table}";

        // Compile and append the condition block if filters or subqueries exist
        if ( ! empty( $conditions ) ) {
            $sql .= " WHERE " . $this->render_where_clauses( $conditions );
        }

        return $sql . ";";
    }

    /*
    |---------------------
    | Shared Helpers
    |---------------------
    */

    /**
     * Render the column selection portion of a query.
     * 
     * @param array $columns
     * @return string
     */
    protected function render_columns( array $columns ) : string {
        $out = [];

        foreach ( $columns as $col ) {

            if ( $col['type'] === 'column' ) {
                $sql = $this->quote_identifier( $col['value'] );
            }

            elseif ( $col['type'] === 'expression' ) {
                $sql = $col['value'];
            }

            else {
                $sql = $col['value']; // raw fallback
            }

            if (!empty($col['alias'])) {
                $sql .= ' AS ' . $this->quote_identifier($col['alias']);
            }

            $out[] = $sql;
        }

        return implode(', ', $out);
    }

    /*
    |--------------------------
    | WHERE CLAUSES RENDERING
    |--------------------------
    */

    /**
     * Render WHERE clause string from structured conditions.
     * 
     * @param array $conditions
     * @throws \InvalidArgumentException If condition type is unknown.
     * @return string
     */
    protected function render_where_clauses( array $conditions ) : string {
        $parts = [];
        foreach ( $conditions as $index => $condition ) {
            $connector = ( $index === 0 ) ? '' : " {$condition['boolean']} ";

            $clause = match ( $condition['type'] ) {
                'Basic'     => sprintf( "%s %s ?", $this->quote_identifier( $condition['column'] ), $condition['operator'] ),
                
                'Null'      => sprintf( "%s IS %sNULL", $this->quote_identifier( $condition['column'] ), $condition['not'] ? 'NOT ' : '' ),
                                
                'In'        => $this->render_in_condition( $condition ),

                'Group'     => $this->render_group_condition( $condition ),

                'Between'   => sprintf(
                    "%s %sBETWEEN ? AND ?",
                    $this->quote_identifier( $condition['column'] ),
                    $condition['not'] ? 'NOT ' : ''
                ),
                
                'Like'      => sprintf(
                    "%s %sLIKE ? ESCAPE '='",
                    $this->quote_identifier( $condition['column'] ),
                    $condition['not'] ? 'NOT ' : ''
                ),

                'Exists'    => sprintf(
                    "%sEXISTS (\n%s\n)",
                    $condition['not'] ? 'NOT ' : '',
                    rtrim( $condition['subquery']->build(), ';' )
                ),

                'Column' => sprintf(
                    "%s %s %s",
                    $this->quote_identifier( $condition['first_column'] ),
                    $condition['operator'],
                    $this->quote_identifier( $condition['second_column'] )
                ),

                'Raw' => $condition['expression'],

                default => throw new \InvalidArgumentException( "Unsupported condition type: \"{$condition['type']}\"" )
            };

            $parts[] = $connector . $clause;
        }

        return implode( '', $parts );
    }

    /**
     * Render GROUP BY clause.
     * 
     * @param array $groups
     * @return string
     */
    protected function render_grouping( array $groups ) : string {
        return empty( $groups ) ? '' : ' GROUP BY ' . implode( ', ', $this->quote_identifiers( $groups ) );
    }

    /**
     * Render grouped WHERE conditions.
     * 
     * @param array $condition
     * @return string
     */
    protected function render_group_condition( array $condition ) : string {
        $inner = $this->render_where_clauses( $condition['conditions'] );
        return '(' . $inner . ')';
    }

    protected function render_in_condition( array $condition ) : string {
        $placeholders = implode(
            ', ',
            array_fill( 0, count( $condition['values'] ), '?' )
        );

        return sprintf(
            "%s %sIN (%s)",
            $this->quote_identifier( $condition['column'] ),
            $condition['not'] ? 'NOT ' : '',
            $placeholders
        );
    }

    /*
    |--------------------------
    | JOIN & ORDERING RENDERING
    |--------------------------
    */

    /**
     * Render JOIN clauses.
     * 
     * @param array $joins
     * @return string
     */
    protected function render_joins( array $joins ) : string {
        if ( empty( $joins ) ) return '';
        
        $sql = [];
        foreach ( $joins as $join ) {
            if ( $join['type'] === 'CROSS' ) {
                $sql[] = sprintf( "CROSS JOIN %s", $this->quote_identifier( $join['table'] ) );
                continue;
            }
            $sql[] = sprintf( 
                "%s JOIN %s ON %s %s %s", 
                $join['type'], 
                $this->quote_identifier( $join['table'] ), 
                $this->quote_identifier( $join['first'] ), 
                $join['operator'], 
                $this->quote_identifier( $join['second'] ) 
            );
        }
        return ' ' . implode( ' ', $sql );
    }

    /**
     * Render ORDER BY clause.
     * 
     * @param array $orders
     * @return string
     */
    protected function render_ordering( array $orders ) : string {
        if ( empty( $orders ) ) return '';
        $parts = [];
        foreach ( $orders as $order ) {
            $parts[] = $this->quote_identifier( $order['column'] ) . ' ' . $order['direction'];
        }
        return ' ORDER BY ' . implode( ', ', $parts );
    }

    /**
     * Render LIMIT and OFFSET clauses.
     * 
     * @param int|null $limit
     * @param int|null $offset
     * @return string
     */
    protected function render_limit_offset( ?int $limit, ?int $offset ) : string {
        $sql = $limit !== null ? " LIMIT {$limit}" : '';
        $sql .= $offset !== null ? " OFFSET {$offset}" : '';
        return $sql;
    }

    /**
     * Quote a database identifier, handling dot notation and aliases.
     * @param string $identifier
     * @return string
     */
    public function quote_identifier( string $identifier ) : string {
        $identifier = trim( $identifier );
        
        // 1. If it's a global wildcard, do not quote it.
        if ( $identifier === '*' ) {
            return '*';
        }

        // 2. Explicitly guard table wildcards like "m.*"
        if ( str_ends_with( $identifier, '.*' ) ) {
            $prefix = substr( $identifier, 0, -2 );
            return $this->quote_identifier( $prefix ) . '.*';
        }

        // 3. Handle explicit aliases (e.g., "table_name AS alias" or "table_name alias")
        // Handles multiple spaces seamlessly by filtering empty parts
        if ( str_contains( $identifier, ' ' ) ) {
            $parts = array_values( array_filter( explode( ' ', $identifier ) ) );
            
            // If it's explicitly "table AS alias"
            if ( isset( $parts[1] ) && strtolower( $parts[1] ) === 'as' ) {
                return $this->quote_identifier( $parts[0] ) . ' AS ' . $this->quote_identifier( $parts[2] );
            }
            
            // Otherwise, it's an implicit space alias "table alias"
            if ( count( $parts ) === 2 ) {
                return $this->quote_identifier( $parts[0] ) . ' ' . $this->quote_identifier( $parts[1] );
            }
        }

        // 4. Handle nested dot notation namespaces (e.g., "database.table")
        if ( str_contains( $identifier, '.' ) ) {
            $parts = array_map( [ $this, 'quote_identifier' ], explode( '.', $identifier ) );
            return implode( '.', $parts );
        }

        return $this->quote_single_identifier( $identifier );
    }

    /**
     * Quote an array of identifiers.
     * 
     * @param array $identifiers
     * @return array
     */
    protected function quote_identifiers( array $identifiers ) : array {
        return array_map( [ $this, 'quote_identifier' ], $identifiers );
    }

    /**
     * Map abstract column type constant to engine-specific type string.
     * 
     * @param int   $type
     * @param array $args Length, scale, or precision.
     * @return string
     */
    protected function normalize_type( int $type, array $args = [] ) : string {
        return ColumnType::resolve( $type, $this->engine, $args );
    }

    /**
     * Format literal values for DDL (defaults) or raw segments.
     * 
     * @param mixed $value
     * @return string
     */
    protected function format_value( mixed $value ) : string {

        if ( $value instanceof  DefaultColumnValue ) {
            if ( $value->is_expression() ) {
                return (string) $value;
            }

            $value  = $value->value();
        }
        
        return match( true ) {
            is_bool( $value )       => $this->supports_native_booleans() ? ( $value ? 'TRUE' : 'FALSE' ) : ( $value ? '1' : '0' ),
            is_null( $value )       => 'NULL',
            is_numeric( $value )    => (string) $value,
            default                 => "'" . str_replace( "'", "''", (string) $value ) . "'"
        };
    }
}