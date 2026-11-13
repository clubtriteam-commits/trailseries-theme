<?php
declare( strict_types=1 );
/**
 * Slug assignment for the admin results upload (audit H6) — pure logic,
 * unit-tested in tests/php; the database lookups stay in TSR_Admin_Upload.
 *
 * A re-upload must update the posts it created the first time, hub included.
 * Before 2026-11-13 the first category of a re-upload got "{base}-{label}"
 * because the hub slug was already taken, so the hub stayed stale and a
 * duplicate post was created (its runners scored twice).
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Upload_Match {

	private function __construct() {}

	/**
	 * Assign a slug to every uploaded category.
	 *
	 * Rules:
	 *  1. Existing posts of this event ("family": the hub "{base}" and
	 *     "{base}-…") from the SAME season are matched by normalised category
	 *     label (Latin/Cyrillic K/M, case, spaces), each post at most once.
	 *     A matched category reuses that post's slug and id; the hub stays
	 *     the hub.
	 *  2. Unmatched categories: the first one takes the bare hub slug when no
	 *     post holds it yet; the others get their candidate slug
	 *     "{base}-{label}" (the caller sanitises it; WordPress suffixes on
	 *     collision).
	 *  3. A hub that belongs to another season is a conflict: the event name
	 *     needs the year ("Baba Marta Run'27"), or an update would overwrite
	 *     another season's results.
	 *  4. Same-season family posts no category matched are returned as
	 *     leftovers (reported, never deleted here).
	 *
	 * @param string                                                         $base     Hub slug, sanitised ("baba-marta-run27-results").
	 * @param int                                                            $season   Season from the form.
	 * @param list<array{label: string, candidate: string}>                  $sections Uploaded categories in upload order.
	 * @param list<array{id: int, slug: string, label: string, season: string}> $family Existing family posts (stored slugs).
	 * @return array{sections: list<array{slug: string, existing_id: int, is_hub_head: bool}>, leftovers: list<array{id: int, slug: string, label: string}>, conflict: ?string}
	 */
	public static function assign( string $base, int $season, array $sections, array $family ): array {
		$hub = null;
		foreach ( $family as $post ) {
			if ( $post['slug'] === $base ) {
				$hub = $post;
			}
		}
		if ( null !== $hub && '' !== $hub['season'] && (string) $season !== $hub['season'] ) {
			return array(
				'sections'  => array(),
				'leftovers' => array(),
				'conflict'  => sprintf(
					'Слъгът "%s" вече принадлежи на сезон %s. Добавете годината в името на събитието (напр. "Baba Marta Run\'%s").',
					$base,
					$hub['season'],
					substr( (string) $season, -2 )
				),
			);
		}

		$same = array_values( array_filter(
			$family,
			static fn( array $p ): bool => '' === $p['season'] || (string) $season === $p['season']
		) );
		$used     = array();
		$hub_held = null !== $hub;
		$out      = array();

		foreach ( $sections as $section ) {
			$key   = TSR_Section_Meta::norm_cat_key( $section['label'] );
			$match = null;
			foreach ( $same as $i => $post ) {
				if ( ! isset( $used[ $i ] ) && TSR_Section_Meta::norm_cat_key( $post['label'] ) === $key ) {
					$match = $i;
					break;
				}
			}
			if ( null !== $match ) {
				$used[ $match ] = true;
				$out[]          = array(
					'slug'        => $same[ $match ]['slug'],
					'existing_id' => $same[ $match ]['id'],
					'is_hub_head' => $same[ $match ]['slug'] === $base,
				);
				continue;
			}
			if ( ! $hub_held ) {
				$hub_held = true;
				$out[]    = array( 'slug' => $base, 'existing_id' => 0, 'is_hub_head' => true );
				continue;
			}
			$out[] = array( 'slug' => $section['candidate'], 'existing_id' => 0, 'is_hub_head' => false );
		}

		$leftovers = array();
		foreach ( $same as $i => $post ) {
			if ( ! isset( $used[ $i ] ) ) {
				$leftovers[] = array( 'id' => $post['id'], 'slug' => $post['slug'], 'label' => $post['label'] );
			}
		}

		return array( 'sections' => $out, 'leftovers' => $leftovers, 'conflict' => null );
	}
}
