<?php
/**
 * PostgreSQL backup dialect class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup\Dialects
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup\Dialects;

use Callismart\DBPrism\Backup\TableSchema;
use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\Table;

/**
 * Backup and restore on PostgreSQL (the public schema, as Inspector reads it).
 *
 * Backup reads inside one REPEATABLE READ, READ ONLY transaction: a snapshot
 * of every table at the moment it began. Restore is one transaction,
 * schema included, since PostgreSQL's DDL is transactional: it is applied
 * whole or not at all.
 *
 * @since 2.6.0
 */
class PostgresBackupDialect extends AbstractBackupDialect {

	/**
	 * pg_constraint referential action codes.
	 */
	private const ACTIONS = [
		'r' => 'RESTRICT',
		'c' => 'CASCADE',
		'n' => 'SET NULL',
		'd' => 'SET DEFAULT',
	];

	/**
	 * {@inheritdoc}
	 */
	public function engine() : string {
		return 'pgsql';
	}

	/*
	|------------------------------
	| Backup
	|------------------------------
	*/

	/**
	 * {@inheritdoc}
	 */
	public function begin_snapshot() : void {
		$this->db->begin_transaction();
		$this->throw_on_error( 'Starting the backup transaction failed' );

		try {
			$this->run( 'SET TRANSACTION ISOLATION LEVEL REPEATABLE READ, READ ONLY' );
		} catch ( BackupException $e ) {
			$this->abort_snapshot();

			throw new BackupException(
				'Cannot open a consistent snapshot; is a transaction already open on this connection? ' . $e->getMessage(),
				0,
				$e
			);
		}
	}

	/**
	 * {@inheritdoc}
	 */
	public function end_snapshot() : void {
		$this->db->commit();
		$this->throw_on_error( 'Closing the backup transaction failed' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function abort_snapshot() : void {
		$this->rollback();
	}

	/**
	 * {@inheritdoc}
	 */
	public function read_table( string $table ) : TableSchema {
		$rows = $this->rows(
			"SELECT c.column_name AS name, c.data_type, c.udt_name, c.character_maximum_length AS char_length,
				c.numeric_precision AS num_precision, c.numeric_scale AS num_scale, c.is_nullable,
				c.column_default, c.is_identity, c.is_generated,
				col_description( ( quote_ident( c.table_schema ) || '.' || quote_ident( c.table_name ) )::regclass, c.ordinal_position::int ) AS comment
			FROM information_schema.columns c
			WHERE c.table_schema = 'public' AND c.table_name = ?
			ORDER BY c.ordinal_position",
			[ $table ]
		);

		if ( empty( $rows ) ) {
			throw new BackupException( sprintf( 'Cannot read table "%s": it has no columns or does not exist.', $table ) );
		}

		$definition = Table::make( $table );
		$native     = [];

		foreach ( $rows as $row ) {
			$definition->add_column( $this->read_column( $table, $row, $native ) );
		}

		$this->add_keys( $definition );

		$checks = [];

		foreach ( $this->inspector()->get_check_constraints( $table ) as $name => $check ) {
			$checks[] = [ 'name' => (string) $name, 'definition' => (string) $check['definition'] ];
		}

		return new TableSchema( self::normalized( $definition ), $this->engine(), $native, $checks );
	}

	/**
	 * One column from information_schema.columns.
	 *
	 * @param string                $table
	 * @param array<string, mixed>  $row
	 * @param array<string, string> $native Native defaults, to add to.
	 * @return Column
	 */
	protected function read_column( string $table, array $row, array &$native ) : Column {
		$name      = (string) $row['name'];
		$data_type = strtolower( (string) $row['data_type'] );
		$length    = null === $row['char_length'] ? null : (int) $row['char_length'];
		$precision = null === $row['num_precision'] ? null : (int) $row['num_precision'];
		$scale     = null === $row['num_scale'] ? null : (int) $row['num_scale'];
		$default   = null === $row['column_default'] ? null : (string) $row['column_default'];

		$column = match ( $data_type ) {
			'boolean'			=> self::column( $name, ColumnType::BOOLEAN ),
			'smallint'			=> self::column( $name, ColumnType::SMALL_INT ),
			'integer'			=> self::column( $name, ColumnType::INTEGER ),
			'bigint'			=> self::column( $name, ColumnType::BIG_INT ),
			'numeric'			=> self::column( $name, ColumnType::DECIMAL, null, $precision, $scale ),
			'real'				=> self::column( $name, ColumnType::FLOAT ),
			'double precision'	=> self::column( $name, ColumnType::DOUBLE ),
			'character'			=> self::column( $name, ColumnType::CHAR, $length ),
			'character varying'	=> null === $length
				? self::column( $name, ColumnType::LONG_TEXT )
				: self::column( $name, ColumnType::STRING, $length ),
			'text'							=> self::column( $name, ColumnType::LONG_TEXT ),
			'bytea'							=> self::column( $name, ColumnType::LONG_BLOB ),
			'date'							=> self::column( $name, ColumnType::DATE ),
			'time without time zone'		=> self::column( $name, ColumnType::TIME ),
			'timestamp without time zone'	=> self::column( $name, ColumnType::DATETIME ),
			'timestamp with time zone'		=> self::column( $name, ColumnType::TIMESTAMP ),
			'json', 'jsonb'					=> self::column( $name, ColumnType::JSON ),
			'uuid'							=> self::column( $name, ColumnType::UUID ),
			default							=> self::column( $name, ColumnType::LONG_TEXT ),
		};

		if ( 'numeric' === $data_type && null === $precision ) {
			$this->warn( sprintf( '%s.%s: NUMERIC without a precision is stored as DECIMAL(65,30).', $table, $name ) );
		} elseif ( 'timestamp with time zone' === $data_type ) {
			$this->warn( sprintf( '%s.%s: TIMESTAMP WITH TIME ZONE values keep their UTC offset as text; engines without time zones may refuse or ignore it.', $table, $name ) );
		} elseif ( ColumnType::LONG_TEXT === $column->type && ! in_array( $data_type, [ 'text', 'character varying' ], true ) ) {
			$this->warn( sprintf( '%s.%s: type %s has no portable equivalent and is stored as text.', $table, $name, (string) $row['udt_name'] ) );
		}

		$column->nullable = 'YES' === strtoupper( (string) $row['is_nullable'] );

		if ( 'ALWAYS' === strtoupper( (string) $row['is_generated'] ) ) {
			$this->warn( sprintf( '%s.%s: a generated column; its values are kept, as a plain column.', $table, $name ) );
			$default = null;
		}

		// SERIAL / BIGSERIAL (a nextval() default) or an identity column.
		if ( 'YES' === strtoupper( (string) $row['is_identity'] ) || ( null !== $default && str_starts_with( strtolower( $default ), 'nextval(' ) ) ) {
			$column->auto_increment = true;
			$default                = null;
		}

		$column->comment = (string) ( $row['comment'] ?? '' );

		if ( null !== $default ) {
			[ $value, $is_expression ] = self::parse_default( $default );
			$this->apply_default( $column, $value, $is_expression, $native );
		}

		return $column;
	}

	/**
	 * A column default as PostgreSQL reports it, as [value, is_expression].
	 *
	 * Literals carry a cast: 'abc'::character varying, '2020-01-01'::date,
	 * NULL::text, (-1). An expression is anything else.
	 *
	 * @param string $default
	 * @return array{0: mixed, 1: bool}
	 */
	protected static function parse_default( string $default ) : array {
		$text = trim( $default );

		// A parenthesised negative number: (-1), (-1.5).
		if ( preg_match( '/^\((-?\d+(?:\.\d+)?)\)$/', $text, $match ) ) {
			return [ $match[1], false ];
		}

		// Strip a trailing cast, possibly repeated: 'x'::text, 'x'::character varying(20)[]
		$uncast = preg_replace( "/^('(?:[^']|'')*'|[^':]+)(?:::[a-z_][a-z0-9_ ]*(?:\(\d+(?:,\s*\d+)?\))?(?:\[\])?)+$/i", '$1', $text );
		$literal = self::unquote_literal( (string) $uncast );

		if ( null !== $literal ) {
			return [ $literal, false ];
		}

		$uncast = trim( (string) $uncast );

		if ( 0 === strcasecmp( $uncast, 'NULL' ) ) {
			return [ null, false ];
		}

		if ( is_numeric( $uncast ) ) {
			return [ $uncast, false ];
		}

		if ( in_array( strtolower( $uncast ), [ 'true', 'false' ], true ) ) {
			return [ strtolower( $uncast ), false ];
		}

		return [ $default, true ];
	}

	/**
	 * Add the primary key, indexes and foreign keys.
	 *
	 * Foreign keys are read from pg_constraint, which pairs each column with
	 * the one it references, so composite keys keep their order.
	 *
	 * @param Table $definition
	 * @return void
	 */
	protected function add_keys( Table $definition ) : void {
		$table   = $definition->get_name();
		$primary = $this->inspector()->get_primary_key( $table );

		if ( ! empty( $primary ) ) {
			$definition->add_constraint( Constraint::primary()->on( ...$primary ) );
		}

		foreach ( $this->inspector()->get_indexes( $table ) as $name => $index ) {
			if ( empty( $index['columns'] ) ) {
				continue;
			}

			$definition->add_constraint(
				( $index['unique'] ? Constraint::unique( (string) $name ) : Constraint::index( (string) $name ) )->on( ...$index['columns'] )
			);
		}

		foreach ( $this->foreign_key_rows( 't.relname = ?', [ $table ] )[ $table ] ?? [] as $foreign ) {
			$definition->add_constraint( $foreign );
		}
	}

	/**
	 * Foreign keys of the public schema matching a condition, by table.
	 *
	 * @param string $where  Condition on pg_class t (the referencing table) and rt (the referenced one).
	 * @param array  $params Its bound values.
	 * @return array<string, Constraint[]>
	 */
	protected function foreign_key_rows( string $where, array $params ) : array {
		$rows = $this->rows(
			"SELECT t.relname AS table_name, con.conname AS name, a.attname AS column_name, rt.relname AS referenced_table,
				ra.attname AS referenced_column, con.confupdtype AS on_update, con.confdeltype AS on_delete
			FROM pg_constraint con
			JOIN pg_class t ON t.oid = con.conrelid
			JOIN pg_namespace n ON n.oid = t.relnamespace
			JOIN pg_class rt ON rt.oid = con.confrelid
			JOIN unnest( con.conkey, con.confkey ) WITH ORDINALITY AS k( attnum, refnum, ord ) ON true
			JOIN pg_attribute a ON a.attrelid = con.conrelid AND a.attnum = k.attnum
			JOIN pg_attribute ra ON ra.attrelid = con.confrelid AND ra.attnum = k.refnum
			WHERE con.contype = 'f' AND n.nspname = 'public' AND ( {$where} )
			ORDER BY t.relname, con.conname, k.ord",
			$params
		);

		$keys = [];

		foreach ( $rows as $row ) {
			$key = &$keys[ $row['table_name'] ][ $row['name'] ];

			$key['columns'][]    = $row['column_name'];
			$key['references'][] = $row['referenced_column'];
			$key['table']        = $row['referenced_table'];
			$key['on_update']    = $row['on_update'];
			$key['on_delete']    = $row['on_delete'];

			unset( $key );
		}

		$constraints = [];

		foreach ( $keys as $table => $named ) {
			foreach ( $named as $name => $key ) {
				$foreign = Constraint::foreign_key( (string) $name )
					->on( ...$key['columns'] )
					->references( (string) $key['table'], ...$key['references'] );

				if ( isset( self::ACTIONS[ $key['on_delete'] ] ) ) {
					$foreign->on_delete( self::ACTIONS[ $key['on_delete'] ] );
				}

				if ( isset( self::ACTIONS[ $key['on_update'] ] ) ) {
					$foreign->on_update( self::ACTIONS[ $key['on_update'] ] );
				}

				$constraints[ $table ][] = $foreign;
			}
		}

		return $constraints;
	}

	/**
	 * {@inheritdoc}
	 *
	 * DROP TABLE ... CASCADE removes them.
	 */
	public function foreign_keys_into( array $tables, array $excluding ) : array {
		if ( empty( $tables ) ) {
			return [];
		}

		return $this->foreign_key_rows(
			sprintf(
				'rt.relname IN (%s) AND t.relname NOT IN (%s)',
				implode( ', ', array_fill( 0, count( $tables ), '?' ) ),
				implode( ', ', array_fill( 0, max( 1, count( $excluding ) ), '?' ) )
			),
			array_merge( $tables, empty( $excluding ) ? [ '' ] : $excluding )
		);
	}

	/*
	|------------------------------
	| Restore
	|------------------------------
	*/

	/**
	 * {@inheritdoc}
	 *
	 * PostgreSQL has no unsigned integers: each is widened to the next type
	 * that holds its whole range.
	 */
	protected function adapt_column( TableSchema $schema, Column $column ) : Column {
		if ( ! $column->unsigned ) {
			return $column;
		}

		$column->unsigned = false;

		switch ( $column->type ) {
			case ColumnType::SMALL_INT:
				$column->type = ColumnType::INTEGER;
				break;

			case ColumnType::INTEGER:
				$column->type = ColumnType::BIG_INT;
				break;

			case ColumnType::BIG_INT:
				if ( $column->auto_increment ) {
					$this->warn( sprintf( '%s.%s: an unsigned BIGINT auto-increment column, created as BIGSERIAL (signed).', $schema->name(), $column->name ) );
					break;
				}

				$column->type      = ColumnType::DECIMAL;
				$column->precision = 20;
				$column->scale     = 0;
				break;
		}

		return $column;
	}

	/**
	 * {@inheritdoc}
	 *
	 * CASCADE also drops foreign keys of other tables that reference these.
	 */
	public function drop_tables( array $tables ) : void {
		if ( empty( $tables ) ) {
			return;
		}

		$this->run( 'DROP TABLE IF EXISTS ' . implode( ', ', array_map( [ $this, 'quote' ], $tables ) ) . ' CASCADE' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * Rows are restored with their IDs, which do not advance a SERIAL's
	 * sequence; it is moved past the highest ID, so the next insert does not
	 * collide with a restored row.
	 */
	public function after_load( Table $table, string $name ) : void {
		foreach ( $table->get_columns() as $column ) {
			if ( ! $column->auto_increment ) {
				continue;
			}

			$this->db->get_var(
				sprintf(
					'SELECT setval( pg_get_serial_sequence( ?, ? ), COALESCE( MAX( %1$s ), 0 ) + 1, false ) FROM %2$s',
					$this->quote( $column->name ),
					$this->quote( $name )
				),
				[ $this->quote( $name ), $column->name ]
			);

			$this->throw_on_error( sprintf( 'Resetting the sequence of %s.%s failed', $name, $column->name ) );
		}
	}
}
