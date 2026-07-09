<?php
declare( strict_types=1 );
/**
 * The single rendering path for results tables. Takes a TSR_Result_Set and
 * nothing else — there is no parameter for choosing, hiding or reordering
 * columns, which is what makes the unified layout structural rather than
 * conventional. Themes call tsr_render_results() / the shortcode and get the
 * canonical table.
 *
 * @package trailseries-results
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class TSR_Renderer {

	private function __construct() {}

	public static function table( TSR_Result_Set $set ): string {
		$html = '<div class="tsr-results-wrap"><table class="tsr-results">';

		$html .= '<thead><tr>';
		foreach ( $set->column_labels() as $label ) {
			$html .= '<th scope="col">' . esc_html( $label ) . '</th>';
		}
		$html .= '</tr></thead>';

		$html .= '<tbody>';
		foreach ( $set->rows() as $row ) {
			$html .= '<tr class="tsr-status-' . esc_attr( strtolower( $row->status->value ) ) . '">';
			foreach ( self::cells( $row ) as $class => $value ) {
				$html .= '<td class="tsr-col-' . esc_attr( $class ) . '">' . esc_html( $value ) . '</td>';
			}
			$html .= '</tr>';
		}
		$html .= '</tbody>';

		$html .= '</table></div>';

		return $html;
	}

	/**
	 * Display values in canonical order: exactly TSR_Schema::CORE_COLUMNS,
	 * then one cell per split. Adding a core column requires changing
	 * TSR_Schema — this match() will throw on any key it doesn't know.
	 *
	 * @return array<string, string> class-suffix => display value
	 */
	private static function cells( TSR_Result_Row $row ): array {
		$cells = array();
		foreach ( TSR_Schema::CORE_COLUMNS as $column ) {
			$cells[ $column ] = match ( $column ) {
				'place'       => null === $row->place ? '—' : (string) $row->place,
				'first_name'  => $row->first_name,
				'last_name'   => $row->last_name,
				'team'        => $row->team,
				'age'         => null === $row->age ? '' : (string) $row->age,
				'bib'         => $row->bib,
				'finish_time' => $row->finish_time,
				'status'      => $row->status->label(),
			};
		}
		foreach ( $row->splits as $i => $split ) {
			$cells[ 'split-' . ( $i + 1 ) ] = $split ?? '—';
		}
		return $cells;
	}
}
