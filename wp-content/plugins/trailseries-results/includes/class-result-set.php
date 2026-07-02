<?php
/**
 * A complete results table for one race. Owns the split-column definition and
 * guarantees every row matches it: a row with the wrong number of splits is
 * rejected at add() time, so a ragged table cannot be built.
 *
 * Column order is not configurable anywhere — column_labels() derives it from
 * TSR_Schema::CORE_COLUMNS plus this set's split labels, and TSR_Renderer has
 * no other input for headers.
 *
 * @package trailseries-results
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Result_Set {

	/**
	 * @var list<TSR_Result_Row>
	 */
	private array $rows = array();

	/**
	 * @param list<string> $split_labels Ordered labels of the optional split
	 *                                   columns, e.g. [ 'CP1 Aleko', 'CP2 Cherni Vrah' ].
	 *                                   Empty for races without splits.
	 */
	public function __construct( private readonly array $split_labels = array() ) {
		if ( ! array_is_list( $this->split_labels ) ) {
			throw new InvalidArgumentException( 'Split labels must be a list.' );
		}
		foreach ( $this->split_labels as $label ) {
			if ( ! is_string( $label ) || '' === trim( $label ) ) {
				throw new InvalidArgumentException( 'Every split label must be a non-empty string.' );
			}
		}
		if ( count( $this->split_labels ) !== count( array_unique( $this->split_labels ) ) ) {
			throw new InvalidArgumentException( 'Split labels must be unique.' );
		}
	}

	public function add( TSR_Result_Row $row ): void {
		if ( count( $row->splits ) !== count( $this->split_labels ) ) {
			throw new InvalidArgumentException(
				sprintf(
					'Row has %d splits but this result set defines %d split columns.',
					count( $row->splits ),
					count( $this->split_labels )
				)
			);
		}
		$this->rows[] = $row;
	}

	/**
	 * @return list<TSR_Result_Row>
	 */
	public function rows(): array {
		return $this->rows;
	}

	/**
	 * @return list<string>
	 */
	public function split_labels(): array {
		return $this->split_labels;
	}

	/**
	 * The one and only source of table headers: canonical core labels
	 * followed by this set's split labels.
	 *
	 * @return list<string>
	 */
	public function column_labels(): array {
		return array_merge( array_values( TSR_Schema::core_labels() ), $this->split_labels );
	}

	/**
	 * @return array{schema_version:int, split_labels:list<string>, rows:list<array<string,mixed>>}
	 */
	public function to_array(): array {
		return array(
			'schema_version' => TSR_Schema::VERSION,
			'split_labels'   => $this->split_labels,
			'rows'           => array_map(
				static fn ( TSR_Result_Row $row ): array => $row->to_array(),
				$this->rows
			),
		);
	}

	/**
	 * Strict inverse of to_array(). Throws on any deviation from the canonical
	 * shape — this runs on every load, so data that drifted out of schema can
	 * never render.
	 *
	 * @param array<string, mixed> $data Decoded result-set data.
	 */
	public static function from_array( array $data ): self {
		if ( ! isset( $data['schema_version'] ) || TSR_Schema::VERSION !== $data['schema_version'] ) {
			throw new InvalidArgumentException(
				sprintf(
					'Unsupported schema version %s (expected %d).',
					var_export( $data['schema_version'] ?? null, true ),
					TSR_Schema::VERSION
				)
			);
		}
		if ( ! isset( $data['split_labels'] ) || ! is_array( $data['split_labels'] ) ) {
			throw new InvalidArgumentException( 'Missing split_labels.' );
		}
		if ( ! isset( $data['rows'] ) || ! is_array( $data['rows'] ) || ! array_is_list( $data['rows'] ) ) {
			throw new InvalidArgumentException( 'Missing rows list.' );
		}

		$set = new self( $data['split_labels'] );
		foreach ( $data['rows'] as $i => $row ) {
			if ( ! is_array( $row ) ) {
				throw new InvalidArgumentException( sprintf( 'Row %d is not an object.', $i ) );
			}
			try {
				$set->add( TSR_Result_Row::from_array( $row ) );
			} catch ( InvalidArgumentException $e ) {
				throw new InvalidArgumentException( sprintf( 'Row %d: %s', $i, $e->getMessage() ), 0, $e );
			}
		}
		return $set;
	}
}
