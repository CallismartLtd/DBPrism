<?php
/**
 * Backup table schema class file.
 *
 * @author Callistus Nwachukwu
 * @package Callismart\DBPrism\Backup
 * @since 2.6.0
 */

declare( strict_types=1 );

namespace Callismart\DBPrism\Backup;

use Callismart\DBPrism\Exceptions\BackupException;
use Callismart\DBPrism\Utils\Column;
use Callismart\DBPrism\Utils\ColumnType;
use Callismart\DBPrism\Utils\Constraint;
use Callismart\DBPrism\Utils\DefaultColumnValue;
use Callismart\DBPrism\Utils\Table;

/**
 * One table as a backup records it: an engine-neutral Table, plus the parts
 * that only make sense on the engine they came from.
 *
 * The Table holds columns with canonical ColumnType types and primary key,
 * unique, index and foreign key constraints, so the query builder can create
 * it on any engine. Two things cannot be translated and are kept apart, tagged
 * with their engine, to be restored on that engine only:
 *
 *  - native defaults: a DEFAULT expression other than CURRENT_TIMESTAMP,
 *    CURRENT_DATE or CURRENT_TIME (e.g. gen_random_uuid());
 *  - CHECK constraints, whose definitions are engine SQL.
 *
 * @since 2.6.0
 */
final class TableSchema {

	/**
	 * Canonical type names as they are written in an archive.
	 *
	 * Names, not ColumnType's numbers, so an archive stays readable and
	 * does not depend on the numbering.
	 */
	public const TYPE_NAMES = [
		ColumnType::BOOLEAN   => 'BOOLEAN',
		ColumnType::SMALL_INT => 'SMALL_INT',
		ColumnType::INTEGER   => 'INTEGER',
		ColumnType::BIG_INT   => 'BIG_INT',
		ColumnType::DECIMAL   => 'DECIMAL',
		ColumnType::FLOAT     => 'FLOAT',
		ColumnType::DOUBLE    => 'DOUBLE',
		ColumnType::CHAR      => 'CHAR',
		ColumnType::STRING    => 'STRING',
		ColumnType::TEXT      => 'TEXT',
		ColumnType::LONG_TEXT => 'LONG_TEXT',
		ColumnType::BINARY    => 'BINARY',
		ColumnType::BLOB      => 'BLOB',
		ColumnType::LONG_BLOB => 'LONG_BLOB',
		ColumnType::DATE      => 'DATE',
		ColumnType::TIME      => 'TIME',
		ColumnType::DATETIME  => 'DATETIME',
		ColumnType::TIMESTAMP => 'TIMESTAMP',
		ColumnType::JSON      => 'JSON',
		ColumnType::UUID      => 'UUID',
	];

	/**
	 * DEFAULT expressions every engine understands, written as they are.
	 */
	public const PORTABLE_EXPRESSIONS = [ 'CURRENT_TIMESTAMP', 'CURRENT_DATE', 'CURRENT_TIME' ];

	/**
	 * Constructor.
	 *
	 * @param Table                                                   $table           The portable definition.
	 * @param string                                                  $engine          The engine the table was read from.
	 * @param array<string, string>                                   $native_defaults Column => DEFAULT expression of $engine.
	 * @param array<int, array{name: string|null, definition: string}> $checks         CHECK constraints in $engine's SQL.
	 */
	public function __construct(
		public readonly Table $table,
		public readonly string $engine,
		public readonly array $native_defaults = [],
		public readonly array $checks = [],
	) {}

	/**
	 * The table name.
	 *
	 * @return string
	 */
	public function name() : string {
		return $this->table->get_name();
	}

	/**
	 * The column with a name, or null.
	 *
	 * @param string $name
	 * @return Column|null
	 */
	public function column( string $name ) : ?Column {
		foreach ( $this->table->get_columns() as $column ) {
			if ( $column->name === $name ) {
				return $column;
			}
		}

		return null;
	}

	/**
	 * The primary key columns, or an empty array.
	 *
	 * @return string[]
	 */
	public function primary_key() : array {
		foreach ( $this->table->get_constraints() as $constraint ) {
			if ( 'primary' === $constraint->type ) {
				return $constraint->columns;
			}
		}

		return [];
	}

	/**
	 * The foreign key constraints.
	 *
	 * @return Constraint[]
	 */
	public function foreign_keys() : array {
		return array_values( array_filter(
			$this->table->get_constraints(),
			fn( Constraint $constraint ) => 'foreign' === $constraint->type
		) );
	}

	/*
	|------------------------------
	| Archive form
	|------------------------------
	*/

	/**
	 * The table record written to an archive.
	 *
	 * @return array<string, mixed>
	 */
	public function to_record() : array {
		$record = [
			'kind'        => 'table',
			'name'        => $this->name(),
			'engine'      => $this->engine,
			'columns'     => array_map( [ self::class, 'column_to_array' ], $this->table->get_columns() ),
			'constraints' => array_map( [ self::class, 'constraint_to_array' ], $this->table->get_constraints() ),
		];

		if ( ! empty( $this->native_defaults ) ) {
			$record['native_defaults'] = $this->native_defaults;
		}

		if ( ! empty( $this->checks ) ) {
			$record['checks'] = $this->checks;
		}

		return $record;
	}

	/**
	 * Read a table record from an archive.
	 *
	 * @param array<string, mixed> $record
	 * @return self
	 * @throws BackupException When the record is malformed.
	 */
	public static function from_record( array $record ) : self {
		$name = $record['name'] ?? null;

		if ( ! is_string( $name ) || '' === $name || ! is_array( $record['columns'] ?? null ) || empty( $record['columns'] ) ) {
			throw new BackupException( 'The backup is damaged: a table record has no name or no columns.' );
		}

		$table = Table::make( $name );

		foreach ( $record['columns'] as $column ) {
			$table->add_column( self::column_from_array( $name, $column ) );
		}

		foreach ( $record['constraints'] ?? [] as $constraint ) {
			$table->add_constraint( self::constraint_from_array( $name, $constraint ) );
		}

		$native_defaults = $record['native_defaults'] ?? [];
		$checks          = $record['checks'] ?? [];

		if ( ! is_array( $native_defaults ) || ! is_array( $checks ) ) {
			throw new BackupException( sprintf( 'The backup is damaged: table "%s" has malformed defaults or checks.', $name ) );
		}

		return new self( $table, (string) ( $record['engine'] ?? '' ), $native_defaults, array_values( $checks ) );
	}

	/**
	 * A column as an archive array.
	 *
	 * @param Column $column
	 * @return array<string, mixed>
	 */
	public static function column_to_array( Column $column ) : array {
		$array = [
			'name'     => $column->name,
			'type'     => self::TYPE_NAMES[ $column->type ] ?? throw new BackupException(
				sprintf( 'Column "%s" has an unknown type %d.', $column->name, $column->type )
			),
			'nullable' => $column->nullable,
		];

		foreach ( [ 'length', 'precision', 'scale' ] as $size ) {
			if ( null !== $column->{$size} ) {
				$array[ $size ] = $column->{$size};
			}
		}

		if ( $column->unsigned ) {
			$array['unsigned'] = true;
		}

		if ( $column->auto_increment ) {
			$array['auto_increment'] = true;
		}

		if ( null !== $column->default ) {
			$array['default'] = $column->default instanceof DefaultColumnValue && $column->default->is_expression()
				? [ 'expression' => (string) $column->default ]
				: [ 'value' => $column->default instanceof DefaultColumnValue ? $column->default->value() : $column->default ];
		}

		if ( '' !== $column->comment ) {
			$array['comment'] = $column->comment;
		}

		return $array;
	}

	/**
	 * A column from an archive array.
	 *
	 * A literal default is wrapped as a DefaultColumnValue literal, so the
	 * renderer quotes it however it looks; an expression default is one of
	 * PORTABLE_EXPRESSIONS.
	 *
	 * @param string $table
	 * @param mixed  $array
	 * @return Column
	 * @throws BackupException When the column is malformed.
	 */
	public static function column_from_array( string $table, mixed $array ) : Column {
		$name = is_array( $array ) ? ( $array['name'] ?? null ) : null;
		$type = is_array( $array ) ? array_search( $array['type'] ?? null, self::TYPE_NAMES, true ) : false;

		if ( ! is_string( $name ) || '' === $name || false === $type ) {
			throw new BackupException( sprintf( 'The backup is damaged: table "%s" has a column with no name or an unknown type.', $table ) );
		}

		$column           = Column::make( $name )->type( $type );
		$column->nullable = (bool) ( $array['nullable'] ?? true );

		foreach ( [ 'length', 'precision', 'scale' ] as $size ) {
			if ( isset( $array[ $size ] ) ) {
				$column->{$size} = (int) $array[ $size ];
			}
		}

		$column->unsigned       = ! empty( $array['unsigned'] );
		$column->auto_increment = ! empty( $array['auto_increment'] );
		$column->comment        = (string) ( $array['comment'] ?? '' );

		$default = $array['default'] ?? null;

		if ( is_array( $default ) && array_key_exists( 'value', $default ) && null !== $default['value'] ) {
			$column->default = DefaultColumnValue::make( ValueCodec::typed_literal( $column, $default['value'] ) );
		} elseif ( is_array( $default ) && in_array( $default['expression'] ?? null, self::PORTABLE_EXPRESSIONS, true ) ) {
			$column->default = DefaultColumnValue::expression( $default['expression'] );
		}

		return $column;
	}

	/**
	 * A constraint as an archive array.
	 *
	 * @param Constraint $constraint
	 * @return array<string, mixed>
	 */
	public static function constraint_to_array( Constraint $constraint ) : array {
		$array = [ 'type' => $constraint->type ];

		if ( null !== $constraint->name ) {
			$array['name'] = $constraint->name;
		}

		$array['columns'] = array_values( $constraint->columns );

		if ( 'foreign' === $constraint->type ) {
			$array['references'] = [
				'table'   => $constraint->references_table,
				'columns' => array_values( $constraint->references_columns ),
			];

			if ( null !== $constraint->on_delete ) {
				$array['on_delete'] = $constraint->on_delete;
			}

			if ( null !== $constraint->on_update ) {
				$array['on_update'] = $constraint->on_update;
			}
		}

		return $array;
	}

	/**
	 * A constraint from an archive array.
	 *
	 * @param string $table
	 * @param mixed  $array
	 * @return Constraint
	 * @throws BackupException When the constraint is malformed.
	 */
	public static function constraint_from_array( string $table, mixed $array ) : Constraint {
		$type    = is_array( $array ) ? ( $array['type'] ?? null ) : null;
		$columns = is_array( $array ) ? ( $array['columns'] ?? null ) : null;

		if ( ! in_array( $type, [ 'primary', 'unique', 'index', 'foreign' ], true ) || ! is_array( $columns ) || empty( $columns ) ) {
			throw new BackupException( sprintf( 'The backup is damaged: table "%s" has a malformed constraint.', $table ) );
		}

		$constraint = Constraint::make( $type )->on( ...array_map( 'strval', $columns ) );

		if ( isset( $array['name'] ) && '' !== $array['name'] ) {
			$constraint->name( (string) $array['name'] );
		}

		if ( 'foreign' === $type ) {
			$references = $array['references'] ?? null;

			if ( ! is_array( $references ) || ! is_string( $references['table'] ?? null ) || empty( $references['columns'] ) ) {
				throw new BackupException( sprintf( 'The backup is damaged: a foreign key of table "%s" has no referenced table.', $table ) );
			}

			$constraint->references( $references['table'], ...array_map( 'strval', $references['columns'] ) );

			if ( isset( $array['on_delete'] ) ) {
				$constraint->on_delete( (string) $array['on_delete'] );
			}

			if ( isset( $array['on_update'] ) ) {
				$constraint->on_update( (string) $array['on_update'] );
			}
		}

		return $constraint;
	}
}
