<?php
/**
 * PostgreSQL Engine Renderer
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\Renderers
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Query\Renderers;

use Callismart\DBPrism\Query\QueryIntents\CreateTableIntent;
use Callismart\DBPrism\Query\QueryIntents\AlterTableIntent;
use Callismart\DBPrism\Query\QueryIntents\CreateIndexIntent;
use Callismart\DBPrism\Query\QueryIntents\SelectionIntent;
use Callismart\DBPrism\Query\QueryIntents\TruncateTableIntent;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\LockMode;

/**
 * PostgreSQL-specific SQL renderer.
 *
 * Generates PostgreSQL-compliant SQL from normalized intent.
 * Handles PostgreSQL-specific double-quote identifiers and ALTER syntax.
 *
 * @since 0.2.0
 */
class PostgreSQLRenderer extends AbstractQueryRenderer {

    /**
     * The database engine identifier.
     * 
     * @var string
     */
    protected string $engine = 'pgsql';

    /**
     * Quote a PostgreSQL identifier using double quotes.
     * 
     * @param string $identifier
     * @return string
     */
    protected function quote_single_identifier( string $identifier ) : string {
        return '"' . str_replace( '"', '""', $identifier ) . '"';
    }

    /**
     * Render CREATE TABLE for PostgreSQL.
     * 
     * @param CreateTableIntent $intent
     * @return string
     */
    public function render_create_table( CreateTableIntent $intent ) : string {
        $table_name  = $this->quote_identifier( $intent->get_table_name() );
        $definitions = [];
        $index_sql   = [];

        // Columns
        foreach ( $intent->get_columns() as $column ) {
            $definitions[] = $this->render_column_definition( $column );
        }

        // Constraints
        foreach ( $intent->get_constraints() as $constraint ) {

            // Extract INDEX constraints into separate statements
            if ( strtolower( $constraint->type ) === 'index' ) {
                $index_sql[] = $this->render_inline_index_as_create_index(
                    $intent->get_table_name(),
                    $constraint
                );
                continue;
            }

            $definitions[] = $this->render_constraint( $constraint );
        }

        // An auto-increment (SERIAL) column becomes the primary key when the
        // table declares none, as on MySQL and SQLite.
        $implied_key = $this->implied_primary_key( $intent );

        if ( null !== $implied_key ) {
            $definitions[] = 'PRIMARY KEY (' . $this->quote_identifier( $implied_key ) . ')';
        }

        // CREATE TABLE
        $table_sql = sprintf(
            "CREATE TABLE %s (\n\t%s\n);",
            $table_name,
            implode( ",\n\t", $definitions )
        );

        // Append indexes (separate statements)
        if ( ! empty( $index_sql ) ) {
            $table_sql .= "\n\n" . implode( "\n", $index_sql );
        }

        // Column comments are separate statements in PostgreSQL.
        $comment_sql = $this->render_column_comments( $intent->get_table_name(), $intent->get_columns() );

        if ( ! empty( $comment_sql ) ) {
            $table_sql .= "\n\n" . implode( "\n", $comment_sql );
        }

        return $table_sql;
    }

    /**
     * Render a standalone CREATE INDEX.
     * 
     * @param CreateIndexIntent $intent
     * @return string
     */
    public function render_create_index( CreateIndexIntent $intent ) : string {
        $table = $this->quote_identifier( $intent->get_table_name() );
        $index = $intent->get_index();
        $type  = ( strtolower( $index->type ) === 'unique' ) ? 'UNIQUE INDEX' : 'INDEX';
        $name  = $index->name ? $this->quote_identifier( $index->name ) : '';
        $cols  = implode( ', ', $this->quote_identifiers( $index->columns ) );

        return trim( "CREATE {$type} {$name} ON {$table} ({$cols});" );
    }

    protected function render_inline_index_as_create_index( string $table, Constraint $constraint ) : string {
        $type  = 'unique' === strtolower( $constraint->type ) ? 'UNIQUE INDEX' : 'INDEX';
        $table = $this->quote_identifier( $table );

        $columns = implode( ', ', $this->quote_identifiers( $constraint->columns ) );

        $name = $constraint->name
            ? $this->quote_identifier( $constraint->name )
            : $this->quote_identifier(
                $table . '_' . implode( '_', $constraint->columns ) . '_idx'
            );

        return sprintf(
            "CREATE %s %s ON %s (%s);",
            $type,
            $name,
            $table,
            $columns
        );
    }

    /**
     * Render ALTER TABLE statement for PostgreSQL.
     * 
     * @param AlterTableIntent $intent
     * @return string
     */
    public function render_alter_table( AlterTableIntent $intent ) : string {
        $table      = $this->quote_identifier( $intent->get_table_name() );
        $operations = [];
        $drops      = [];
        $creates    = [];
        $commented  = [];
        $cleared    = [];
        $final_name = $intent->get_table_name();

        foreach ( $intent->get_operations() as $op ) {
            $key = strtoupper( $op['action'] ) . '_' . strtoupper( $op['subject'] );

            // Comments on added or modified columns follow as separate statements,
            // against the table's name after any rename in this same ALTER.
            // A modified column without a comment has its old one removed, as MODIFY COLUMN does on MySQL.
            if ( 'MODIFY_COLUMN' === $key && '' === $op['payload']->comment ) {
                $cleared[] = $op['payload'];
            } elseif ( 'ADD_COLUMN' === $key || 'MODIFY_COLUMN' === $key ) {
                $commented[] = $op['payload'];
            } elseif ( 'RENAME_TABLE' === $key ) {
                $final_name = $op['payload']['to'];
            }

            // PostgreSQL has no index operations inside ALTER TABLE; indexes are
            // standalone statements, as in render_create_table().
            switch ( $this->index_operation( $op ) ) {
                case 'ADD':
                    $creates[] = $op['payload']; // Rendered after the loop, against the final table name.
                    break;

                case 'DROP':
                    $drops[] = sprintf( "DROP INDEX %s;", $this->quote_identifier( $op['payload'] ) );
                    break;

                default:
                    $operations[] = $this->render_alter_operation( $op );
            }
        }

        $creates  = array_map( fn( $index ) => $this->render_inline_index_as_create_index( $final_name, $index ), $creates );
        $comments = $this->render_column_comments( $final_name, $commented );

        foreach ( $cleared as $column ) {
            $comments[] = sprintf(
                "COMMENT ON COLUMN %s.%s IS NULL;",
                $this->quote_identifier( $final_name ),
                $this->quote_identifier( $column->name )
            );
        }

        if ( empty( $drops ) && empty( $creates ) && empty( $comments ) ) {
            return sprintf( "ALTER TABLE %s %s;", $table, implode( ', ', $operations ) );
        }

        // Drops first: dropping a column in the same ALTER also drops its indexes.
        // Creates and comments last: they refer to columns the ALTER adds.
        $statements = $drops;

        if ( ! empty( $operations ) ) {
            $statements[] = sprintf( "ALTER TABLE %s %s;", $table, implode( ', ', $operations ) );
        }

        return implode( "\n", array_merge( $statements, $creates, $comments ) );
    }

    /**
     * {@inheritdoc}
     */
    public function render_truncate_table( TruncateTableIntent $intent ) : string {
        $quoted_tables = array_map( fn( $t ) => "\"{$t}\"", $intent->get_tables() );
        $tables_list = implode( ', ', $quoted_tables );

        $sql = "TRUNCATE TABLE {$tables_list}";
        $sql .= $intent->should_restart_identity() ? " RESTART IDENTITY" : " CONTINUE IDENTITY";
        $sql .= $intent->should_cascade() ? " CASCADE" : " RESTRICT";

        return $sql . ";";
    }

    /**
     * Render a constraint or index for a CREATE TABLE definition.
     * 
     * @param Constraint $constraint
     * @return string
     */
    protected function render_constraint( Constraint $constraint ) : string {
        $type    = strtolower( $constraint->type );
        $columns = implode( ', ', $this->quote_identifiers( $constraint->columns ) );
        $name    = $constraint->name ? ' CONSTRAINT ' . $this->quote_identifier( $constraint->name ) : '';

        return match ( $type ) {
            'primary' => "{$name} PRIMARY KEY ({$columns})",
            'unique'  => "{$name} UNIQUE ({$columns})",
            'foreign' => $this->render_foreign_key( $constraint ),
            default   => throw new \RuntimeException( "PostgreSQL: Unsupported constraint type [{$type}]" )
        };
    }

    /**
     * Map Alter Operations to PostgreSQL Syntax.
     * 
     * @param array $op
     * @return string
     */
    protected function render_alter_operation( array $op ) : string {
        $action  = strtoupper( $op['action'] );
        $subject = strtoupper( $op['subject'] );
        $payload = $op['payload'];

        return match ( "{$action}_{$subject}" ) {
            'RENAME_TABLE'  => "RENAME TO " . $this->quote_identifier( $payload['to'] ),
            'ADD_COLUMN'    => "ADD COLUMN " . $this->render_column_definition( $payload ),
            'MODIFY_COLUMN' => $this->render_pgsql_modify_column( $payload ),
            'DROP_COLUMN'   => "DROP COLUMN " . $this->quote_identifier( $payload ),
            'RENAME_COLUMN' => sprintf(
                "RENAME COLUMN %s TO %s",
                $this->quote_identifier( $payload['from'] ),
                $this->quote_identifier( $payload['to'] )
            ),
            'ADD_CONSTRAINT'    => "ADD " . $this->render_constraint( $payload ),
            'DROP_CONSTRAINT'   => "DROP CONSTRAINT " . $this->quote_identifier( $payload ),
            default             => throw new \RuntimeException( "PostgreSQL: Unsupported operation {$action}_{$subject}" )
        };
    }

    /**
     * PostgreSQL specific column modification.
     * Unlike MySQL, PG requires separate ALTER ACTIONS for type, nullability, etc.
     * 
     * @param Column $column
     * @return string
     */
    private function render_pgsql_modify_column( Column $column ) : string {
        $this->require_column_type( $column );

        $name = $this->quote_identifier( $column->name );
        $type = $this->normalize_type( $column->type, [
            'length' => $column->length,
            'precision' => $column->precision,
            'scale' => $column->scale
        ]);

        // USING converts values PostgreSQL has no automatic cast for (e.g. text
        // to integer); a value that cannot convert still fails the statement.
        // Character types take no USING: an explicit cast to VARCHAR(n) would
        // cut longer values short, where the automatic cast refuses them.
        $using = preg_match( '/^\s*(VARCHAR|CHAR|CHARACTER|TEXT)\b/i', $type ) ? '' : " USING {$name}::{$type}";

        $actions = [];

        // The column gets exactly the default it is given, as MODIFY COLUMN
        // does on MySQL. The old default goes first: PostgreSQL refuses a type
        // change when the old default cannot be cast to the new type. An
        // auto-increment column keeps its sequence default.
        if ( ! $column->auto_increment ) {
            $actions[] = "ALTER COLUMN {$name} DROP DEFAULT";
        }

        $actions[] = "ALTER COLUMN {$name} TYPE {$type}{$using}";
        $actions[] = "ALTER COLUMN {$name} " . ( $column->nullable ? "DROP NOT NULL" : "SET NOT NULL" );

        if ( $column->default !== null ) {
            $actions[] = "ALTER COLUMN {$name} SET DEFAULT " . $this->format_default( $column->default );
        }

        return implode( ', ', $actions );
    }

    /**
     * Render Column Definition.
     * 
     * @param Column $column
     * @return string
     */
    protected function render_column_definition( Column $column ) : string {
        $this->require_column_type( $column );

        $type = $this->normalize_type( $column->type, [
            'length'    => $column->length,
            'scale'     => $column->scale,
            'precision' => $column->precision
        ]);

        // Handle PostgreSQL Serial (Auto Increment)
        if ( $column->auto_increment ) {
            $type = ( strtolower( $type ) === 'bigint' ) ? 'BIGSERIAL' : 'SERIAL';
        }

        $parts = [ $this->quote_identifier( $column->name ), $type ];

        if ( ! $column->nullable ) $parts[] = 'NOT NULL';
        
        // If not serial, handle standard defaults
        if ( ! $column->auto_increment && $column->default !== null ) {
            $parts[] = "DEFAULT " . $this->format_default( $column->default );
        }

        return implode( ' ', $parts );
    }

    /**
     * COMMENT ON COLUMN statements for the columns that have a comment.
     *
     * An empty comment adds no statement.
     *
     * @param string   $table   Table name.
     * @param Column[] $columns
     * @return string[]
     */
    protected function render_column_comments( string $table, array $columns ) : array {
        $statements = [];

        foreach ( $columns as $column ) {
            if ( '' === $column->comment ) {
                continue;
            }

            $statements[] = sprintf(
                "COMMENT ON COLUMN %s.%s IS %s;",
                $this->quote_identifier( $table ),
                $this->quote_identifier( $column->name ),
                $this->quote_string_literal( $column->comment )
            );
        }

        return $statements;
    }

    /**
     * Render a Foreign Key constraint.
     * 
     * @param Constraint $constraint
     * @return string
     */
    protected function render_foreign_key( Constraint $constraint ) : string {
        $columns = implode( ', ', $this->quote_identifiers( $constraint->columns ) );
        $ref_table = $this->quote_identifier( $constraint->references_table ?? '' );
        $ref_cols  = implode( ', ', $this->quote_identifiers( $constraint->references_columns ) );
        $name    = $constraint->name ? $this->quote_identifier( $constraint->name ) : null;

        $sql    = $name ? "CONSTRAINT {$name} " : '';
        $sql    .= "FOREIGN KEY ({$columns}) REFERENCES {$ref_table} ({$ref_cols})";

        if ( $constraint->on_delete ) $sql .= " ON DELETE " . strtoupper( $constraint->on_delete );
        if ( $constraint->on_update ) $sql .= " ON UPDATE " . strtoupper( $constraint->on_update );

        return $sql;
    }

    /**
     * {@inheritdoc}
     */
    protected function render_lock_mode( LockMode $mode ) : string {
        return match( $mode ) {
            LockMode::SHARED      => ' FOR SHARE',
            LockMode::EXCLUSIVE   => ' FOR UPDATE',
            LockMode::NO_WAIT     => ' FOR UPDATE NOWAIT',
            LockMode::SKIP_LOCKED => ' FOR UPDATE SKIP LOCKED',
            default               => '',
        };
    }

    /**
     * {@inheritdoc}
     */
    protected function supports_native_booleans() : bool {
        return true;
    }
}