<?php
declare( strict_types=1 );
/**
 * Template Name: Профил на бегач
 *
 * Shows a runner's full history across all TrailSeries races.
 * URL: /runner/?runner=Ivan+Ivanov
 *
 * The query param is deliberately NOT "name": that is a reserved WordPress
 * public query var (post slug) — WP_Query::parse_query() checks it before
 * pagename, so /runner/?name=X hijacks the main query into a single-post
 * lookup for slug "x" and 404s before this template ever runs.
 *
 * Query: searches every _tsr_result_set JSON for rows whose concatenated
 * first_name + last_name (or reversed) contains the query string.
 *
 * Dedup: some legacy source pages published one byte-identical result
 * table under two category labels (e.g. Palakaria 10.7КМ published under
 * both Жени and МЪЖЕ) — see the `_tsr_names_sha256` post meta groups also
 * handled in page-klasiraniya.php. A runner appearing in such a group would
 * otherwise show the exact same race twice. Candidates sharing a hash are
 * resolved to ONE row: prefer whichever post's detected gender
 * (tsr_race_gender()) matches a lightweight Bulgarian-name-morphology guess
 * of the runner's own gender, else the lowest post ID.
 *
 * @package exhibz-child
 */

// ── Shared helpers ────────────────────────────────────────────────────────────
//
// tsr_title_year, tsr_slug_year, tsr_event_base_name,
// tsr_dist_label_from_title and tsr_race_gender come from the
// trailseries-results plugin (includes/event-heuristics.php); the guarded
// private copies are gone — this template's tsr_slug_year still split
// slugs on '--', which sanitize_title() collapses before insert, so it ran
// against raw section slugs. tsr_slug_base() (functions.php) resolves the
// hub base slug instead.

/**
 * Lightweight guess of a runner's gender from Bulgarian first-name
 * morphology: names ending in "а"/"я" are overwhelmingly female, everything
 * else is treated as male. Known exceptions exist (e.g. "Никола", "Илия"
 * are male names ending in "а") — this is a tie-breaker for duplicate-post
 * dedup, not an authoritative gender field (the schema has none), so a rare
 * misclassification only affects which of two IDENTICAL rows is shown.
 *
 * @param string $first_name Runner's first name.
 * @return string 'M' or 'F'; '' only when the name is empty.
 */
function tsr_guess_runner_gender( string $first_name ): string {
	$first_name = trim( $first_name );
	if ( '' === $first_name ) {
		return '';
	}
	$last_char = mb_strtolower( mb_substr( $first_name, -1, 1, 'UTF-8' ), 'UTF-8' );
	return in_array( $last_char, array( 'а', 'я' ), true ) ? 'F' : 'M';
}

// ── Input ─────────────────────────────────────────────────────────────────────

// phpcs:ignore WordPress.Security.NonceVerification
$tsr_runner_query = trim( sanitize_text_field( wp_unslash( $_GET['runner'] ?? '' ) ) );
$tsr_searching    = '' !== $tsr_runner_query;

// ── Data collection ───────────────────────────────────────────────────────────

$tsr_seasons      = array(); // [ year (int) => [ entry, … ] ]
$tsr_total_starts = 0;
$tsr_total_fin    = 0;
$tsr_best_place   = null;
$tsr_first_year   = null;

if ( $tsr_searching ) {
	$tsr_q_lower = mb_strtolower( $tsr_runner_query );

	$tsr_all = get_posts( array(
		'post_type'      => 'ts_result',
		'posts_per_page' => -1,
		'post_status'    => 'publish',
	) );

	// Map every post to its `_tsr_names_sha256` hash so byte-identical
	// duplicate posts (same full results table, different category label)
	// can be resolved to one entry below. Cheap: a meta read, no JSON decode.
	$tsr_post_hash = array(); // post ID => hash
	foreach ( $tsr_all as $tsr_post ) {
		$tsr_hash = (string) get_post_meta( $tsr_post->ID, '_tsr_names_sha256', true );
		if ( '' !== $tsr_hash ) {
			$tsr_post_hash[ $tsr_post->ID ] = $tsr_hash;
		}
	}

	// Every matched (post, row) pair, before dedup. Candidates that share a
	// hash are collapsed to one below; everything else passes through.
	$tsr_candidates = array();

	foreach ( $tsr_all as $tsr_post ) {
		$tsr_raw = get_post_meta( $tsr_post->ID, '_tsr_result_set', true );
		if ( '' === $tsr_raw ) {
			continue;
		}
		$tsr_data = json_decode( $tsr_raw, true );
		if ( ! isset( $tsr_data['rows'] ) || ! is_array( $tsr_data['rows'] ) ) {
			continue;
		}

		// Meta first (canonical, set by backfill-meta), heuristics as
		// fallback — same precedence as page-rezultati.php.
		$tsr_year  = (int) get_post_meta( $tsr_post->ID, '_tsr_season', true )
			?: ( tsr_title_year( $tsr_post->post_title )
				?? tsr_slug_year( tsr_slug_base( $tsr_post ) )
				?? 0 );
		$tsr_event = (string) get_post_meta( $tsr_post->ID, '_tsr_event_base', true )
			?: tsr_event_base_name( $tsr_post->post_title )
			?: $tsr_post->post_title;
		$tsr_dist  = tsr_dist_label_from_title( $tsr_post->post_title );

		foreach ( $tsr_data['rows'] as $tsr_row ) {
			if ( ! is_array( $tsr_row ) ) {
				continue;
			}
			$tsr_fn  = (string) ( $tsr_row['first_name'] ?? '' );
			$tsr_ln  = (string) ( $tsr_row['last_name']  ?? '' );
			$tsr_fl  = mb_strtolower( trim( $tsr_fn . ' ' . $tsr_ln ) );
			$tsr_lf  = mb_strtolower( trim( $tsr_ln . ' ' . $tsr_fn ) );

			if ( false === mb_strpos( $tsr_fl, $tsr_q_lower )
				&& false === mb_strpos( $tsr_lf, $tsr_q_lower ) ) {
				continue;
			}

			$tsr_status = (string) ( $tsr_row['status'] ?? '' );
			$tsr_place  = ( isset( $tsr_row['place'] ) && is_int( $tsr_row['place'] ) )
				? $tsr_row['place']
				: null;
			$tsr_time   = (string) ( $tsr_row['finish_time'] ?? '' );

			$tsr_candidates[] = array(
				'post_id' => $tsr_post->ID,
				'hash'    => $tsr_post_hash[ $tsr_post->ID ] ?? null,
				'gender'  => tsr_race_gender( $tsr_post ),
				'name'    => $tsr_fn,
				'year'    => $tsr_year,
				'event'   => $tsr_event,
				'dist'    => $tsr_dist,
				'place'   => $tsr_place,
				'time'    => $tsr_time,
				'status'  => $tsr_status,
				'url'     => get_permalink( $tsr_post ),
			);
		}
	}

	// Resolve duplicate-hash groups to one candidate each. Candidates with
	// no hash (post has none, or a group of exactly one match) pass through.
	$tsr_by_hash = array();
	$tsr_final   = array();
	foreach ( $tsr_candidates as $tsr_c ) {
		if ( null === $tsr_c['hash'] ) {
			$tsr_final[] = $tsr_c;
		} else {
			$tsr_by_hash[ $tsr_c['hash'] ][] = $tsr_c;
		}
	}
	foreach ( $tsr_by_hash as $tsr_group ) {
		if ( count( $tsr_group ) < 2 ) {
			$tsr_final[] = $tsr_group[0];
			continue;
		}
		$tsr_guess = tsr_guess_runner_gender( $tsr_group[0]['name'] );
		$tsr_rank  = static function ( array $c ) use ( $tsr_guess ): array {
			$tsr_matches = '' !== $tsr_guess && $c['gender'] === $tsr_guess;
			return array( $tsr_matches ? 0 : 1, $c['post_id'] );
		};
		usort( $tsr_group, static fn( $a, $b ) => $tsr_rank( $a ) <=> $tsr_rank( $b ) );
		$tsr_final[] = $tsr_group[0];
	}

	foreach ( $tsr_final as $tsr_c ) {
		$tsr_total_starts++;

		if ( 'FIN' === $tsr_c['status'] ) {
			$tsr_total_fin++;
			if ( null !== $tsr_c['place']
				&& ( null === $tsr_best_place || $tsr_c['place'] < $tsr_best_place ) ) {
				$tsr_best_place = $tsr_c['place'];
			}
		}

		if ( $tsr_c['year'] > 0
			&& ( null === $tsr_first_year || $tsr_c['year'] < $tsr_first_year ) ) {
			$tsr_first_year = $tsr_c['year'];
		}

		$tsr_seasons[ $tsr_c['year'] ][] = array(
			'event'  => $tsr_c['event'],
			'dist'   => $tsr_c['dist'],
			'place'  => $tsr_c['place'],
			'time'   => $tsr_c['time'],
			'status' => $tsr_c['status'],
			'url'    => $tsr_c['url'],
		);
	}

	krsort( $tsr_seasons, SORT_NUMERIC );
}

get_header();
?>

<div class="tsr-page-hero">
	<div class="tsr-container">
		<p class="tsr-page-hero__kicker">TrailSeries.bg</p>
		<h1 class="tsr-page-hero__title">Профил на бегач</h1>
		<p class="tsr-page-hero__subtitle">
			<?php if ( $tsr_searching ) : ?>
				<?php echo esc_html( $tsr_runner_query ); ?>
			<?php else : ?>
				Търсете бегач по трите имена
			<?php endif; ?>
		</p>
	</div>
</div>

<main class="tsr-page-content">
	<div class="tsr-container">

		<?php tsr_page_breadcrumbs( 'Профил на бегач' ); ?>

		<form class="tsr-runner-search" method="get" action="">
			<label class="screen-reader-text" for="tsr-runner-name">Имена на бегача</label>
			<input
				id="tsr-runner-name"
				class="tsr-runner-search__input"
				type="search"
				name="runner"
				placeholder="Например: Иван Иванов"
				value="<?php echo esc_attr( $tsr_runner_query ); ?>"
				autocomplete="off"
				spellcheck="false">
			<button class="tsr-runner-search__btn" type="submit">Търси</button>
		</form>

		<?php if ( $tsr_searching ) : ?>

			<?php if ( empty( $tsr_seasons ) ) : ?>

				<div class="tsr-notice tsr-notice--info">
					<p>Не е намерен бегач с имена „<?php echo esc_html( $tsr_runner_query ); ?>" в базата с резултати. Проверете правописа или опитайте само с фамилното име.</p>
				</div>

			<?php else : ?>

				<!-- ── Stats strip ──────────────────────────────────────────── -->
				<div class="tsr-runner-stats">
					<div class="tsr-runner-stat">
						<div class="tsr-runner-stat__num"><?php echo esc_html( (string) $tsr_total_starts ); ?></div>
						<div class="tsr-runner-stat__label">старта</div>
					</div>
					<div class="tsr-runner-stat">
						<div class="tsr-runner-stat__num"><?php echo esc_html( (string) $tsr_total_fin ); ?></div>
						<div class="tsr-runner-stat__label">финиша</div>
					</div>
					<div class="tsr-runner-stat">
						<div class="tsr-runner-stat__num">
							<?php echo null !== $tsr_best_place ? esc_html( (string) $tsr_best_place ) : '—'; ?>
						</div>
						<div class="tsr-runner-stat__label">най-добро място</div>
					</div>
					<div class="tsr-runner-stat">
						<div class="tsr-runner-stat__num">
							<?php echo null !== $tsr_first_year ? esc_html( (string) $tsr_first_year ) : '—'; ?>
						</div>
						<div class="tsr-runner-stat__label">първи сезон</div>
					</div>
				</div>

				<!-- ── Results per season ──────────────────────────────────── -->
				<?php foreach ( $tsr_seasons as $tsr_yr => $tsr_entries ) : ?>
					<div class="tsr-runner-season">
						<h2 class="tsr-runner-season__heading">
							<?php echo $tsr_yr > 0 ? esc_html( (string) $tsr_yr ) : 'Без година'; ?>
							<span class="tsr-runner-season__count">
								— <?php echo esc_html( (string) count( $tsr_entries ) ); ?>&nbsp;старт<?php echo count( $tsr_entries ) !== 1 ? 'а' : ''; ?>
							</span>
						</h2>

						<div class="tsr-compact-wrap">
							<table class="tsr-compact-table">
								<thead>
									<tr>
										<th>Събитие</th>
										<th>Дистанция</th>
										<th>Място</th>
										<th>Време</th>
										<th>Статус</th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $tsr_entries as $tsr_e ) : ?>
										<tr class="<?php echo 'FIN' !== $tsr_e['status'] ? 'tsr-runner-row--dnx' : ''; ?>">
											<td>
												<a class="tsr-compact-table__link"
												   href="<?php echo esc_url( $tsr_e['url'] ); ?>">
													<?php echo esc_html( $tsr_e['event'] ); ?>
												</a>
											</td>
											<td><?php echo esc_html( $tsr_e['dist'] ); ?></td>
											<td class="tsr-compact-table__place">
												<?php echo null !== $tsr_e['place'] ? esc_html( (string) $tsr_e['place'] ) : '—'; ?>
											</td>
											<td class="tsr-compact-table__time">
												<?php echo '' !== $tsr_e['time'] ? esc_html( $tsr_e['time'] ) : '—'; ?>
											</td>
											<td><?php echo esc_html( $tsr_e['status'] ); ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						</div><!-- .tsr-compact-wrap -->
					</div><!-- .tsr-runner-season -->
				<?php endforeach; ?>

			<?php endif; ?>

		<?php endif; ?>

	</div><!-- .tsr-container -->
</main>

<?php get_footer(); ?>
