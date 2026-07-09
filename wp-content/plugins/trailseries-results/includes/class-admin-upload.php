<?php
declare( strict_types=1 );
/**
 * Admin results upload for race results — no WP-CLI needed.
 *
 * Tools → "TSR — Качване на резултати". Two input modes, auto-detected by
 * file extension, sharing one two-step flow (upload+preview → confirm):
 *
 *  - .csv/.txt — single category: place, first_name, last_name, team, age,
 *    bib, finish_time, status. Comma or semicolon delimited (auto-detected),
 *    optional header row, UTF-8 or Windows-1251 (auto-converted). Category
 *    label / km / distance cat come from the form fields.
 *
 *  - .xlsx — multi-sheet timing-system export: one sheet per category
 *    ("6km M", "12KM F", ...), plus an "All" summary sheet that is skipped.
 *    Parsed with ZipArchive + DOM over the underlying XML (workbook.xml,
 *    sharedStrings.xml, worksheets/*.xml) — no external library. Column
 *    mapping: # → place, First (+Middle) name → first_name, Last name →
 *    last_name, Team → team, Age → age, Number → bib, Time → finish_time
 *    (milliseconds stripped; Excel numeric day-fractions converted). Status
 *    is not present in this format — rows default to FIN (the timing system
 *    only exports finishers). Category label = sheet-name distance + Gender
 *    column ("6km M" → "6КМ МЪЖЕ"); km and distance cat derived per sheet.
 *    One ts_result post is created per category sheet.
 *
 * Every parsed row is validated through TSR_Result_Row/TSR_Result_Set (the
 * same objects the importer uses — no reimplemented validation) and saved
 * via TSR_Repository::save() (round-trip verified). Meta matches the CLI
 * backfill: _tsr_season, _tsr_distance_cat, _tsr_distance_km,
 * _tsr_event_base.
 *
 * Slug scheme matches the migrated content: the FIRST category of an event
 * gets the bare "{event-slug}-results" slug (it becomes the hub post that
 * single-ts_result.php expands); later categories get
 * "{event-slug}-results-{category-slug}". Note sanitize_title() collapses
 * dashes and percent-encodes Cyrillic, same as the imported siblings.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Admin_Upload {

	private const PAGE_SLUG     = 'tsr-upload-results';
	private const NONCE_UPLOAD  = 'tsr_upload_step1';
	private const NONCE_CONFIRM = 'tsr_upload_step2';
	private const MAX_FILE_SIZE = 10 * 1024 * 1024;
	private const PREVIEW_ROWS  = 20;

	private const XLSX_NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

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

		echo '<div class="wrap"><h1>Качване на резултати (CSV / XLSX)</h1>';

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

		$upload = self::validate_upload();
		if ( is_string( $upload ) ) {
			self::notice( 'error', $upload );
			self::render_form();
			return;
		}
		$is_xlsx = 'xlsx' === $upload['ext'];

		$fields = self::read_fields( $is_xlsx );
		if ( is_string( $fields ) ) {
			self::notice( 'error', $fields );
			self::render_form();
			return;
		}

		$skipped = array();
		if ( $is_xlsx ) {
			$book = self::parse_xlsx( $upload['path'] );
			if ( is_string( $book ) ) {
				self::notice( 'error', $book );
				self::render_form();
				return;
			}
			$sections = $book['sections'];
			$skipped  = $book['skipped'];
		} else {
			$parsed   = self::parse_csv( self::read_text( $upload['path'] ) );
			$sections = array(
				array(
					'label'    => $fields['category_label'],
					'km'       => $fields['distance_km'],
					'cat'      => $fields['distance_cat'],
					'rows'     => $parsed['rows'],
					'warnings' => $parsed['warnings'],
					'errors'   => $parsed['errors'],
					'source'   => sprintf(
						'CSV, разделител "%s"%s',
						$parsed['delimiter'],
						$parsed['had_header'] ? ', заглавен ред пропуснат' : ''
					),
				),
			);
		}

		// Final validation pass through the plugin's own value objects.
		foreach ( $sections as &$section ) {
			if ( array() !== $section['errors'] ) {
				continue;
			}
			try {
				self::build_set( $section['rows'] );
			} catch ( InvalidArgumentException $e ) {
				$section['errors'][] = $e->getMessage();
			}
		}
		unset( $section );

		self::resolve_slugs( $fields['event_name'], $sections );

		set_transient(
			self::transient_key(),
			array( 'fields' => $fields, 'sections' => $sections ),
			HOUR_IN_SECONDS
		);

		self::render_preview( $fields, $sections, $skipped );
	}

	/**
	 * Validate form fields. For XLSX uploads the per-category fields
	 * (label / km / distance cat) are derived per sheet and not required.
	 *
	 * @return array{event_name:string, season:int, distance_cat:?string, distance_km:?float, category_label:?string}|string
	 */
	private static function read_fields( bool $is_xlsx ): array|string {
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

		if ( $is_xlsx ) {
			return array(
				'event_name'     => $event_name,
				'season'         => $season,
				'distance_cat'   => null,
				'distance_km'    => null,
				'category_label' => null,
			);
		}

		if ( ! in_array( $distance_cat, array( 'short', 'medium', 'long', 'bonus' ), true ) ) {
			return 'Невалидна категория дистанция.';
		}
		if ( $distance_km <= 0 || $distance_km > 200 ) {
			return 'Невалидна дистанция в километри.';
		}
		if ( '' === $category_label ) {
			return 'Липсва етикет на категорията (напр. "16КМ МЪЖЕ") — задължителен при CSV.';
		}

		return compact( 'event_name', 'season', 'distance_cat', 'distance_km', 'category_label' );
	}

	/**
	 * @return array{path:string, ext:string}|string Upload info, or error message.
	 */
	private static function validate_upload(): array|string {
		$file = $_FILES['tsr_csv'] ?? null;
		if ( ! is_array( $file ) || UPLOAD_ERR_OK !== ( $file['error'] ?? UPLOAD_ERR_NO_FILE ) ) {
			return 'Файлът не беше качен успешно.';
		}
		if ( $file['size'] > self::MAX_FILE_SIZE ) {
			return 'Файлът е над 10 MB.';
		}
		if ( ! is_uploaded_file( $file['tmp_name'] ) ) {
			return 'Невалидно качване.';
		}
		$ext = strtolower( pathinfo( $file['name'], PATHINFO_EXTENSION ) );
		if ( ! in_array( $ext, array( 'csv', 'txt', 'xlsx' ), true ) ) {
			return 'Очаква се .csv, .txt или .xlsx файл.';
		}

		return array( 'path' => $file['tmp_name'], 'ext' => $ext );
	}

	/**
	 * Read a CSV/TXT upload as UTF-8 text: strips the BOM and converts
	 * Windows-1251 exports (common from Excel BG).
	 */
	private static function read_text( string $path ): string {
		$raw = (string) file_get_contents( $path );

		if ( str_starts_with( $raw, "\xEF\xBB\xBF" ) ) {
			$raw = substr( $raw, 3 );
		}
		if ( ! mb_check_encoding( $raw, 'UTF-8' ) ) {
			$raw = mb_convert_encoding( $raw, 'UTF-8', 'Windows-1251' );
		}

		return $raw;
	}

	// ── CSV parsing (single category) ────────────────────────────────────────

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

	// ── XLSX parsing (multi-sheet timing export) ─────────────────────────────

	/**
	 * Parse a timing-system XLSX into per-category sections.
	 *
	 * @return array{sections:list<array<string,mixed>>, skipped:list<string>}|string
	 *         Sections + skip notes, or a fatal error message.
	 */
	private static function parse_xlsx( string $path ): array|string {
		if ( ! class_exists( 'ZipArchive' ) ) {
			return 'PHP разширението zip не е налично на този сървър — качете CSV вместо XLSX.';
		}

		$zip = new ZipArchive();
		if ( true !== $zip->open( $path ) ) {
			return 'Файлът не е валиден XLSX архив.';
		}

		$workbook_xml = $zip->getFromName( 'xl/workbook.xml' );
		$rels_xml     = $zip->getFromName( 'xl/_rels/workbook.xml.rels' );
		if ( false === $workbook_xml || false === $rels_xml ) {
			$zip->close();
			return 'Файлът не съдържа валидна XLSX структура (workbook.xml липсва).';
		}

		// Relationship Id → worksheet path inside the archive.
		$rels = array();
		$rdoc = new DOMDocument();
		if ( $rdoc->loadXML( $rels_xml ) ) {
			foreach ( $rdoc->getElementsByTagName( 'Relationship' ) as $rel ) {
				$target = $rel->getAttribute( 'Target' );
				if ( ! str_starts_with( $target, 'xl/' ) ) {
					$target = 'xl/' . ltrim( $target, '/' );
				}
				$rels[ $rel->getAttribute( 'Id' ) ] = $target;
			}
		}

		// Sheets in workbook order.
		$sheets = array();
		$wdoc   = new DOMDocument();
		if ( $wdoc->loadXML( $workbook_xml ) ) {
			foreach ( $wdoc->getElementsByTagName( 'sheet' ) as $sh ) {
				$rid    = $sh->getAttributeNS( self::XLSX_NS_REL, 'id' );
				$target = $rels[ $rid ] ?? '';
				if ( '' !== $target ) {
					$sheets[] = array( 'name' => $sh->getAttribute( 'name' ), 'target' => $target );
				}
			}
		}
		if ( array() === $sheets ) {
			$zip->close();
			return 'Не бяха намерени листове в XLSX файла.';
		}

		// Shared string table (cell type "s" points into it).
		$strings = array();
		$ss_xml  = $zip->getFromName( 'xl/sharedStrings.xml' );
		if ( false !== $ss_xml ) {
			$sdoc = new DOMDocument();
			if ( $sdoc->loadXML( $ss_xml ) ) {
				foreach ( $sdoc->getElementsByTagName( 'si' ) as $si ) {
					$text = '';
					foreach ( $si->getElementsByTagName( 't' ) as $t ) {
						$text .= $t->textContent;
					}
					$strings[] = $text;
				}
			}
		}

		$sections = array();
		$skipped  = array();

		foreach ( $sheets as $sheet ) {
			$name = $sheet['name'];

			// Summary sheets are duplicates of the per-category data.
			if ( preg_match( '/^\s*(all|summary|всички|общо)\s*$/iu', $name ) ) {
				$skipped[] = sprintf( 'Лист "%s": обобщен лист — пропуснат.', $name );
				continue;
			}

			$sheet_xml = $zip->getFromName( $sheet['target'] );
			if ( false === $sheet_xml ) {
				$skipped[] = sprintf( 'Лист "%s": не може да бъде прочетен.', $name );
				continue;
			}

			$section = self::parse_sheet( $name, $sheet_xml, $strings );
			if ( is_string( $section ) ) {
				$skipped[] = $section;
				continue;
			}
			$sections[] = $section;
		}

		$zip->close();

		if ( array() === $sections ) {
			return 'Нито един лист не изглежда като категориен лист с резултати. Пропуснати: ' . implode( ' ', $skipped );
		}

		return compact( 'sections', 'skipped' );
	}

	/**
	 * Parse one category worksheet into a section, or return a skip-note
	 * string when the sheet does not look like a category sheet.
	 *
	 * @param list<string> $strings Shared string table.
	 * @return array<string,mixed>|string
	 */
	private static function parse_sheet( string $name, string $xml, array $strings ): array|string {
		// Distance comes from the sheet name ("17km M", "12KM F").
		if ( ! preg_match( '/(\d+(?:[.,]\d+)?)\s*(?:km|км)/iu', $name, $m ) ) {
			return sprintf( 'Лист "%s": името не съдържа дистанция (напр. "6km M") — пропуснат.', $name );
		}
		$km = (float) str_replace( ',', '.', $m[1] );

		$grid = self::read_sheet_grid( $xml, $strings );
		if ( count( $grid ) < 2 ) {
			return sprintf( 'Лист "%s": няма редове с данни — пропуснат.', $name );
		}

		// Header row → column map. Exact matches only, so "Lap: finish" can
		// never be mistaken for the "Time" column.
		$header = array_shift( $grid );
		$map    = array();
		foreach ( $header as $idx => $h ) {
			$key = mb_strtolower( trim( $h ), 'UTF-8' );
			if ( in_array( $key, array( '#', 'no', 'no.', 'place', 'място', 'позиция' ), true ) ) {
				$map['place'] = $idx;
			} elseif ( in_array( $key, array( 'first name', 'име' ), true ) ) {
				$map['first'] = $idx;
			} elseif ( 'middle name' === $key ) {
				$map['middle'] = $idx;
			} elseif ( in_array( $key, array( 'last name', 'фамилия' ), true ) ) {
				$map['last'] = $idx;
			} elseif ( in_array( $key, array( 'team', 'отбор', 'клуб' ), true ) ) {
				$map['team'] = $idx;
			} elseif ( in_array( $key, array( 'age', 'възраст' ), true ) ) {
				$map['age'] = $idx;
			} elseif ( in_array( $key, array( 'number', 'bib', 'номер', 'стартов номер' ), true ) ) {
				$map['bib'] = $idx;
			} elseif ( in_array( $key, array( 'time', 'finish time', 'време' ), true ) ) {
				$map['time'] = $idx;
			} elseif ( in_array( $key, array( 'gender', 'пол' ), true ) ) {
				$map['gender'] = $idx;
			}
		}
		foreach ( array( 'place', 'first', 'last', 'time' ) as $required ) {
			if ( ! isset( $map[ $required ] ) ) {
				return sprintf( 'Лист "%s": липсва колона "%s" — не е категориен лист, пропуснат.', $name, $required );
			}
		}

		$cell = static fn( array $row, string $col ): string => isset( $map[ $col ] ) ? trim( $row[ $map[ $col ] ] ?? '' ) : '';

		// Gender: majority of the Gender column, falling back to the sheet
		// name's trailing token ("6km M" / "12КМ Ж").
		$males   = 0;
		$females = 0;
		foreach ( $grid as $row ) {
			$g = mb_strtolower( $cell( $row, 'gender' ), 'UTF-8' );
			if ( in_array( $g, array( 'm', 'male', 'м', 'мъж', 'мъже' ), true ) ) {
				++$males;
			} elseif ( in_array( $g, array( 'f', 'female', 'ж', 'жена', 'жени' ), true ) ) {
				++$females;
			}
		}
		if ( $males > $females ) {
			$gender_label = 'МЪЖЕ';
		} elseif ( $females > $males ) {
			$gender_label = 'ЖЕНИ';
		} elseif ( preg_match( '/\b(m|м)\s*$/iu', $name ) ) {
			$gender_label = 'МЪЖЕ';
		} elseif ( preg_match( '/\b(f|ж)\s*$/iu', $name ) ) {
			$gender_label = 'ЖЕНИ';
		} else {
			return sprintf( 'Лист "%s": полът не може да бъде определен (нито от колона Gender, нито от името) — пропуснат.', $name );
		}

		$km_label = rtrim( rtrim( sprintf( '%.1f', $km ), '0' ), '.' );
		$label    = sprintf( '%sКМ %s', $km_label, $gender_label );

		$rows     = array();
		$warnings = array();
		$errors   = array();

		foreach ( $grid as $i => $row ) {
			$row_no = $i + 2; // 1-based, after the header row.

			$place_raw = $cell( $row, 'place' );
			$first     = $cell( $row, 'first' );
			$middle    = $cell( $row, 'middle' );
			$last      = $cell( $row, 'last' );
			$team      = $cell( $row, 'team' );
			$age_raw   = $cell( $row, 'age' );
			$bib       = $cell( $row, 'bib' );
			$time_raw  = $cell( $row, 'time' );

			if ( '' === $place_raw && '' === $first && '' === $last ) {
				continue; // fully empty row
			}

			if ( '' !== $middle ) {
				$first = trim( $first . ' ' . $middle );
			}

			// Excel numerics may carry a fractional tail ("1.0", "34.0").
			$place_raw = (string) preg_replace( '/\.0+$/', '', $place_raw );
			$age_raw   = (string) preg_replace( '/\.0+$/', '', $age_raw );
			$bib       = (string) preg_replace( '/\.0+$/', '', $bib );

			$place = ctype_digit( $place_raw ) ? (int) $place_raw : null;
			if ( null === $place ) {
				$errors[] = sprintf( 'Лист "%s", ред %d: невалидно място "%s".', $name, $row_no, $place_raw );
				continue;
			}

			$age = ctype_digit( $age_raw ) ? (int) $age_raw : null;
			if ( null === $age && '' !== $age_raw ) {
				$warnings[] = sprintf( 'Лист "%s", ред %d: невалидна възраст "%s" — записана като празна.', $name, $row_no, $age_raw );
			}

			$finish_time = self::normalize_time( $time_raw );
			if ( '' === $finish_time || ! TSR_Schema::is_valid_time( $finish_time ) ) {
				$errors[] = sprintf( 'Лист "%s", ред %d: невалидно време "%s".', $name, $row_no, $time_raw );
				continue;
			}

			if ( '' === $first && '' === $last ) {
				$errors[] = sprintf( 'Лист "%s", ред %d: липсва име.', $name, $row_no );
				continue;
			}
			if ( '' === $bib ) {
				$warnings[] = sprintf( 'Лист "%s", ред %d: липсва стартов номер.', $name, $row_no );
			}

			// This timing format has no status column and only exports
			// finishers — every valid row is FIN.
			$rows[] = array(
				'place'       => $place,
				'first_name'  => $first,
				'last_name'   => $last,
				'team'        => $team,
				'age'         => $age,
				'bib'         => $bib,
				'finish_time' => $finish_time,
				'status'      => 'FIN',
				'splits'      => array(),
			);
		}

		if ( array() === $rows && array() === $errors ) {
			return sprintf( 'Лист "%s": няма нито един ред с данни — пропуснат.', $name );
		}

		return array(
			'label'    => $label,
			'km'       => $km,
			'cat'      => self::km_to_cat( $km ),
			'rows'     => $rows,
			'warnings' => $warnings,
			'errors'   => $errors,
			'source'   => sprintf( 'лист "%s"', $name ),
		);
	}

	/**
	 * Extract a worksheet's cell grid: one array per row, keyed by 0-based
	 * column index (sparse cells resolved via the r="C5" reference).
	 *
	 * @param list<string> $strings Shared string table.
	 * @return list<array<int,string>>
	 */
	private static function read_sheet_grid( string $xml, array $strings ): array {
		$doc = new DOMDocument();
		if ( ! $doc->loadXML( $xml ) ) {
			return array();
		}

		$grid = array();
		foreach ( $doc->getElementsByTagName( 'row' ) as $row_el ) {
			$cells    = array();
			$next_col = 0;
			foreach ( $row_el->getElementsByTagName( 'c' ) as $c ) {
				$ref      = $c->getAttribute( 'r' );
				$col      = '' !== $ref ? self::col_index( (string) preg_replace( '/\d+/', '', $ref ) ) : $next_col;
				$next_col = $col + 1;

				$type = $c->getAttribute( 't' );
				if ( 'inlineStr' === $type ) {
					$val = '';
					foreach ( $c->getElementsByTagName( 't' ) as $t ) {
						$val .= $t->textContent;
					}
				} else {
					$v   = $c->getElementsByTagName( 'v' )->item( 0 );
					$val = null !== $v ? $v->textContent : '';
					if ( 's' === $type ) {
						$val = $strings[ (int) $val ] ?? '';
					}
				}
				$cells[ $col ] = trim( $val );
			}
			if ( array() !== array_filter( $cells, static fn( string $v ): bool => '' !== $v ) ) {
				$grid[] = $cells;
			}
		}

		return $grid;
	}

	/**
	 * Column letters → 0-based index ("A" → 0, "Z" → 25, "AA" → 26).
	 */
	private static function col_index( string $letters ): int {
		$n = 0;
		foreach ( str_split( strtoupper( $letters ) ) as $ch ) {
			$n = $n * 26 + ( ord( $ch ) - 64 );
		}
		return max( 0, $n - 1 );
	}

	/**
	 * Normalize a timing-export time value to the canonical Ч:ММ:СС form:
	 * "01:21:46.397" → "01:21:46"; Excel day-fraction numerics converted;
	 * bare MM:SS gets an hour prefix. Unrecognized input is returned as-is
	 * so the caller's TSR_Schema::is_valid_time() check rejects it.
	 */
	private static function normalize_time( string $raw ): string {
		$raw = trim( $raw );
		if ( '' === $raw ) {
			return '';
		}
		if ( is_numeric( $raw ) && ! str_contains( $raw, ':' ) ) {
			$secs = (int) round( (float) $raw * 86400 );
			return sprintf( '%d:%02d:%02d', intdiv( $secs, 3600 ), intdiv( $secs % 3600, 60 ), $secs % 60 );
		}
		$raw = (string) preg_replace( '/[.,]\d+\s*$/', '', $raw ); // strip milliseconds
		if ( preg_match( '/^\d{1,3}:\d{2}:\d{2}$/', $raw ) ) {
			return $raw;
		}
		if ( preg_match( '/^\d{1,2}:\d{2}$/', $raw ) ) {
			return '0:' . $raw; // MM:SS
		}
		return $raw;
	}

	/**
	 * Same distance→category boundaries as the CLI backfill:
	 * <8 short · 8–13.9 medium · 14–20.9 long · 21+ bonus.
	 */
	private static function km_to_cat( float $km ): string {
		if ( $km < 8 ) {
			return 'short';
		}
		if ( $km < 14 ) {
			return 'medium';
		}
		if ( $km < 21 ) {
			return 'long';
		}
		return 'bonus';
	}

	// ── Shared: validation, slugs, preview ───────────────────────────────────

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
	 * Assign a slug to every section following the site's hub pattern: the
	 * first section of a NEW event gets the bare "{event-slug}-results" hub
	 * slug; every other section gets "-{category-slug}" appended. Writes
	 * 'slug', 'existing_id' and 'is_hub_head' into each section.
	 *
	 * @param list<array<string,mixed>> $sections Modified in place.
	 */
	private static function resolve_slugs( string $event_name, array &$sections ): void {
		$base       = sanitize_title( $event_name ) . '-results';
		$bare_taken = null !== get_page_by_path( $base, OBJECT, TSR_Post_Types::POST_TYPE );

		foreach ( $sections as &$section ) {
			if ( ! $bare_taken ) {
				$slug                   = $base;
				$section['is_hub_head'] = true;
				$bare_taken             = true;
			} else {
				$slug                   = sanitize_title( $base . '-' . sanitize_title( $section['label'] ) );
				$section['is_hub_head'] = false;
			}
			$existing               = get_page_by_path( $slug, OBJECT, TSR_Post_Types::POST_TYPE );
			$section['slug']        = $slug;
			$section['existing_id'] = $existing instanceof WP_Post ? $existing->ID : 0;
		}
		unset( $section );
	}

	/**
	 * @param list<array<string,mixed>> $sections
	 * @param list<string>              $skipped
	 */
	private static function render_preview( array $fields, array $sections, array $skipped ): void {
		$total_rows   = array_sum( array_map( static fn( array $s ): int => count( $s['rows'] ), $sections ) );
		$total_warn   = array_sum( array_map( static fn( array $s ): int => count( $s['warnings'] ), $sections ) );
		$total_errors = array_sum( array_map( static fn( array $s ): int => count( $s['errors'] ), $sections ) );
		$has_existing = array() !== array_filter( $sections, static fn( array $s ): bool => $s['existing_id'] > 0 );

		self::notice(
			0 === $total_errors ? 'success' : 'error',
			sprintf(
				'%d категории, %d реда разчетени. %d предупреждения, %d грешки.',
				count( $sections ),
				$total_rows,
				$total_warn,
				$total_errors
			)
		);
		foreach ( $skipped as $note ) {
			self::notice( 'info', $note );
		}

		echo '<h2>Преглед</h2>';
		echo '<p><strong>' . esc_html( $fields['event_name'] ) . '</strong> · сезон ' . esc_html( (string) $fields['season'] ) . '</p>';

		foreach ( $sections as $section ) {
			echo '<h3>' . esc_html( $section['label'] ) . ' — ' . esc_html( (string) count( $section['rows'] ) ) . ' реда';
			echo ' <small>(' . esc_html( $section['source'] ) . ' · ' . esc_html( (string) $section['km'] ) . ' км · ' . esc_html( (string) $section['cat'] ) . ')</small></h3>';

			echo '<p>Слъг: <code>' . esc_html( $section['slug'] ) . '</code>';
			if ( $section['is_hub_head'] ) {
				echo ' — първа категория за събитието (hub слъг)';
			}
			echo '</p>';

			if ( $section['existing_id'] > 0 ) {
				self::notice( 'warning', sprintf(
					'Публикация със слъг "%s" вече съществува (ID %d).',
					$section['slug'],
					$section['existing_id']
				) );
			}
			foreach ( $section['errors'] as $e ) {
				self::notice( 'error', $e );
			}
			foreach ( $section['warnings'] as $w ) {
				self::notice( 'warning', $w );
			}

			echo '<table class="widefat striped" style="max-width:900px"><thead><tr>';
			foreach ( array( '#', 'Име', 'Фамилия', 'Отбор', 'Възраст', 'Номер', 'Време', 'Статус' ) as $h ) {
				echo '<th>' . esc_html( $h ) . '</th>';
			}
			echo '</tr></thead><tbody>';
			foreach ( array_slice( $section['rows'], 0, self::PREVIEW_ROWS ) as $r ) {
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
			if ( count( $section['rows'] ) > self::PREVIEW_ROWS ) {
				echo '<p>… и още ' . esc_html( (string) ( count( $section['rows'] ) - self::PREVIEW_ROWS ) ) . ' реда.</p>';
			}
		}

		if ( $total_errors > 0 ) {
			echo '<p><a class="button" href="' . esc_url( admin_url( 'tools.php?page=' . self::PAGE_SLUG ) ) . '">Назад — коригирайте файла и качете отново</a></p>';
			return;
		}

		echo '<form method="post">';
		wp_nonce_field( self::NONCE_CONFIRM );
		if ( $has_existing ) {
			echo '<p><label><input type="radio" name="tsr_mode" value="update" checked> Презапиши съществуващите публикации (резултатите и мета се заменят)</label><br>';
			echo '<label><input type="radio" name="tsr_mode" value="create"> Създай нови публикации (слъговете получават суфикс)</label></p>';
		} else {
			echo '<input type="hidden" name="tsr_mode" value="create">';
		}
		submit_button(
			sprintf( 'Потвърди — запиши %d категории (%d реда)', count( $sections ), $total_rows ),
			'primary',
			'tsr_confirm'
		);
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

		$fields   = $payload['fields'];
		$sections = $payload['sections'];
		$mode     = ( $_POST['tsr_mode'] ?? '' ) === 'update' ? 'update' : 'create';

		// Same event-base derivation as the CLI backfill: strip the year tick
		// ("Baba Marta Run'27" → "Baba Marta Run").
		$event_base = trim( (string) preg_replace( "/['’]\\s*\\d{2}\$/u", '', $fields['event_name'] ) );

		foreach ( $sections as $section ) {
			try {
				$set = self::build_set( $section['rows'] );
			} catch ( InvalidArgumentException $e ) {
				self::notice( 'error', sprintf( '%s: валидацията се провали — %s', $section['label'], $e->getMessage() ) );
				continue;
			}

			// Title follows the importer convention — the " — {category}" suffix
			// is what the hub view and klasiraniya use for labels and gender.
			$post_title = $fields['event_name'] . ' - Results — ' . $section['label'];

			if ( 'update' === $mode && $section['existing_id'] > 0 ) {
				$post_id = (int) $section['existing_id'];
				wp_update_post( array( 'ID' => $post_id, 'post_title' => $post_title ) );
			} else {
				$result = wp_insert_post(
					array(
						'post_type'   => TSR_Post_Types::POST_TYPE,
						'post_title'  => $post_title,
						'post_name'   => $section['slug'], // wp_unique_post_slug suffixes on collision.
						'post_status' => 'publish',
					),
					true
				);
				if ( is_wp_error( $result ) ) {
					self::notice( 'error', sprintf( '%s: създаването на публикацията се провали — %s', $section['label'], $result->get_error_message() ) );
					continue;
				}
				$post_id = $result;
			}

			try {
				TSR_Repository::save( $post_id, $set );
			} catch ( Exception $e ) {
				self::notice( 'error', sprintf( '%s: записът на резултатите се провали — %s', $section['label'], $e->getMessage() ) );
				continue;
			}

			$km_str = rtrim( rtrim( sprintf( '%.1f', (float) $section['km'] ), '0' ), '.' );
			update_post_meta( $post_id, '_tsr_season', (string) $fields['season'] );
			update_post_meta( $post_id, '_tsr_distance_cat', (string) $section['cat'] );
			update_post_meta( $post_id, '_tsr_distance_km', $km_str );
			update_post_meta( $post_id, '_tsr_event_base', $event_base );

			self::notice( 'success', sprintf(
				'%s: записани %d реда в публикация %d (слъг "%s"). <a href="%s">Виж</a> · <a href="%s">Редактирай</a>',
				$section['label'],
				count( $set->rows() ),
				$post_id,
				get_post_field( 'post_name', $post_id ),
				esc_url( (string) get_permalink( $post_id ) ),
				esc_url( get_edit_post_link( $post_id, 'url' ) ?? '' )
			) );
		}

		self::render_form();
	}

	// ── Form + helpers ───────────────────────────────────────────────────────

	private static function render_form(): void {
		?>
		<p><strong>CSV</strong> — една категория; колони в този ред:
		<code>place, first_name, last_name, team, age, bib, finish_time, status</code>.
		Разделител запетая или точка и запетая; заглавният ред е по избор; кодировка UTF-8 или Windows-1251.</p>
		<p><strong>XLSX</strong> — многолистов експорт от тайминг система: по един лист на категория
		("6km M", "12KM F", …); обобщеният лист "All" се пропуска автоматично. Категорията, дистанцията
		и полът се извличат от името на листа и колоната Gender — полетата по-долу за категория/дистанция
		се попълват само при CSV.</p>
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
					<th scope="row"><label for="tsr_distance_cat">Категория дистанция <small>(само CSV)</small></label></th>
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
					<th scope="row"><label for="tsr_distance_km">Дистанция (км) <small>(само CSV)</small></label></th>
					<td><input name="tsr_distance_km" id="tsr_distance_km" type="number" step="0.1" min="0.5" max="200"></td>
				</tr>
				<tr>
					<th scope="row"><label for="tsr_category_label">Етикет на категорията <small>(само CSV)</small></label></th>
					<td><input name="tsr_category_label" id="tsr_category_label" type="text" class="regular-text" placeholder="16КМ МЪЖЕ">
					<p class="description">Показва се като заглавие на секцията в hub изгледа, напр. "16КМ МЪЖЕ". При XLSX се извлича автоматично от листовете.</p></td>
				</tr>
				<tr>
					<th scope="row"><label for="tsr_csv">CSV / XLSX файл</label></th>
					<td><input name="tsr_csv" id="tsr_csv" type="file" accept=".csv,.txt,.xlsx" required></td>
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
