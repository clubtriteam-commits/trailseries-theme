<?php
declare( strict_types=1 );
/**
 * Series season of a race (audit H1) — pure logic, no WordPress calls.
 *
 * The owner's definition (2026-11-13):
 *   - from 2021 a season is the calendar year (season 9 = 2021);
 *   - seasons 1–8 ran October to October: Birthday Run (mid-October) opens a
 *     season, iRan Run (~1 October) closes one; season 8 ran October 2019 to
 *     December 2020.
 *
 * `_tsr_season` stays the RACE YEAR (records, runner profiles, the event
 * history, the home page and the uploader read it that way). The season a
 * race counts for is stored separately in `_tsr_series_season` as the year
 * the season starts (2012 = season 1 … 2019 = season 8), or `none` for a
 * race that is not part of the series (e.g. ОББ Vertical Run). Posts from
 * 2021 on carry no `_tsr_series_season`: their season is their race year.
 *
 * The per-page list lives in migration/series-seasons.csv (built from the
 * old site's dates and titles, checked by the owner — docs/seasons-2012-2020.md)
 * and is applied with `wp tsr apply-seasons`, which is idempotent and must be
 * re-run after any bulk re-import.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Series_Seasons {

	public const META_KEY = '_tsr_series_season';
	public const NONE     = 'none';

	/** Last calendar year of the October-to-October seasons. */
	public const LAST_SPLIT_YEAR = 2020;

	/**
	 * Parse the CSV (header: page_slug,race_year,series_season,…).
	 *
	 * @return array<string, array{race_year: int, series_season: string}> keyed by page slug.
	 * @throws InvalidArgumentException On a malformed or inconsistent row.
	 */
	public static function parse_csv( string $csv ): array {
		$lines = preg_split( '/\r\n|\n|\r/', trim( $csv ) );
		$head  = str_getcsv( (string) array_shift( $lines ), ',', '"', '' );
		$col   = array_flip( array_map( static fn( string $h ): string => trim( $h, " \xEF\xBB\xBF" ), $head ) );
		foreach ( array( 'page_slug', 'race_year', 'series_season' ) as $need ) {
			if ( ! isset( $col[ $need ] ) ) {
				throw new InvalidArgumentException( "series-seasons CSV: missing column {$need}" );
			}
		}

		$out = array();
		foreach ( $lines as $n => $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}
			$f      = str_getcsv( $line, ',', '"', '' );
			$slug   = trim( (string) ( $f[ $col['page_slug'] ] ?? '' ) );
			$year   = (int) ( $f[ $col['race_year'] ] ?? 0 );
			$season = trim( (string) ( $f[ $col['series_season'] ] ?? '' ) );
			$row    = $n + 2;
			if ( '' === $slug || isset( $out[ $slug ] ) ) {
				throw new InvalidArgumentException( "series-seasons CSV line {$row}: empty or repeated page_slug" );
			}
			if ( $year < 2012 || $year > self::LAST_SPLIT_YEAR ) {
				throw new InvalidArgumentException( "series-seasons CSV line {$row}: race_year {$year} outside 2012–2020" );
			}
			if ( self::NONE !== $season && ! self::is_valid_split_season( $year, (int) $season ) ) {
				throw new InvalidArgumentException( "series-seasons CSV line {$row}: season {$season} impossible for a {$year} race" );
			}
			$out[ $slug ] = array(
				'race_year'     => $year,
				'series_season' => self::NONE === $season ? self::NONE : (string) (int) $season,
			);
		}
		return $out;
	}

	/**
	 * A 2012–2020 race can only count for the season that started in its own
	 * year (autumn races) or the year before (January–September races);
	 * every 2020 race is season 8 (start 2019), 2012 races are season 1.
	 */
	public static function is_valid_split_season( int $race_year, int $season ): bool {
		if ( 2012 === $race_year ) {
			return 2012 === $season;
		}
		if ( 2020 === $race_year ) {
			return 2019 === $season;
		}
		return $season === $race_year || $season === $race_year - 1;
	}

	/**
	 * Which listed page a post belongs to: its slug equals a page slug or
	 * starts with "{page}-" (section posts). The longest page slug wins, so a
	 * split page ("…-класиране-65km") beats its parent ("…-класиране").
	 *
	 * @param string        $post_slug  Decoded post_name.
	 * @param array<string> $page_slugs Decoded page slugs.
	 */
	public static function page_for( string $post_slug, array $page_slugs ): ?string {
		$best = null;
		foreach ( $page_slugs as $page ) {
			if ( ( $post_slug === $page || str_starts_with( $post_slug, $page . '-' ) )
				&& ( null === $best || strlen( $page ) > strlen( $best ) ) ) {
				$best = $page;
			}
		}
		return $best;
	}

	/**
	 * Season key a post counts for: the stored series season for 2012–2020
	 * races, the race year from 2021 on; null for no season (`none`, or no
	 * year at all).
	 */
	public static function season_key( string $race_year, string $series_season ): ?int {
		if ( self::NONE === $series_season ) {
			return null;
		}
		if ( '' !== $series_season ) {
			return (int) $series_season;
		}
		return '' !== $race_year ? (int) $race_year : null;
	}
}
