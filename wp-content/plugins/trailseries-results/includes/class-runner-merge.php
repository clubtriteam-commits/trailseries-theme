<?php
declare( strict_types=1 );
/**
 * Automatic merging of a runner's spellings (owner decision 2026-11-14).
 *
 * Until 2016 the protocols were typed in Cyrillic, from 2017 mostly in Latin,
 * so one person is "Гергана Ангелова" in some tables and "Gergana Angelova"
 * in others. plan() groups spellings whose transliteration-folded key is the
 * same and maps them to one canonical name — but only when nothing in the
 * data contradicts it:
 *
 *   - two different spellings of the group appear in the same race
 *     (two people, not one), or
 *   - the birth years implied by the recorded ages differ by more than
 *     AGE_TOLERANCE years.
 *
 * Such groups are left alone; so are one-word names and names on the
 * owner's "do not merge" list. Everything else is fixed by hand on request
 * (`wp tsr alias`, `wp tsr alias-auto --unmerge`).
 *
 * Stored results are never changed (ADR-002) — like TSR_Runner_Alias this
 * only produces a spelling → canonical map, kept in its own option so it can
 * be rebuilt or dropped without touching the owner-confirmed aliases, which
 * always win.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Runner_Merge {

	public const OPTION        = 'tsr_runner_aliases_auto';
	public const BLOCK_OPTION  = 'tsr_runner_no_merge';
	public const AGE_TOLERANCE = 2;

	private const CYR = array(
		'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e', 'ж' => 'zh', 'з' => 'z',
		'и' => 'i', 'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p',
		'р' => 'r', 'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
		'ш' => 'sh', 'щ' => 'sht', 'ъ' => 'a', 'ь' => 'y', 'ю' => 'yu', 'я' => 'ya', 'ѝ' => 'i',
	);

	/** Spelling variants of the same sound, applied in this order. */
	private const FOLDS = array(
		'sch' => 'sh', 'kh' => 'h', 'ph' => 'f', 'w' => 'v', 'x' => 'ks', 'q' => 'k', 'dzh' => 'j',
		'dj' => 'j', 'zh' => 'j', 'tz' => 'c', 'ts' => 'c', 'ou' => 'u', 'y' => 'i', 'ck' => 'k',
	);

	/** One word → script- and spelling-independent key ("Илиян", "Iliyan", "Ilian" → "ilian"). */
	public static function fold_word( string $word ): string {
		$w = strtr( mb_strtolower( $word ), self::CYR );
		$w = (string) preg_replace( '/[^a-z]/', '', $w );
		foreach ( self::FOLDS as $from => $to ) {
			$w = str_replace( $from, $to, $w );
		}
		return (string) preg_replace( '/(.)\1+/', '$1', $w );
	}

	/** Full name → key that ignores script, spelling variants and word order. */
	public static function fold_key( string $full_name ): string {
		$words = array();
		foreach ( preg_split( '/[\s\-]+/u', $full_name ) ?: array() as $word ) {
			$k = self::fold_word( $word );
			if ( '' !== $k ) {
				$words[] = $k;
			}
		}
		sort( $words );
		return implode( ' ', $words );
	}

	/**
	 * Decide which spellings are merged.
	 *
	 * @param iterable<array{name:string, race:string, year:int, age:?int, bib:string}> $rows
	 *        One entry per result row; `race` identifies the race (event + year).
	 * @param array<string, string> $manual  Owner-confirmed aliases (TSR_Runner_Alias map).
	 * @param list<string>          $blocked fold_key()s that must never be merged.
	 * @return array{map: array<string, string>, merged: list<array{canonical:string, spellings:list<string>}>, skipped: list<array{reason:string, spellings:list<string>}>}
	 */
	public static function plan( iterable $rows, array $manual = array(), array $blocked = array() ): array {
		$targets = array(); // alias key of every owner-chosen canonical name
		foreach ( $manual as $canonical ) {
			$targets[ TSR_Runner_Alias::key( $canonical ) ] = true;
		}
		$blocked = array_flip( $blocked );

		$groups = array();
		foreach ( $rows as $row ) {
			$name = trim( (string) preg_replace( '/\s+/u', ' ', (string) $row['name'] ) );
			$name = TSR_Runner_Alias::canonical( $name, $manual );
			$fold = self::fold_key( $name );
			if ( substr_count( $fold, ' ' ) < 1 ) {
				continue; // empty or a single word: too little to identify a person
			}
			$key = TSR_Runner_Alias::key( $name );
			$g   = &$groups[ $fold ];
			$g['shown'][ $name ]              = ( $g['shown'][ $name ] ?? 0 ) + 1;
			$g['keys'][ $key ]                = true;
			$g['races'][ $row['race'] ][ $key ] = true;
			$age = $row['age'];
			if ( null !== $age && $age > 5 && $age < 90 && (string) $age !== trim( (string) $row['bib'] ) && $row['year'] > 0 ) {
				$g['births'][] = $row['year'] - $age; // an age equal to the bib is a known import artefact
			}
			unset( $g );
		}

		$out = array( 'map' => array(), 'merged' => array(), 'skipped' => array() );
		foreach ( $groups as $fold => $g ) {
			if ( count( $g['shown'] ) < 2 ) {
				continue;
			}
			$spellings = array_keys( $g['shown'] );
			sort( $spellings );
			$several = count( $g['keys'] ) > 1; // more than a difference in letter case
			$reason  = '';
			if ( $several && isset( $blocked[ $fold ] ) ) {
				$reason = 'blocked';
			} elseif ( $several && array_filter( $g['races'], static fn( array $keys ): bool => count( $keys ) > 1 ) ) {
				$reason = 'same-race';
			} elseif ( $several && isset( $g['births'] ) && max( $g['births'] ) - min( $g['births'] ) > self::AGE_TOLERANCE ) {
				$reason = 'age';
			}
			if ( '' !== $reason ) {
				$out['skipped'][] = array( 'reason' => $reason, 'spellings' => $spellings );
				continue;
			}
			$canonical = self::pick( $g['shown'], $targets );
			foreach ( array_keys( $g['keys'] ) as $key ) {
				$out['map'][ $key ] = $canonical;
			}
			$out['merged'][] = array( 'canonical' => $canonical, 'spellings' => $spellings );
		}
		ksort( $out['map'] );
		return $out;
	}

	/**
	 * The name a merged group is shown under: the owner's choice if there is
	 * one, else the most frequent spelling (properly cased before all-lower /
	 * all-upper, then alphabetical so the result is stable).
	 *
	 * @param array<string, int>  $shown   Spelling => number of rows.
	 * @param array<string, true> $targets Alias keys of owner-chosen names.
	 */
	private static function pick( array $shown, array $targets ): string {
		foreach ( array_keys( $shown ) as $name ) {
			if ( isset( $targets[ TSR_Runner_Alias::key( $name ) ] ) ) {
				return $name;
			}
		}
		$names = array_keys( $shown );
		usort(
			$names,
			static function ( string $a, string $b ) use ( $shown ): int {
				$cased = static fn( string $n ): int => ( $n !== mb_strtolower( $n ) && $n !== mb_strtoupper( $n ) ) ? 0 : 1;
				return array( -$shown[ $a ], $cased( $a ), $a ) <=> array( -$shown[ $b ], $cased( $b ), $b );
			}
		);
		return $names[0];
	}

	/**
	 * The map the site uses: automatic merges, overridden by owner aliases.
	 *
	 * @param array<string, string> $manual
	 * @param array<string, string> $auto
	 * @return array<string, string>
	 */
	public static function effective( array $manual, array $auto ): array {
		return $manual + $auto;
	}
}
