<?php
declare( strict_types=1 );
/**
 * Runner aliases — one person, several spellings (audit M2, first step).
 *
 * Stored names are never changed (ADR-002: results are byte-verified against
 * the source). When the owner confirms that two spellings are the same
 * person, the spelling is mapped to one canonical name here, and standings,
 * the runner search and profiles aggregate under it.
 *
 * The map lives in the `tsr_runner_aliases` option (key() of the variant =>
 * canonical full name), managed with `wp tsr alias`. It is deliberately not
 * a file in the repository: it holds names of real people.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Runner_Alias {

	public const OPTION = 'tsr_runner_aliases';

	/** Comparison key of a full name: lower case, single spaces. */
	public static function key( string $full_name ): string {
		return mb_strtolower( trim( (string) preg_replace( '/\s+/u', ' ', $full_name ) ) );
	}

	/**
	 * Canonical full name for a spelling, or the spelling itself.
	 *
	 * @param array<string, string> $map key() => canonical name.
	 */
	public static function canonical( string $full_name, array $map ): string {
		return $map[ self::key( $full_name ) ] ?? $full_name;
	}

	/**
	 * Add or replace an alias; returns the new map.
	 *
	 * @param array<string, string> $map
	 * @throws InvalidArgumentException On an empty name, a self-alias, or a chain.
	 */
	public static function with( array $map, string $variant, string $canonical ): array {
		$variant   = trim( $variant );
		$canonical = trim( (string) preg_replace( '/\s+/u', ' ', $canonical ) );
		if ( '' === $variant || '' === $canonical ) {
			throw new InvalidArgumentException( 'alias: empty name' );
		}
		if ( self::key( $variant ) === self::key( $canonical ) ) {
			throw new InvalidArgumentException( 'alias: variant and canonical are the same spelling' );
		}
		if ( isset( $map[ self::key( $canonical ) ] ) ) {
			throw new InvalidArgumentException( 'alias: the canonical name is itself an alias of ' . $map[ self::key( $canonical ) ] );
		}
		foreach ( $map as $k => $target ) {
			if ( self::key( $target ) === self::key( $variant ) ) {
				throw new InvalidArgumentException( 'alias: the variant is the canonical name of another alias' );
			}
		}
		$map[ self::key( $variant ) ] = $canonical;
		ksort( $map );
		return $map;
	}
}
