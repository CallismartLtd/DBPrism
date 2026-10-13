<?php
/**
 * MySQL Query Renderer
 * 
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Query\Renderers
 * @since 0.2.0
 */

namespace Callismart\DBPrism\Query\Renderers;

use Callismart\DBPrism\Query\QueryIntents\CreateTableIntent;
use Callismart\DBPrism\Query\QueryIntents\AlterTableIntent;
use Callismart\DBPrism\Query\QueryIntents\TruncateTableIntent;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\LockMode;

/**
 * MySQL Query Renderer.
 *
 * This renderer generates SQL specifically for MySQL 8.0.16 and higher.
 * 
 * Supported Features & Requirements:
 * - MySQL Version: 8.0.16+ (Required for 'DROP CONSTRAINT' and 'CHECK' enforcement).
 * - Atomic DDL: Supported (ALTER TABLE operations are transactional in MySQL 8.0+).
 * - Identifiers: Uses backticks (`) for quoting.
 * - Constraints: Uses standardized 'ADD/DROP CONSTRAINT' syntax for Foreign Keys, 
 *   Unique Keys, and Check Constraints.
 */
class MySQLRenderer extends AbstractQueryRenderer {

    protected string $engine = 'mysql';

    /**
     * Quote a MySQL identifier.
     */
    protected function quote_single_identifier( string $identifier ) : string {
        return "`" . str_replace( "`", "``", $identifier ) . "`";
    }

    /**
     * Render CREATE TABLE.
     */
    public function render_create_table( CreateTableIntent $intent ) : string {
        $table_name = $this->quote_identifier( $intent->get_table_name() );
        $definitions = [];

        foreach ( $intent->get_columns() as $column ) {
            $definitions[] = $this->render_column_definition( $column );
        }

        foreach ( $intent->get_constraints() as $constraint ) {
            $definitions[] = $this->render_constraint( $constraint );
        }

        // MySQL requires an AUTO_INCREMENT column to be a key.
        $implied_key = $this->implied_primary_key( $intent );

        if ( null !== $implied_key ) {
            $definitions[] = 'PRIMARY KEY (' . $this->quote_identifier( $implied_key ) . ')';
        }

        return sprintf( 
            "CREATE TABLE %s (\n\t%s\n)",
            $table_name,
            implode( ",\n\t", $definitions ) 
        );
    }

    /**
     * {@inheritdoc}
     */
    public function render_alter_table( AlterTableIntent $intent ) : string {
        $table      = $this->quote_identifier( $intent->get_table_name() );
        $operations = [];

        foreach ( $intent->get_operations() as $op ) {
            $operations[] = $this->render_alter_operation( $op );
        }

        return sprintf( "ALTER TABLE %s %s;", $table, implode( ', ', $operations ) );
    }

    /**
     * {@inheritdoc}
     */
    public function render_truncate_table( TruncateTableIntent $intent ) : string {
        $sql = '';
    
        if ( $intent->should_cascade() ) {
            $sql .= "SET FOREIGN_KEY_CHECKS = 0; ";
        }

        foreach ( $intent->get_tables() as $table ) {
            $sql .= "TRUNCATE TABLE `{$table}`; ";
        }

        if ( $intent->should_cascade() ) {
            $sql .= "SET FOREIGN_KEY_CHECKS = 1; ";
        }

        return trim( $sql );
    }

    /**
     * Helper to render index portion for standalone or ADD INDEX queries.
     */
    protected function render_index_definition( Constraint $index ) : string {
        $prefix = ( strtolower( $index->type ) === 'unique' ) ? 'UNIQUE INDEX' : 'INDEX';
        $name   = $index->name ? $this->quote_identifier( $index->name ) : '';
        $cols   = implode( ', ', $this->quote_identifiers( $index->columns ) );

        return trim( "{$prefix} {$name} ({$cols})" );
    }

    /**
     * Render a constraint or index for a CREATE TABLE definition.
     */
    protected function render_constraint( Constraint $constraint ) : string {
        $type    = strtolower( $constraint->type );
        $columns = implode( ', ', $this->quote_identifiers( $constraint->columns ) );
        
        $name = $constraint->name ? ' ' . $this->quote_identifier( $constraint->name ) : '';

        return match ( $type ) {
            'primary' => "PRIMARY KEY ({$columns})",
            'unique'  => "UNIQUE INDEX{$name} ({$columns})",
            'index'   => "INDEX{$name} ({$columns})",
            'foreign' => $this->render_foreign_key( $constraint ),
            default   => throw new \Exception( "MySQL: Unsupported constraint type [{$type}]" )
        };
    }

    /**
     * Map Alter Operations to MySQL Syntax.
     */
    protected function render_alter_operation( array $op ) : string {
        $action  = strtoupper( $op['action'] );
        $subject = strtoupper( $op['subject'] );
        $payload = $op['payload'];

        return match ( "{$action}_{$subject}" ) {
            // Tables
            'RENAME_TABLE'  => "RENAME TO " . $this->quote_identifier( $payload['to'] ),
            // Columns.
            'ADD_COLUMN'      => "ADD " . $this->render_column_definition( $payload ),
            'MODIFY_COLUMN'   => "MODIFY COLUMN " . $this->render_column_definition( $payload ),
            'DROP_COLUMN'     => "DROP COLUMN " . $this->quote_identifier( $payload ),
            'RENAME_COLUMN'   => sprintf(
                "RENAME COLUMN %s TO %s",
                $this->quote_identifier( $payload['from'] ),
                $this->quote_identifier( $payload['to'] )
            ),
            // Constraints.
            'ADD_CONSTRAINT'  => "ADD " . $this->render_constraint( $payload ),
            'DROP_CONSTRAINT' => $this->render_drop_constraint( $payload ),

            // Indexes
            'ADD_INDEX'       => "ADD " . $this->render_index_definition( $payload ),
            'DROP_INDEX'      => "DROP INDEX " . $this->quote_identifier( $payload ),

            default => throw new \Exception( "MySQL: Unsupported operation {$action}_{$subject}" )
        };
    }

    /**
     * Handle MySQL specific drop logic.
     */
    private function render_drop_constraint( string $name ) : string {
        if ( 'PRIMARY' === strtoupper( $name ) ) {
            return "DROP PRIMARY KEY";
        }

        return "DROP CONSTRAINT " . $this->quote_identifier( $name );
    }

    /**
     * Render Column Definition.
     */
    protected function render_column_definition( Column $column ) : string {
        $this->require_column_type( $column );

        $parts = [
            $this->quote_identifier( $column->name ),
            $this->normalize_type( $column->type, [
                'length'    => $column->length,
                'scale'     => $column->scale,
                'precision' => $column->precision
            ])
        ];

        if ( $column->unsigned ) {
            $parts[] = 'UNSIGNED';
        }

        if ( ! $column->nullable ) {
            $parts[] = 'NOT NULL';
        }

        if ( $column->default !== null ) {
            $parts[] = "DEFAULT " . $this->format_default( $column->default );
        }

        if ( $column->auto_increment ) {
            $parts[] = 'AUTO_INCREMENT';
        }

        if ( '' !== $column->comment ) {
            $parts[] = 'COMMENT ' . $this->quote_mysql_comment( $column->comment );
        }

        return implode( ' ', $parts );
    }

    /**
     * Quote a column comment for MySQL.
     *
     * MySQL also treats a backslash as an escape inside strings (unless the
     * NO_BACKSLASH_ESCAPES SQL mode is on), so backslashes are doubled too.
     *
     * @param string $comment
     * @return string
     */
    private function quote_mysql_comment( string $comment ) : string {
        return $this->quote_string_literal( str_replace( '\\', '\\\\', $comment ) );
    }

    /**
     * Specifically render a Foreign Key constraint.
     */
    protected function render_foreign_key( Constraint $constraint ) : string {
        $name    = $constraint->name ? $this->quote_identifier( $constraint->name ) : null;
        $columns = implode( ', ', $this->quote_identifiers( $constraint->columns ) );
        
        $ref_table = $this->quote_identifier( $constraint->references_table ?? '' );
        $ref_cols  = implode( ', ', $this->quote_identifiers( $constraint->references_columns ) );

        $sql = $name ? "CONSTRAINT {$name} " : "";
        $sql .= "FOREIGN KEY ({$columns}) REFERENCES {$ref_table} ({$ref_cols})";

        if ( $constraint->on_delete ) {
            $sql .= " ON DELETE " . strtoupper( $constraint->on_delete );
        }

        if ( $constraint->on_update ) {
            $sql .= " ON UPDATE " . strtoupper( $constraint->on_update );
        }

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
     *
     * MySQL accepts the CURRENT_TIMESTAMP family (CURRENT_TIMESTAMP[(n)],
     * NOW([n]), LOCALTIME[(n)], LOCALTIMESTAMP[(n)]) bare; that is the only
     * expression default before MySQL 8.0.13, so it stays bare. Any other
     * expression must be in parentheses (MySQL 8.0.13+, MariaDB 10.2+).
     */
    protected function wrap_default_expression( string $expression ) : string {
        if ( preg_match( '/^\s*(CURRENT_TIMESTAMP|NOW|LOCALTIME|LOCALTIMESTAMP)\s*(\(\s*\d*\s*\))?\s*$/i', $expression ) ) {
            return $expression;
        }

        return '(' . $expression . ')';
    }

    /**
     * {@inheritdoc}
     */
    protected function supports_native_booleans() : bool {
        return false;
    }
}