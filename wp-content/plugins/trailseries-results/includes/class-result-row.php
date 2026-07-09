<?php
declare( strict_types=1 );
/**
 * One result row. Immutable; validated at construction — an invalid row
 * cannot exist.
 *
 * IMPORTANT: names are sacred. This class stores first_name / last_name
 * byte-for-byte as given — no trim(), no sanitize_*(), no encoding
 * normalization. Migration byte-verifies names against the source, so any
 * transformation here would be a data-integrity bug.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Result_Row {

	/**
	 * @param ?int         $place       1-based finishing place; null for DNS/DNF/DSQ rows without one.
	 * @param string       $first_name  Stored byte-for-byte. Never transformed.
	 * @param string       $last_name   Stored byte-for-byte. Never transformed.
	 * @param string       $team        May be empty (unaffiliated runners).
	 * @param ?int         $age         Age at race time; null when unknown.
	 * @param string       $bib         Bib number as printed (may contain letters); may be empty in old results.
	 * @param string       $finish_time H:MM:SS, or '' for non-finishers.
	 * @param TSR_Status   $status      Closed enum — see TSR_Status.
	 * @param list<?string> $splits     One entry per split column of the parent set, H:MM:SS or null (missed checkpoint).
	 */
	public function __construct(
		public readonly ?int $place,
		public readonly string $first_name,
		public readonly string $last_name,
		public readonly string $team,
		public readonly ?int $age,
		public readonly string $bib,
		public readonly string $finish_time,
		public readonly TSR_Status $status,
		public readonly array $splits = array(),
	) {
		if ( '' === $this->first_name && '' === $this->last_name ) {
			throw new InvalidArgumentException( 'A result row must have a name.' );
		}

		if ( null !== $this->place && $this->place < 1 ) {
			throw new InvalidArgumentException( 'Place must be >= 1 or null.' );
		}

		if ( null !== $this->age && ( $this->age < 0 || $this->age > 130 ) ) {
			throw new InvalidArgumentException( 'Age out of range.' );
		}

		if ( '' !== $this->finish_time && ! TSR_Schema::is_valid_time( $this->finish_time ) ) {
			throw new InvalidArgumentException(
				sprintf( 'Invalid finish time "%s" — expected H:MM:SS.', $this->finish_time )
			);
		}

		if ( TSR_Status::Finished === $this->status ) {
			if ( '' === $this->finish_time ) {
				throw new InvalidArgumentException( 'A finished runner must have a finish time.' );
			}
			if ( null === $this->place ) {
				throw new InvalidArgumentException( 'A finished runner must have a place.' );
			}
		}

		if ( array_is_list( $this->splits ) === false ) {
			throw new InvalidArgumentException( 'Splits must be a list.' );
		}
		foreach ( $this->splits as $i => $split ) {
			if ( null !== $split && ( ! is_string( $split ) || ! TSR_Schema::is_valid_time( $split ) ) ) {
				throw new InvalidArgumentException(
					sprintf( 'Invalid split #%d — expected H:MM:SS or null.', $i + 1 )
				);
			}
		}
	}

	/**
	 * Serialized shape. Keys are exactly TSR_Schema::CORE_COLUMNS plus 'splits'.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'place'       => $this->place,
			'first_name'  => $this->first_name,
			'last_name'   => $this->last_name,
			'team'        => $this->team,
			'age'         => $this->age,
			'bib'         => $this->bib,
			'finish_time' => $this->finish_time,
			'status'      => $this->status->value,
			'splits'      => $this->splits,
		);
	}

	/**
	 * Strict inverse of to_array(). Unknown keys are rejected — a row with
	 * extra columns is structurally invalid, not silently tolerated.
	 *
	 * @param array<string, mixed> $data Decoded row data.
	 */
	public static function from_array( array $data ): self {
		$allowed = array_merge( TSR_Schema::CORE_COLUMNS, array( 'splits' ) );
		$extra   = array_diff( array_keys( $data ), $allowed );
		if ( array() !== $extra ) {
			throw new InvalidArgumentException(
				'Unknown row keys: ' . implode( ', ', $extra )
			);
		}
		$missing = array_diff( $allowed, array_keys( $data ) );
		if ( array() !== $missing ) {
			throw new InvalidArgumentException(
				'Missing row keys: ' . implode( ', ', $missing )
			);
		}

		if ( ! is_string( $data['status'] ) ) {
			throw new InvalidArgumentException( 'Status must be a string code.' );
		}
		$status = TSR_Status::tryFrom( $data['status'] );
		if ( null === $status ) {
			throw new InvalidArgumentException( sprintf( 'Unknown status "%s".', $data['status'] ) );
		}

		foreach ( array( 'first_name', 'last_name', 'team', 'bib', 'finish_time' ) as $key ) {
			if ( ! is_string( $data[ $key ] ) ) {
				throw new InvalidArgumentException( sprintf( '"%s" must be a string.', $key ) );
			}
		}
		foreach ( array( 'place', 'age' ) as $key ) {
			if ( null !== $data[ $key ] && ! is_int( $data[ $key ] ) ) {
				throw new InvalidArgumentException( sprintf( '"%s" must be an integer or null.', $key ) );
			}
		}
		if ( ! is_array( $data['splits'] ) ) {
			throw new InvalidArgumentException( 'Splits must be an array.' );
		}

		return new self(
			place: $data['place'],
			first_name: $data['first_name'],
			last_name: $data['last_name'],
			team: $data['team'],
			age: $data['age'],
			bib: $data['bib'],
			finish_time: $data['finish_time'],
			status: $status,
			splits: $data['splits'],
		);
	}
}
