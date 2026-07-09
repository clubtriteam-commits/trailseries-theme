<?php
/**
 * Admin CSV upload for race results — no WP-CLI needed.
 *
 * Tools → "TSR — Качване на резултати". Two-step flow:
 *
 *   1. Upload: event fields + CSV file → parse, validate every row through
 *      TSR_Result_Row/TSR_Result_Set (the same objects the importer uses —
 *      no reimplemented validation), stash the normalized payload in a
 *      transient, and show a preview with warnings/errors.
 *   2. Confirm: rebuild the set from the transient, create or update the
 *      ts_result post, persist via TSR_Repository::save() (round-trip
 *      verified), and write the same meta the CLI backfill produces.
 *
 * Slug scheme matches the migrated content: the FIRST category of an event
 * gets the bare "{event-slug}-results" slug (it becomes the hub post that
 * single-ts_result.php expands); later categories get
 * "{event-slug}-results-{category-slug}". Note sanitize_title() collapses
 * dashes and percent-encodes Cyrillic, same as the imported siblings.
 *
 * CSV format: place, first_name, last_name, team, age, bib, finish_time,
 * status — comma or semicolon delimited (auto-detected), optional header
 * row (auto-detected), UTF-8 or Windows-1251 (auto-converted).
 *
 * @package trailseries-results
 */

declare( strict_types=1 );

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Admin_Upload {

	private const PAGE_SLUG     = 'tsr-upload-results';
	private const NONCE_UPLOAD  = 'tsr_upload_step1';
	private const NONCE_CONFIRM = 'tsr_upload_step2';
	private const MAX_FILE_SIZE = 5 * 1024 * 1024;
	private const PREVIEW_ROWS  = 20;

	private function __construct() {}

	public static function register(): void {
		add_action( 'admin_menu', static function (): void {
			add_management_page(
				'TSR — Качване на резултати',
				'TSR — Качване на резултати',
				'manage_options',
				self::PAGE_SLUG,
				array( self::class, 'render_page' )
			);
		} );
	}

	// ── Page controller ──────────────────────────────────────────────────────

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'Нямате права за тази страница.', 'trailseries-results' ) );
		}

		echo '<div class="wrap"><h1>Качване на резултати (CSV)</h1>';

		if ( isset( $_POST['tsr_confirm'] ) ) {
			self::handle_confirm();
		} elseif ( isset( $_FILES['tsr_csv'] ) ) {
			self::handle_upload();
		} else {
			self::render_form();
		}

		echo '</div>';
	}

	// ── Step 1: upload + parse + preview ─────────────────────────────────────

	private static function handle_upload(): void {
		check_admin_referer( self::NONCE_UPLOAD );

		$fields = self::read_fields();
		if ( is_string( $fields ) ) {
			self::notice( 'error', $fields );
			self::render_form();
			return;
		}

		$csv = self::read_upload();
		if ( is_string( $csv ) === false ) {
			self::notice( 'error', $csv['error'] );
			self::render_form();
			return;
		}

		$parsed = self::parse_csv( $csv );

		// Validate every row through the plugin's own value objects.
		$errors = $parsed['errors'];
		if ( array() === $errors ) {
			try {
				self::build_set( $parsed['rows'] );
			} catch ( InvalidArgumentException $e ) {
				$errors[] = $e->getMessage();
			}
		}

		$slug_info = self::resolve_slug( $fields['event_name'], $fields['category_label'] );

		$payload = array(
			'fields'    => $fields,
			'rows'      => $parsed['rows'],
			'slug_info' => $slug_info,
		);
		set_transient( self::transient_key(), $payload, HOUR_IN_SECONDS );

		self::render_preview( $fields, $parsed, $slug_info, $errors );
	}

	/**
	 * @return array{event_name:string, season:int, distance_cat:string, distance_km:float, category_label:string}|string
	 *         Sanitized fields, or an error message.
	 */
	private static function read_fields(): array|string {
		$event_name     = sanitize_text_field( wp_unslash( $_POST['tsr_event_name'] ?? '' ) );
		$season         = absint( $_POST['tsr_season'] ?? 0 );
		$distance_cat   = sanitize_key( $_POST['tsr_distance_cat'] ?? '' );
		$distance_km    = (float) str_replace( ',', '.', sanitize_text_field( wp_unslash( $_POST['tsr_distance_km'] ?? '' ) ) );
		$category_label = sanitize_text_field( wp_unslash( $_POST['tsr_category_label'] ?? '' ) );

		if ( '' === $event_name ) {
			return 'Липсва име на събитието.';
		}
		if ( $season < 2012 || $season > 2100 ) {
			return 'Невалиден сезон (очаква се година 2012–2100).';
		}
		if ( ! in_array( $distance_cat, array( 'short', 'medium', 'long', 'bonus' ), true ) ) {
			return 'Невалидна категория дистанция.';
		}
		if ( $distance_km <= 0 || $distance_km > 200 ) {
			return 'Невалидна дистанция в километри.';
		}
		if ( '' === $category_label ) {
			return 'Липсва етикет на категорията (напр. "16КМ МЪЖЕ").';
		}

		return compact( 'event_name', 'season', 'distance_cat', 'distance_km', 'category_label' );
	}

	/**
	 * @return string|array{error:string} File contents (UTF-8), or error.
	 */
	private static function read_upload(): string|array {
		$file = $_FILES['tsr_csv'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return array( 'error' => 'Файлът не беше качен успешно.' );
		}
		if ( $file['size'] > self::MAX_FILE_SIZE ) {
			return array( 'error' => 'Файлът е над 5 MB.' );
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return array( 'error' => 'Невалидно качване.' );
		}
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt' ), true ) ) {
			return array( 'error' => 'Очаква се .csv или .txt файл.' );
		}

		$raw = file_get_contents( $file['tmp_name'] );
		if ( false === $raw || '' === trim( $raw ) ) {
			return array( 'error' => 'Файлът е празен или нечетим.' );
		}

		// Strip UTF-8 BOM; convert Windows-1251 exports (common from Excel BG).
		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1251' );
		}

		return $raw;
	}

	/**
	 * Parse CSV text into normalized row arrays (TSR_Result_Row::from_array
	 * shape). Delimiter and header row are auto-detected.
	 *
	 * @return array{rows:list<array<string,mixed>>, warnings:list<string>, errors:list<string>, delimiter:string, had_header:bool}
	 */
	private static function parse_csv( string $text ): array {
		$lines = preg_split( '/\r\n|\r|\n/', $text ) ?: array();
		$lines = array_values( array_filter( $lines, static fn( string $l ): bool => '' !== trim( $l ) ) );

		// Delimiter: whichever of ';' and ',' occurs more in the first line.
		$first     = $lines[0] ?? '';
		$delimiter = substr_count( $first, ';' ) > substr_count( $first, ',' ) ? ';' : ',';

		$rows       = array();
		$warnings   = array();
		$errors     = array();
		$had_header = false;

		foreach ( $lines as $i => $line ) {
			$cells = str_getcsv( $line, $delimiter, '"', '\\' );
			$cells = array_map( static fn( ?string $c ): string => trim( (string) $c ), $cells );

			// Header detection: only on the first row — non-numeric first cell
			// or known header keywords means it is a header, not data.
			if ( 0 === $i ) {
				$joined = mb_strtolower( implode( ' ', $cells ), 'UTF-8' );
				$kw     = (bool) preg_match( '/place|място|first|last|име|фамилия|status|статус/u', $joined );
				if ( $kw || ( '' !== $cells[0] && ! ctype_digit( $cells[0] ) ) ) {
					$had_header = true;
					continue;
				}
			}

			$line_no = $i + 1;
			$cells   = array_pad( $cells, 8, '' );
			list( $place_raw, $first_name, $last_name, $team, $age_raw, $bib, $finish_time, $status_raw ) = $cells;

			// place: int or null.
			$place = ctype_digit( $place_raw ) ? (int) $place_raw : null;
			if ( null === $place && '' !== $place_raw ) {
				$errors[] = sprintf( 'Ред %d: невалидно място "%s".', $line_no, $place_raw );
				continue;
			}

			// age: int or null.
			$age = ctype_digit( $age_raw ) ? (int) $age_raw : null;
			if ( null === $age && '' !== $age_raw ) {
				$warnings[] = sprintf( 'Ред %d: невалидна възраст "%s" — записана като празна.', $line_no, $age_raw );
			}

			// status: closed enum; empty defaults to FIN when the row looks finished.
			$status = strtoupper( $status_raw );
			if ( '' === $status ) {
				if ( '' !== $finish_time && null !== $place ) {
					$status     = 'FIN';
					$warnings[] = sprintf( 'Ред %d: липсва статус — приет като FIN.', $line_no );
				} else {
					$errors[] = sprintf( 'Ред %d: липсва статус, а редът няма време/място.', $line_no );
					continue;
				}
			}
			if ( null === TSR_Status::tryFrom( $status ) ) {
				$errors[] = sprintf( 'Ред %d: непознат статус "%s" (очаква се FIN/DNF/DNS/DSQ/OTL).', $line_no, $status_raw );
				continue;
			}

			if ( '' !== $finish_time && ! TSR_Schema::is_valid_time( $finish_time ) ) {
				$errors[] = sprintf( 'Ред %d: невалидно време "%s" (очаква се Ч:ММ:СС).', $line_no, $finish_time );
				continue;
			}

			if ( '' === $first_name && '' === $last_name ) {
				$errors[] = sprintf( 'Ред %d: липсва име.', $line_no );
				continue;
			}
			if ( '' === $bib ) {
				$warnings[] = sprintf( 'Ред %d: липсва стартов номер.', $line_no );
			}

			// Names are sacred — passed through byte-for-byte (only CSV-level trim).
			$rows[] = array(
				'place'       => $place,
				'first_name'  => $first_name,
				'last_name'   => $last_name,
				'team'        => $team,
				'age'         => $age,
				'bib'         => $bib,
				'finish_time' => $finish_time,
				'status'      => $status,
				'splits'      => array(),
			);
		}

		if ( array() === $rows && array() === $errors ) {
			$errors[] = 'Файлът не съдържа нито един ред с данни.';
		}

		return compact( 'rows', 'warnings', 'errors', 'delimiter', 'had_header' );
	}

	/**
	 * Build (and thereby validate) the result set from normalized rows.
	 *
	 * @param list<array<string,mixed>> $rows
	 */
	private static function build_set( array $rows ): TSR_Result_Set {
		return TSR_Result_Set::from_array( array(
			'schema_version' => TSR_Schema::VERSION,
			'split_labels'   => array(),
			'rows'           => $rows,
		) );
	}

	/**
	 * Compute the target slug following the site's hub pattern.
	 *
	 * @return array{slug:string, base:string, existing_id:int, is_hub_head:bool}
	 */
	private static function resolve_slug( string $event_name, string $category_label ): array {
		$base      = sanitize_title( $event_name ) . '-results';
		$base_post = get_page_by_path( $base, OBJECT, TSR_Post_Types::POST_TYPE );

		if ( null === $base_post ) {
			// First category of this event → bare hub slug.
			$slug = $base;
		} else {
			$slug = sanitize_title( $base . '-' . sanitize_title( $category_label ) );
		}

		$existing = get_page_by_path( $slug, OBJECT, TSR_Post_Types::POST_TYPE );

		return array(
			'slug'        => $slug,
			'base'        => $base,
			'existing_id' => $existing instanceof WP_Post ? $existing->ID : 0,
			'is_hub_head' => null === $base_post,
		);
	}

	private static function render_preview( array $fields, array $parsed, array $slug_info, array $errors ): void {
		$n_rows = count( $parsed['rows'] );

		self::notice(
			array() === $errors ? 'success' : 'error',
			sprintf(
				'%d реда разчетени (разделител "%s"%s). %d предупреждения, %d грешки.',
				$n_rows,
				$parsed['delimiter'],
				$parsed['had_header'] ? ', заглавен ред пропуснат' : '',
				count( $parsed['warnings'] ),
				count( $errors )
			)
		);

		foreach ( $errors as $e ) {
			self::notice( 'error', $e );
		}
		foreach ( $parsed['warnings'] as $w ) {
			self::notice( 'warning', $w );
		}

		echo '<h2>Преглед</h2>';
		echo '<p><strong>' . esc_html( $fields['event_name'] ) . ' — ' . esc_html( $fields['category_label'] ) . '</strong>';
		echo ' · сезон ' . esc_html( (string) $fields['season'] );
		echo ' · ' . esc_html( (string) $fields['distance_km'] ) . ' км (' . esc_html( $fields['distance_cat'] ) . ')</p>';
		echo '<p>Слъг: <code>' . esc_html( $slug_info['slug'] ) . '</code>';
		if ( $slug_info['is_hub_head'] ) {
			echo ' — първа категория за събитието (hub слъг)';
		}
		echo '</p>';

		if ( $slug_info['existing_id'] > 0 ) {
			self::notice( 'warning', sprintf(
				'Публикация със слъг "%s" вече съществува (ID %d). Изберете дали да я презапишете.',
				$slug_info['slug'],
				$slug_info['existing_id']
			) );
		}

		// First rows as a sanity check.
		echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
		foreach ( array( '#', 'Име', 'Фамилия', 'Отбор', 'Възраст', 'Номер', 'Време', 'Статус' ) as $h ) {
			echo '<th>' . esc_html( $h ) . '</th>';
		}
		echo '</tr></thead><tbody>';
		foreach ( array_slice( $parsed['rows'], 0, self::PREVIEW_ROWS ) as $r ) {
			echo '<tr>';
			echo '<td>' . esc_html( (string) ( $r['place'] ?? '—' ) ) . '</td>';
			echo '<td>' . esc_html( $r['first_name'] ) . '</td>';
			echo '<td>' . esc_html( $r['last_name'] ) . '</td>';
			echo '<td>' . esc_html( $r['team'] ) . '</td>';
			echo '<td>' . esc_html( (string) ( $r['age'] ?? '' ) ) . '</td>';
			echo '<td>' . esc_html( $r['bib'] ) . '</td>';
			echo '<td>' . esc_html( $r['finish_time'] ) . '</td>';
			echo '<td>' . esc_html( $r['status'] ) . '</td>';
			echo '</tr>';
		}
		echo '</tbody></table>';
		if ( $n_rows > self::PREVIEW_ROWS ) {
			echo '<p>… и още ' . esc_html( (string) ( $n_rows - self::PREVIEW_ROWS ) ) . ' реда.</p>';
		}

		if ( array() !== $errors ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) . '">Назад — коригирайте CSV файла и качете отново</a></p>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_CONFIRM );
		if ( $slug_info['existing_id'] > 0 ) {
			echo '<p><label><input type="radio" name="tsr_mode" value="update" checked> Презапиши съществуващата публикация (резултатите и мета се заменят)</label><br>';
			echo '<label><input type="radio" name="tsr_mode" value="create"> Създай нова публикация (слъгът получава суфикс)</label></p>';
		} else {
			echo '<input type="hidden" name="tsr_mode" value="create">';
		}
		submit_button( sprintf( 'Потвърди — запиши %d реда', $n_rows ), 'primary', 'tsr_confirm' );
		echo ' <a class="button" href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) . '">Откажи</a>';
		echo '</form>';
	}

	// ── Step 2: confirm + persist ────────────────────────────────────────────

	private static function handle_confirm(): void {
		check_admin_referer( self::NONCE_CONFIRM );

		$payload = get_transient( self::transient_key() );
		if ( ! is_array( $payload ) ) {
			self::notice( 'error', 'Сесията за качване е изтекла — качете файла отново.' );
			self::render_form();
			return;
		}
		delete_transient( self::transient_key() );

		$fields    = $payload['fields'];
		$slug_info = $payload['slug_info'];
		$mode      = ( $_POST['tsr_mode'] ?? '' ) === 'update' ? 'update' : 'create';

		try {
			$set = self::build_set( $payload['rows'] );
		} catch ( InvalidArgumentException $e ) {
			self::notice( 'error', 'Валидацията се провали: ' . $e->getMessage() );
			self::render_form();
			return;
		}

		// Title follows the importer convention — the " — {category}" suffix is
		// what the hub view and klasiraniya use for labels and gender.
		$post_title = $fields['event_name'] . ' - Results — ' . $fields['category_label'];

		if ( 'update' === $mode && $slug_info['existing_id'] > 0 ) {
			$post_id = $slug_info['existing_id'];
			wp_update_post( array( 'ID' => $post_id, 'post_title' => $post_title ) );
		} else {
			$result = wp_insert_post(
				array(
					'post_type'   => TSR_Post_Types::POST_TYPE,
					'post_title'  => $post_title,
					'post_name'   => $slug_info['slug'], // wp_unique_post_slug suffixes on collision.
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				self::notice( 'error', 'Създаването на публикацията се провали: ' . $result->get_error_message() );
				self::render_form();
				return;
			}
			$post_id = $result;
		}

		try {
			TSR_Repository::save( $post_id, $set );
		} catch ( Exception $e ) {
			self::notice( 'error', 'Записът на резултатите се провали: ' . $e->getMessage() );
			self::render_form();
			return;
		}

		// Same meta as `wp tsr backfill-meta` derives. Event base strips the
		// year tick ("Baba Marta Run'27" → "Baba Marta Run").
		$km_str     = rtrim( rtrim( sprintf( '%.1f', $fields['distance_km'] ), '0' ), '.' );
		$event_base = trim( (string) preg_replace( "/['’]\\s*\\d{2}\$/u", '', $fields['event_name'] ) );
		update_post_meta( $post_id, '_tsr_season', (string) $fields['season'] );
		update_post_meta( $post_id, '_tsr_distance_cat', $fields['distance_cat'] );
		update_post_meta( $post_id, '_tsr_distance_km', $km_str );
		update_post_meta( $post_id, '_tsr_event_base', $event_base );

		$permalink = get_permalink( $post_id );
		self::notice( 'success', sprintf(
			'Записани %d реда в публикация %d (слъг "%s"). <a href="%s">Виж</a> · <a href="%s">Редактирай</a>',
			count( $set->rows() ),
			$post_id,
			get_post_field( 'post_name', $post_id ),
			esc_url( (string) $permalink ),
			esc_url( get_edit_post_link( $post_id, 'url' ) ?? '' )
		) );

		self::render_form();
	}

	// ── Form + helpers ───────────────────────────────────────────────────────

	private static function render_form(): void {
		?>
		<p>CSV колони (в този ред): <code>place, first_name, last_name, team, age, bib, finish_time, status</code>.
		Разделител запетая или точка и запетая; заглавният ред е по избор; кодировка UTF-8 или Windows-1251.</p>
		<form method="post" enctype="multipart/form-data">
			<?php wp_nonce_field( self::NONCE_UPLOAD ); ?>
			<table class="form-table" role="presentation">
				<tr>
					<th scope="row"><label for="tsr_event_name">Събитие</label></th>
					<td><input name="tsr_event_name" id="tsr_event_name" type="text" class="regular-text" placeholder="Baba Marta Run'27" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="tsr_season">Сезон (година)</label></th>
					<td><input name="tsr_season" id="tsr_season" type="number" min="2012" max="2100" value="<?php echo esc_attr( gmdate( 'Y' ) ); ?>" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="tsr_distance_cat">Категория дистанция</label></th>
					<td>
						<select name="tsr_distance_cat" id="tsr_distance_cat">
							<option value="short">short (&lt;8 км)</option>
							<option value="medium">medium (8–13.9 км)</option>
							<option value="long">long (14–20.9 км)</option>
							<option value="bonus">bonus (21+ км)</option>
						</select>
					</td>
				</tr>
				<tr>
					<th scope="row"><label for="tsr_distance_km">Дистанция (км)</label></th>
					<td><input name="tsr_distance_km" id="tsr_distance_km" type="number" step="0.1" min="0.5" max="200" required></td>
				</tr>
				<tr>
					<th scope="row"><label for="tsr_category_label">Етикет на категорията</label></th>
					<td><input name="tsr_category_label" id="tsr_category_label" type="text" class="regular-text" placeholder="16КМ МЪЖЕ" required>
					<p class="description">Показва се като заглавие на секцията в hub изгледа, напр. "16КМ МЪЖЕ".</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="tsr_csv">CSV файл</label></th>
					<td><input name="tsr_csv" id="tsr_csv" type="file" accept=".csv,.txt" required></td>
				</tr>
			</table>
			<?php submit_button( 'Качи и прегледай' ); ?>
		</form>
		<?php
	}

	private static function transient_key(): string {
		return 'tsr_upload_preview_' . get_current_user_id();
	}

	private static function notice( string $type, string $message ): void {
		printf(
			'<div class="notice notice-%s"><p>%s</p></div>',
			esc_attr( $type ),
			wp_kses( $message, array( 'a' => array( 'href' => true ), 'code' => array() ) )
		);
	}
}